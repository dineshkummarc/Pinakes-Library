<?php
declare(strict_types=1);

/**
 * Pickup deadline, said up front and reminded before it passes.
 *
 *   A. "Loan approved" (a loan that starts later) carries {{giorni_ritiro}} and
 *      {{scadenza_ritiro}}: the deadline the start day will set (start + pickup
 *      days, never past the end of the loan). It used to say nothing about it.
 *   B. Pickup reminder (loans.pickup_reminder_days before the deadline):
 *      only loans awaiting pickup, in the window, whose pickup opened before
 *      today, with an email; never twice for the same deadline; a failed send
 *      re-arms it; a new deadline re-arms it; 0 turns it off.
 *   C. The staff gets an in-app notice when a pickup expires.
 *   D. Shipped texts: the reminder template exists in the five locales, the
 *      new approval text is in the installer seeds only (existing installs
 *      keep the row they have), the column is in schema.sql and self-heals.
 *
 * Run: php tests/pickup-reminder.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\DateHelper;
use App\Support\NotificationService;
use App\Support\SettingsMailTemplates;

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

// ── D. Shipped texts (no DB) ────────────────────────────────────────────────
echo "D. shipped texts\n";
foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'] as $locale) {
    $reminder = SettingsMailTemplates::get('loan_pickup_reminder', $locale);
    $approved = SettingsMailTemplates::get('loan_approved', $locale);
    $check(is_array($reminder) && str_contains((string) $reminder['body'], '{{scadenza_ritiro}}')
        && str_contains((string) $reminder['body'], '{{pickup_instructions}}'), "{$locale}: pickup reminder template shipped");
    $check(is_array($approved) && str_contains((string) $approved['body'], '{{giorni_ritiro}}')
        && str_contains((string) $approved['body'], '{{scadenza_ritiro}}'), "{$locale}: loan approved names the pickup days and deadline");
    $seed = (string) file_get_contents("{$root}/installer/database/data_{$locale}.sql");
    $check(preg_match("/`email_templates` VALUES \\(28,'loan_pickup_reminder','{$locale}'/", $seed) === 1, "{$locale}: seed row 28 for the reminder");
    $check(preg_match("/VALUES \\(3,'loan_approved','{$locale}'[^\\n]*\\{\\{giorni_ritiro\\}\\}/", $seed) === 1, "{$locale}: new installs get the new approval text");
}
$check(str_contains((string) file_get_contents("{$root}/installer/database/schema.sql"), '`pickup_reminder_sent_for` date DEFAULT NULL'), 'fresh install has prestiti.pickup_reminder_sent_for');
$check(isset(SettingsMailTemplates::placeholderDescriptions()['giorni_ritiro']), '{{giorni_ritiro}} is documented in the template editor');

// ── DB ─────────────────────────────────────────────────────────────────────
$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(null, getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    $db->set_charset('utf8mb4');
    DateHelper::synchronizeDatabaseSession($db);
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — the pickup reminder suite is mandatory: {$e->getMessage()}\n");
    exit(1);
}

// Deterministic transport: every send fails the same way on every host
// (sendWithRetry short-circuits on the cached SMTP probe).
(new ReflectionProperty(\App\Support\Mailer::class, 'smtpReachable'))->setValue(null, false);

/** Records who a send was attempted for and every date formatted for it. */
final class PickupProbeNotificationService extends NotificationService
{
    /** @var list<string> */
    public array $recipients = [];
    /** @var list<string> */
    public array $dates = [];

    public function resolveRecipientLocale(string $email): string
    {
        $this->recipients[] = $email;
        return 'it_IT';
    }

    public function formatEmailDate(string $dateString, bool $includeTime = false, ?string $locale = null): string
    {
        $this->dates[] = substr($dateString, 0, 10);
        return $dateString;
    }
}

