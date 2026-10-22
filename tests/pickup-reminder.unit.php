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
 *      re-arms it; a new deadline re-arms it; 0 turns it off. Preparation
 *      failures and abandoned workers remain retryable, concurrent workers
 *      do not double-send, and a failed ready notice must be delivered first.
 *   C. The staff gets an in-app notice when a pickup expires, written in the
 *      expiry transaction so a failed notice is retried, never lost.
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
// The deadline day itself is still valid (expiry is pickup_deadline < today):
// French "avant le …" would read it as excluded.
$frSeed = (string) file_get_contents("{$root}/installer/database/data_fr_FR.sql");
foreach (['loan_approved', 'loan_pickup_ready', 'loan_pickup_reminder'] as $name) {
    $fr = SettingsMailTemplates::get($name, 'fr_FR');
    $text = (string) $fr['subject'] . (string) $fr['body'];
    $check(!preg_match('/avant (le \{\{scadenza_ritiro\}\}|cette date)/u', $text), "fr_FR {$name}: the deadline day is inclusive");
}
$check(!preg_match('/avant (le \{\{scadenza_ritiro\}\}|cette date)/u', $frSeed), 'fr_FR seeds: the deadline day is inclusive');

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

final class ReminderCaptureEmailService extends \App\Support\EmailService
{
    /** @var list<array{email:string, template:string}> */
    public array $deliveries = [];
    /** @var list<array<string, mixed>> variables of each delivery, same order */
    public array $variables = [];
    public ?\Closure $onSend = null;