$run = bin2hex(random_bytes(5));
$title = "ZZ_PICKREM_{$run}";
$bookId = 0;
$userIds = [];
$settingsBackup = [];
foreach (['pickup_expiry_days', 'pickup_reminder_days'] as $key) {
    $r = $db->query("SELECT setting_value FROM system_settings WHERE category = 'loans' AND setting_key = '{$key}'");
    $row = $r ? $r->fetch_row() : null;
    $settingsBackup[$key] = $row ? (string) $row[0] : null;
}
$setSetting = static function (string $key, string $value) use ($db): void {
    $stmt = $db->prepare("INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('loans', ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
};
$cleanup = static function () use ($db, &$bookId, &$userIds, $settingsBackup, $run): void {
    try {
        if ($bookId > 0) {
            $db->query("DELETE FROM admin_notifications WHERE related_id IN (SELECT id FROM prestiti WHERE libro_id = {$bookId})");
            $db->query("DELETE FROM prestiti WHERE libro_id = {$bookId}");
            $db->query("DELETE FROM copie WHERE libro_id = {$bookId}");
            $db->query("DELETE FROM libri WHERE id = {$bookId}");
        }
        foreach ($userIds as $id) {
            $db->query('DELETE FROM utenti WHERE id = ' . (int) $id);
        }
        foreach ($settingsBackup as $key => $value) {
            $value === null
                ? $db->query("DELETE FROM system_settings WHERE category = 'loans' AND setting_key = '{$key}'")
                : $db->query("UPDATE system_settings SET setting_value = '" . $db->real_escape_string($value) . "' WHERE category = 'loans' AND setting_key = '{$key}'");
        }
        $db->query("DELETE FROM email_delivery_outbox WHERE recipient_email LIKE 'zz-pickrem-{$run}%'");
    } catch (Throwable) {
    }
};
register_shutdown_function($cleanup);

$today = DateHelper::today();
$day = static fn(int $offset): string => (new DateTimeImmutable($today))->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');

$setSetting('pickup_expiry_days', '7');
$setSetting('pickup_reminder_days', '2');

$stmt = $db->prepare("INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato) VALUES (?, 6, 0, 'prenotato')");
$stmt->bind_param('s', $title);
$stmt->execute();
$bookId = (int) $db->insert_id;
$stmt->close();

$mkUser = static function (string $tag, string $email) use ($db, $run, &$userIds): int {
    $card = strtoupper("ZZPR{$tag}{$run}");
    $hash = password_hash('PickupReminder!1', PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata) VALUES (?, 'Pick', ?, ?, ?, 'standard', 'attivo', 1)");
    $stmt->bind_param('ssss', $card, $tag, $email, $hash);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    $userIds[] = $id;
    return $id;
};
$mkLoan = static function (int $userId, string $state, string $start, string $end, ?string $deadline) use ($db, $bookId, $run): int {
    static $n = 0;
    $n++;
    $inventory = "ZZPR-{$run}-{$n}";
    $copyState = $state === 'prenotato' || $state === 'da_ritirare' ? 'prenotato' : 'prestato';
    $stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, ?)");
    $stmt->bind_param('iss', $bookId, $inventory, $copyState);
    $stmt->execute();
    $copyId = (int) $db->insert_id;
    $stmt->close();
    $stmt = $db->prepare("INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo, pickup_deadline) VALUES (?, ?, ?, ?, ?, ?, 1, ?)");
    $stmt->bind_param('iiissss', $bookId, $copyId, $userId, $start, $end, $state, $deadline);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

$probe = new PickupProbeNotificationService($db);
$check($probe->addNotificationColumns(), 'notification columns ensured (self-heal)');
$col = $db->query("SHOW COLUMNS FROM prestiti LIKE 'pickup_reminder_sent_for'");
$check($col !== false && $col->num_rows === 1, 'prestiti.pickup_reminder_sent_for exists after the self-heal');

// ── A. Loan approved names the deadline the start day will set ──────────────
echo "A. loan approved\n";
$emailA = "zz-pickrem-{$run}-a@test.local";
$userA = $mkUser('A', $emailA);
$startA = $day(5);
$loanA = $mkLoan($userA, 'prenotato', $startA, $day(35), null);
$probe->dates = [];
$probe->sendLoanApprovedNotification($loanA);
$check(in_array($day(12), $probe->dates, true), 'pickup deadline = start + 7 days (' . $day(12) . ')');
$shortLoan = $mkLoan($userA, 'prenotato', $startA, $day(8), null);
$probe->dates = [];
$probe->sendLoanApprovedNotification($shortLoan);
$check(in_array($day(8), $probe->dates, true) && !in_array($day(12), $probe->dates, true), 'a loan shorter than the pickup window caps the deadline at its end');

// ── B. Pickup reminder ──────────────────────────────────────────────────────
echo "B. pickup reminder\n";
$emailDue = "zz-pickrem-{$run}-due@test.local";
$emailToday = "zz-pickrem-{$run}-today@test.local";
$emailFar = "zz-pickrem-{$run}-far@test.local";
$emailJustOpened = "zz-pickrem-{$run}-new@test.local";
$emailPast = "zz-pickrem-{$run}-past@test.local";
$emailPicked = "zz-pickrem-{$run}-picked@test.local";
// Window opened 5 days ago, deadline in 2 days → due.
$due = $mkLoan($mkUser('DUE', $emailDue), 'da_ritirare', $day(-5), $day(25), $day(2));
$db->query("UPDATE prestiti SET pickup_notification_sent = 1, pickup_notification_last_attempt_at = UTC_TIMESTAMP() - INTERVAL 5 DAY WHERE id = {$due}");
// Deadline today → still due (last valid day).
$mkLoan($mkUser('TOD', $emailToday), 'da_ritirare', $day(-7), $day(23), $day(0));
// Deadline in 5 days → not yet.
$mkLoan($mkUser('FAR', $emailFar), 'da_ritirare', $day(-2), $day(28), $day(5));
// Pickup window opened today (deadline capped by a short loan): the "ready"
// email went out today, a reminder the same day would be noise.
$justOpened = $mkLoan($mkUser('NEW', $emailJustOpened), 'da_ritirare', $day(0), $day(1), $day(1));
$db->query("UPDATE prestiti SET pickup_notification_sent = 1, pickup_notification_last_attempt_at = UTC_TIMESTAMP() WHERE id = {$justOpened}");
// "Ready" sent yesterday for the due loan below.

// Deadline already passed → the expiry sweep's job, not a reminder.
$mkLoan($mkUser('PAST', $emailPast), 'da_ritirare', $day(-9), $day(21), $day(-1));
// Already picked up.
$mkLoan($mkUser('PICK', $emailPicked), 'in_corso', $day(-3), $day(27), null);
// No email address: never attempted.
$noMail = $mkLoan($mkUser('NOMAIL', ''), 'da_ritirare', $day(-5), $day(25), $day(2));

$probe->recipients = [];
$sent = $probe->sendPickupReminders();
$attempted = array_values(array_unique(array_filter($probe->recipients, static fn(string $e): bool => str_starts_with($e, "zz-pickrem-{$run}"))));
sort($attempted);
$expected = [$emailDue, $emailToday];
sort($expected);
$check($attempted === $expected, 'reminders attempted exactly for the due loans: ' . implode(', ', $attempted));
$check($sent === 0, 'with no transport nothing counts as sent');
$released = $db->query("SELECT pickup_reminder_sent_for FROM prestiti WHERE id = {$due}")->fetch_row()[0];
$check($released === null, 'a failed send releases the claim, so the next run retries');
$check($db->query("SELECT pickup_reminder_sent_for FROM prestiti WHERE id = {$noMail}")->fetch_row()[0] === null, 'a borrower with no email is not claimed');
$outbox = (int) $db->query("SELECT COUNT(*) FROM email_delivery_outbox WHERE recipient_email LIKE 'zz-pickrem-{$run}%' AND template_name = 'loan_pickup_reminder'")->fetch_row()[0];
$check($outbox === 0, 'no outbox copy: the claim is the retry state (no double send)');

// Sent for this deadline → never again for it; a new deadline re-arms it.
$db->query("UPDATE prestiti SET pickup_reminder_sent_for = pickup_deadline WHERE id = {$due}");
$probe->recipients = [];
$probe->sendPickupReminders();
$check(!in_array($emailDue, $probe->recipients, true), 'a reminder sent for this deadline is not repeated');
$db->query("UPDATE prestiti SET pickup_deadline = '" . $day(1) . "' WHERE id = {$due}");
$probe->recipients = [];
$probe->sendPickupReminders();
$check(in_array($emailDue, $probe->recipients, true), 'a moved deadline re-arms the reminder');

$setSetting('pickup_reminder_days', '0');
$probe->recipients = [];
$check($probe->sendPickupReminders() === 0 && $probe->recipients === [], 'pickup_reminder_days = 0 turns the reminder off');
$setSetting('pickup_reminder_days', '5');
$probe->recipients = [];
$probe->sendPickupReminders();
$check(in_array($emailFar, $probe->recipients, true), 'a wider notice reaches the loan due in 5 days');

// ── C. Staff notice on expiry ───────────────────────────────────────────────
echo "C. staff notice\n";
$service = new NotificationService($db);
$check($service->notifyStaffPickupExpired($due, $day(-1)), 'staff notice created');
$row = $db->query("SELECT title, message, link FROM admin_notifications WHERE related_id = {$due} ORDER BY id DESC LIMIT 1")->fetch_assoc();
$check(is_array($row) && str_contains((string) $row['message'], $title), 'the notice names the book');
$check(is_array($row) && str_contains((string) $row['link'], '/admin/loans'), 'the notice links to the loans page');

echo "\n" . ($failed === 0 ? "ALL {$passed} PASS\n" : "{$passed} PASS, {$failed} FAIL\n");
exit($failed === 0 ? 0 : 1);