    public function sendTemplate(string $to, string $templateName, array $variables = [], ?string $locale = null, array $attachments = []): bool
    {
        $this->deliveries[] = ['email' => $to, 'template' => $templateName];
        $this->variables[] = $variables;
        if ($this->onSend !== null) {
            ($this->onSend)();
        }
        return true;
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
    if ($state === 'da_ritirare') {
        // Historical delivered notices may have no attempt timestamp; an
        // unsent notice is a distinct state covered by the recovery tests.
        $db->query("UPDATE prestiti SET pickup_notification_sent = 1 WHERE id = {$id}");
    }
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

/** The variables the approval email was actually sent with (captured transport). */
$approvedVars = static function (int $loanId) use ($db): array {
    $mail = new ReminderCaptureEmailService($db);
    $service = new PickupProbeNotificationService($db);
    (new ReflectionProperty(NotificationService::class, 'emailService'))->setValue($service, $mail);
    (new ReflectionProperty(\App\Support\Mailer::class, 'smtpReachable'))->setValue(null, true);
    try {
        $service->sendLoanApprovedNotification($loanId);
    } finally {
        (new ReflectionProperty(\App\Support\Mailer::class, 'smtpReachable'))->setValue(null, false);
    }
    $i = array_search('loan_approved', array_column($mail->deliveries, 'template'), true);
    return $i === false ? [] : $mail->variables[$i];
};
$capped = $approvedVars($shortLoan);
$check(($capped['scadenza_ritiro'] ?? null) === $day(8) && ($capped['giorni_ritiro'] ?? null) === '3',
    'capped deadline → {{giorni_ritiro}} is 3, not 7 (no promise of days the reader does not have)');
$full = $approvedVars($loanA);
$check(($full['scadenza_ritiro'] ?? null) === $day(12) && ($full['giorni_ritiro'] ?? null) === '7',
    'uncapped → {{giorni_ritiro}} is the configured 7');

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
$check($released === null, 'a failed send leaves the deadline unacknowledged, so the next run retries');
$check($db->query("SELECT pickup_reminder_sent_for FROM prestiti WHERE id = {$noMail}")->fetch_row()[0] === null, 'a borrower with no email is not claimed');
$outbox = (int) $db->query("SELECT COUNT(*) FROM email_delivery_outbox WHERE recipient_email LIKE 'zz-pickrem-{$run}%' AND template_name = 'loan_pickup_reminder'")->fetch_row()[0];
$check($outbox === 0, 'no outbox copy: reminder delivery is retried through the loan');

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

// ── Delivery recovery and concurrent workers ───────────────────────────────
echo "B. delivery recovery and concurrency\n";
$setSetting('pickup_reminder_days', '2');
$db->query("UPDATE prestiti SET pickup_reminder_sent_for = pickup_deadline WHERE libro_id = {$bookId}");

final class ThrowingReminderNotificationService extends NotificationService
{
    public function __construct(mysqli $db, private string $failFor)
    {
        parent::__construct($db);
    }

    public function resolveRecipientLocale(string $email): string
    {
        if ($email === $this->failFor) {
            throw new RuntimeException('Injected preparation failure after acquiring reminder lock');
        }
        return parent::resolveRecipientLocale($email);
    }
}

$transport = new ReminderCaptureEmailService($db);
$useTransport = static function (NotificationService $service, ReminderCaptureEmailService $mail): NotificationService {
    (new ReflectionProperty(NotificationService::class, 'emailService'))->setValue($service, $mail);
    return $service;
};
(new ReflectionProperty(\App\Support\Mailer::class, 'smtpReachable'))->setValue(null, true);

$errorEmail = "zz-pickrem-{$run}-error@test.local";
$errorLoan = $mkLoan($mkUser('ERR', $errorEmail), 'da_ritirare', $day(-5), $day(25), $day(2));
$nextEmail = "zz-pickrem-{$run}-next@test.local";
$nextLoan = $mkLoan($mkUser('NEXT', $nextEmail), 'da_ritirare', $day(-5), $day(25), $day(2));
$throwing = $useTransport(new ThrowingReminderNotificationService($db, $errorEmail), $transport);
$check($throwing->sendPickupReminders() === 1
    && $transport->deliveries === [['email' => $nextEmail, 'template' => 'loan_pickup_reminder']],
    'an exception preparing one reminder does not block the rest of the batch');
$check($db->query("SELECT pickup_reminder_sent_for FROM prestiti WHERE id = {$errorLoan}")->fetch_row()[0] === null,
    'a preparation exception cannot leave a permanent sent marker');
$healthy = $useTransport(new NotificationService($db), $transport);
$check($healthy->sendPickupReminders() === 1
    && $db->query("SELECT pickup_reminder_sent_for FROM prestiti WHERE id = {$errorLoan}")->fetch_row()[0] === $day(2),
    'the next healthy run delivers and acknowledges the previously failed reminder');
$check($healthy->sendPickupReminders() === 0, 'successful delivery is acknowledged only once for its deadline');

// A second connection models another hourly/HTTP worker. Keep the first
// sender inside its transport while the second tries the same loan.
$otherDb = $socket !== '' && file_exists($socket)
    ? new mysqli(null, getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), 0, $socket)
    : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
$otherDb->set_charset('utf8mb4');
DateHelper::synchronizeDatabaseSession($otherDb);
$otherTransport = new ReminderCaptureEmailService($otherDb);
$otherWorker = $useTransport(new NotificationService($otherDb), $otherTransport);
$db->query("UPDATE prestiti SET pickup_deadline = '" . $day(1) . "' WHERE id = {$errorLoan}");
$concurrentSent = -1;
$transport->onSend = static function () use ($otherWorker, &$concurrentSent): void {
    $concurrentSent = $otherWorker->sendPickupReminders();
};
$check($healthy->sendPickupReminders() === 1 && $concurrentSent === 0 && $otherTransport->deliveries === [],
    'a moved deadline re-arms delivery, but a concurrent worker cannot double-send it');
$transport->onSend = null;

// Model a process that acquired the lock and lost its database connection
// before delivery: the next worker must not inherit a durable sent claim.
$database = (string) $db->query('SELECT DATABASE()')->fetch_row()[0];
$lockName = 'pinakes_pickup_reminder_' . substr(hash('sha256', $database), 0, 24) . '_' . $errorLoan;
$db->query("UPDATE prestiti SET pickup_reminder_sent_for = NULL WHERE id = {$errorLoan}");
$lock = $otherDb->prepare('SELECT GET_LOCK(?, 0)');
$lock->bind_param('s', $lockName);
$lock->execute();
$check((int) $lock->get_result()->fetch_row()[0] === 1 && $healthy->sendPickupReminders() === 0,
    'an active worker owns the reminder until its connection releases the lock');
$lock->close();
$otherDb->close();
$check($healthy->sendPickupReminders() === 1, 'a disconnected worker leaves the reminder retryable');

// The order used by MaintenanceService and automatic-notifications.php:
// reminders first, then the ready-notice retries. SMTP recovery must produce
// only the initial ready notice today, never a reminder before it.
$waitingEmail = "zz-pickrem-{$run}-waiting@test.local";
$waiting = $mkLoan($mkUser('WAIT', $waitingEmail), 'da_ritirare', $day(-5), $day(25), $day(2));
$db->query("UPDATE prestiti SET pickup_notification_sent = 0, pickup_notification_last_attempt_at = UTC_TIMESTAMP() - INTERVAL 1 DAY WHERE id = {$waiting}");
$transport->deliveries = [];
$check($healthy->sendPickupReminders() === 0 && $transport->deliveries === [],
    'a failed ready attempt yesterday is not mistaken for a delivered notice');
$check($healthy->retryUnsentPickupNotifications() === 1
    && $transport->deliveries === [['email' => $waitingEmail, 'template' => 'loan_pickup_ready']],
    'SMTP recovery sends only the ready notice on the same maintenance pass');
$check($healthy->sendPickupReminders() === 0, 'a ready notice delivered today suppresses today\'s reminder');
$db->query("UPDATE prestiti SET pickup_notification_last_attempt_at = UTC_TIMESTAMP() - INTERVAL 1 DAY WHERE id = {$waiting}");
$check($healthy->sendPickupReminders() === 1, 'the reminder becomes eligible after the ready-delivery day');

// ── C. Staff notice on expiry ───────────────────────────────────────────────
echo "C. staff notice\n";
$service = new NotificationService($db);
$check($service->notifyStaffPickupExpired($due, $day(-1)), 'staff notice created');
$row = $db->query("SELECT title, message, link FROM admin_notifications WHERE related_id = {$due} ORDER BY id DESC LIMIT 1")->fetch_assoc();
$check(is_array($row) && str_contains((string) $row['message'], $title), 'the notice names the book');
$check(is_array($row) && str_contains((string) $row['link'], '/admin/loans'), 'the notice links to the loans page');

// The notice is written in the expiry transaction: if it cannot be stored the
// loan stays awaiting pickup and the next sweep retries both. Written after the
// commit it was lost for good — the sweep never selects a `scaduto` loan again.
(new ReflectionProperty(\App\Support\Mailer::class, 'smtpReachable'))->setValue(null, false);
$expiring = $mkLoan($mkUser('EXP', "zz-pickrem-{$run}-exp@test.local"), 'da_ritirare', $day(-9), $day(21), $day(-1));
$stateOf = static function (int $loanId) use ($db): array {
    $row = $db->query("SELECT p.stato, c.stato AS copia, (SELECT COUNT(*) FROM admin_notifications n WHERE n.related_id = p.id) AS notices
        FROM prestiti p JOIN copie c ON c.id = p.copia_id WHERE p.id = {$loanId}")->fetch_assoc();
    return [(string) $row['stato'], (string) $row['copia'], (int) $row['notices']];
};
$trigger = "zz_pickrem_fail_{$run}";
$db->query("CREATE TRIGGER {$trigger} BEFORE INSERT ON admin_notifications FOR EACH ROW
    BEGIN IF NEW.related_id = {$expiring} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated notice failure'; END IF; END");
try {
    (new \App\Support\MaintenanceService($db))->checkExpiredPickups();
} finally {
    $db->query("DROP TRIGGER IF EXISTS {$trigger}");
}
$check($stateOf($expiring) === ['da_ritirare', 'prenotato', 0], 'a staff notice that cannot be stored rolls the expiry back');
(new \App\Support\MaintenanceService($db))->checkExpiredPickups();
$check($stateOf($expiring) === ['scaduto', 'disponibile', 1], 'the next sweep expires the pickup and tells the staff once');

echo "\n" . ($failed === 0 ? "ALL {$passed} PASS\n" : "{$passed} PASS, {$failed} FAIL\n");
exit($failed === 0 ? 0 : 1);
