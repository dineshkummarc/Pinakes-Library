<?php
declare(strict_types=1);

/**
 * Mobile API 1.6.0 — the calendar-token table reaches every installation, and
 * the personal feed is correct RFC 5545 without personal data.
 *
 *   A. Fresh install: registration (onInstall) and PluginManager::
 *      activatePlugin() — the production path, never ensureSchema() alone —
 *      each create mobile_calendar_tokens.
 *   B. Upgrade: an installation on 1.5.1 without the table gets it from the
 *      real bundled-plugin sync (autoRegisterBundledPlugins), and the recorded
 *      version moves to the shipped one.
 *   C. Same-version self-heal: the table dropped behind the plugin's back is
 *      rebuilt on the next sync (expectedTables()).
 *   D. CalendarTokenService: rotate / resolve / status / revoke, plaintext
 *      never stored; a suspended/unverified owner resolves to nothing; a
 *      password reset (CredentialRevoker::revokeAll) revokes the feed token.
 *   E. UserLoanCalendar on real rows: only the owner's approved loans,
 *      escaping, 75-octet folding, CRLF, SEQUENCE, VALARM, no name/e-mail,
 *      stable output (so the ETag only moves when a loan does).
 *
 * Runs against a DISPOSABLE database of its own ("<db>_mobileapi", override
 * with MOBILEAPI_SANDBOX_DB) and refuses the installation's. It FAILS rather
 * than skips when that database is unreachable.
 *
 * Run: php tests/mobile-api-calendar.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\HookManager;
use App\Support\PluginManager;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
// A throwaway key: the sandbox is wiped afterwards, nothing encrypted here survives.
$_ENV['PLUGIN_ENCRYPTION_KEY'] = bin2hex(random_bytes(32));
putenv('PINAKES_DISABLE_CLI_PURGE=1');

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) ?: [] as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$dbHost = getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$dbPort = (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306));

$sandbox = getenv('MOBILEAPI_SANDBOX_DB') ?: ($dbName . '_mobileapi');
if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $sandbox) !== 1) {
    fwrite(STDERR, "FAIL: invalid sandbox database name '{$sandbox}'\n");
    exit(1);
}
$real = array_map('strtolower', array_filter([(string) $dbName, (string) getenv('E2E_DB_NAME'), (string) ($env['DB_NAME'] ?? '')]));
if (in_array(strtolower($sandbox), $real, true)) {
    fwrite(STDERR, "FAIL: the sandbox name is the installation's own database. Name a disposable one.\n");
    exit(1);
}

$connect = static function (string $database) use ($socket, $dbUser, $dbPass, $dbHost, $dbPort): mysqli {
    $db = is_string($socket) && $socket !== '' && file_exists($socket)
        ? new mysqli(null, $dbUser, $dbPass, $database, 0, $socket)
        : new mysqli($dbHost, $dbUser, $dbPass, $database, $dbPort);
    $db->set_charset('utf8mb4');

    return $db;
};
$wipe = static function (mysqli $db): void {
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    $tables = [];
    $result = $db->query('SHOW FULL TABLES');
    while ($row = $result->fetch_row()) {
        $tables[] = [$row[0], $row[1]];
    }
    foreach ($tables as [$table, $type]) {
        $db->query(($type === 'VIEW' ? 'DROP VIEW IF EXISTS `' : 'DROP TABLE IF EXISTS `') . $table . '`');
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
};

$admin = $connect('');
$admin->query("CREATE DATABASE IF NOT EXISTS `{$sandbox}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$admin->close();
$prep = $connect($sandbox);
$wipe($prep);
$prep->close();

$fatal = null;
try {
    $useSocket = is_string($socket) && $socket !== '' && file_exists($socket);
    $command = $useSocket
        ? sprintf('mysql --default-character-set=utf8mb4 --socket=%s -u %s %s', escapeshellarg($socket), escapeshellarg((string) $dbUser), escapeshellarg($sandbox))
        : sprintf('mysql --default-character-set=utf8mb4 -h %s -P %d --protocol=TCP -u %s %s', escapeshellarg((string) $dbHost), $dbPort, escapeshellarg((string) $dbUser), escapeshellarg($sandbox));
    $command .= ' < ' . escapeshellarg($root . '/installer/database/schema.sql') . ' 2>&1';
    $previous = getenv('MYSQL_PWD');
    putenv('MYSQL_PWD=' . (string) $dbPass);
    try {
        exec($command, $out, $rc);
    } finally {
        $previous === false ? putenv('MYSQL_PWD') : putenv('MYSQL_PWD=' . $previous);
    }
    if ($rc !== 0) {
        throw new RuntimeException('could not load schema.sql: ' . implode("\n", $out));
    }

    $db = $connect($sandbox);
    $manager = new PluginManager($db, new HookManager($db));
    $hasTable = static fn (): bool => $db->query("SHOW TABLES LIKE 'mobile\\_calendar\\_tokens'")->num_rows === 1;
    $shipped = (string) (json_decode((string) file_get_contents($root . '/storage/plugins/mobile-api/plugin.json'), true)['version'] ?? '');

    echo "A. Fresh install through PluginManager::activatePlugin()\n";
    $manager->autoRegisterBundledPlugins();
    $row = $db->query("SELECT id, is_active FROM plugins WHERE name = 'mobile-api'")->fetch_assoc();
    $check($row !== null && (int) $row['is_active'] === 0, 'mobile-api registered and inactive by default');
    $check($hasTable(), 'registration runs onInstall(), which already builds the table');
    // Take it away again: activation must build it on its own (onActivate).
    $db->query('DROP TABLE mobile_calendar_tokens');
    $result = $manager->activatePlugin((int) $row['id']);
    $check(($result['success'] ?? false) === true, 'activation succeeds: ' . (string) ($result['message'] ?? ''));
    $check($hasTable(), 'activation creates mobile_calendar_tokens');
    $cols = [];
    $res = $db->query('SHOW COLUMNS FROM mobile_calendar_tokens');
    while ($c = $res->fetch_assoc()) {
        $cols[] = $c['Field'];
    }
    $check($cols === ['user_id', 'token_hash', 'token_enc', 'created_at', 'rotated_at', 'revoked_at'], 'columns: ' . implode(',', $cols));
    $check($db->query("SHOW INDEX FROM mobile_calendar_tokens WHERE Key_name = 'uq_calendar_token_hash' AND Non_unique = 0")->num_rows === 1, 'token_hash is unique');

    echo "\nB. Upgrade from 1.5.1 through the real bundled-plugin sync\n";
    $db->query('DROP TABLE mobile_calendar_tokens');
    $db->query("UPDATE plugins SET version = '1.5.1' WHERE name = 'mobile-api'");
    $check(!$hasTable(), 'rewound: a 1.5.1 installation has no calendar table');
    $manager->autoRegisterBundledPlugins();
    $after = (string) $db->query("SELECT version FROM plugins WHERE name = 'mobile-api'")->fetch_assoc()['version'];
    $check($after === $shipped, "recorded version moves to the shipped {$shipped} (got {$after})");
    $check($hasTable(), 'the upgrade creates the table on an installation that already had the plugin');

    echo "\nC. Same-version self-heal\n";
    $db->query('DROP TABLE mobile_calendar_tokens');
    $manager->autoRegisterBundledPlugins();
    $check($hasTable(), 'a table dropped at the same version is rebuilt by the next sync');

    echo "\nD. CalendarTokenService\n";
    require_once $root . '/storage/plugins/mobile-api/src/Support/CalendarTokenService.php';
    require_once $root . '/storage/plugins/mobile-api/src/Support/UserLoanCalendar.php';
    $db->query("INSERT INTO utenti (nome, cognome, email, password, tipo_utente, stato, email_verificata, codice_tessera, privacy_accettata)
                VALUES ('Anna', 'Owner', 'anna.owner@example.invalid', 'x', 'standard', 'attivo', 1, 'CALU1', 1),
                       ('Bruno', 'Other', 'bruno.other@example.invalid', 'x', 'standard', 'attivo', 1, 'CALU2', 1)");
    $userA = (int) $db->query("SELECT id FROM utenti WHERE codice_tessera = 'CALU1'")->fetch_row()[0];
    $userB = (int) $db->query("SELECT id FROM utenti WHERE codice_tessera = 'CALU2'")->fetch_row()[0];
    $tokens = new \App\Plugins\MobileApi\Support\CalendarTokenService($db);
    $check($tokens->status($userA)['enabled'] === false, 'no token yet → disabled');
    $t1 = $tokens->rotate($userA);
    $check(preg_match('/^[a-f0-9]{64}$/', $t1) === 1, 'token is 256 random bits in hex');
    $check($tokens->resolve($t1) === $userA, 'resolve() finds the owner');
    $status = $tokens->status($userA);
    $check($status['enabled'] === true && $status['token'] === $t1 && $status['rotated_at'] === null, 'status() re-displays the same token');
    $stored = $db->query("SELECT token_hash, token_enc FROM mobile_calendar_tokens WHERE user_id = {$userA}")->fetch_assoc();
    $check($stored['token_hash'] === hash('sha256', $t1) && !str_contains((string) $stored['token_enc'], $t1), 'only the hash and an encrypted copy are stored');
    $t2 = $tokens->rotate($userA);
    $check($t2 !== $t1 && $tokens->resolve($t1) === null && $tokens->resolve($t2) === $userA, 'rotation kills the old token at once');
    $check($tokens->status($userA)['rotated_at'] !== null, 'rotated_at is set');
    $tokens->revoke($userA);
    $tokens->revoke($userA);
    $check($tokens->resolve($t2) === null && $tokens->status($userA)['enabled'] === false, 'revoke is effective and idempotent');
    $check($tokens->resolve('not-a-token') === null && $tokens->resolve(str_repeat('0', 64)) === null, 'malformed / unknown tokens resolve to null');
    $t3 = $tokens->rotate($userA);
    foreach (["stato = 'sospeso'", "stato = 'scaduto'", 'email_verificata = 0'] as $state) {
        $restore = str_starts_with($state, 'stato') ? "stato = 'attivo'" : 'email_verificata = 1';
        $db->query("UPDATE utenti SET {$state} WHERE id = {$userA}");
        $check($tokens->resolve($t3) === null, "an owner with {$state} gets no feed, token untouched");
        $db->query("UPDATE utenti SET {$restore} WHERE id = {$userA}");
    }
    $check($tokens->resolve($t3) === $userA, 'and gets it back once active again');
    $revoked = \App\Support\CredentialRevoker::revokeAll($db, $userA, false);
    $check(($revoked['calendar'] ?? null) === 1 && $tokens->resolve($t3) === null, 'a password reset (CredentialRevoker::revokeAll) revokes the feed token');
    $again = \App\Support\CredentialRevoker::revokeAll($db, $userA, false);
    $check($again['calendar'] === 0 && array_keys($again) === ['sessions', 'mobile', 'calendar'], 'revokeAll is idempotent and keeps its sessions/mobile keys');

    echo "\nE. UserLoanCalendar\n";
    $title = 'Viaggio, al centro; della terra — edizione «integrale» con un titolo molto lungo àèìòù';
    $stmt = $db->prepare('INSERT INTO libri (titolo, copie_totali, copie_disponibili) VALUES (?, 1, 1)');
    foreach ([$title, 'Libro programmato', 'Libro altrui', 'Libro restituito', 'Libro eliminato'] as $t) {
        $stmt->bind_param('s', $t);
        $stmt->execute();
    }
    $book = static fn (string $t): int => (int) $db->query("SELECT id FROM libri WHERE titolo = '" . $db->real_escape_string($t) . "'")->fetch_row()[0];
    $loan = static function (int $bookId, int $userId, string $state, string $start, string $due, int $active = 1) use ($db): int {
        $db->query("INSERT INTO prestiti (libro_id, utente_id, data_prestito, data_scadenza, stato, attivo, origine)
                    VALUES ({$bookId}, {$userId}, {$start}, {$due}, '{$state}', {$active}, 'diretto')");
        return (int) $db->insert_id;
    };
    $running = $loan($book($title), $userA, 'in_corso', 'CURDATE()', 'DATE_ADD(CURDATE(), INTERVAL 14 DAY)');
    $scheduled = $loan($book('Libro programmato'), $userA, 'prenotato', 'DATE_ADD(CURDATE(), INTERVAL 30 DAY)', 'DATE_ADD(CURDATE(), INTERVAL 44 DAY)');
    $others = $loan($book('Libro altrui'), $userB, 'in_corso', 'CURDATE()', 'DATE_ADD(CURDATE(), INTERVAL 7 DAY)');
    $returned = $loan($book('Libro restituito'), $userA, 'restituito', 'DATE_SUB(CURDATE(), INTERVAL 30 DAY)', 'DATE_SUB(CURDATE(), INTERVAL 16 DAY)', 0);
    $deletedBookLoan = $loan($book('Libro eliminato'), $userA, 'in_corso', 'CURDATE()', 'DATE_ADD(CURDATE(), INTERVAL 7 DAY)');
    $db->query("UPDATE libri SET deleted_at = NOW() WHERE titolo = 'Libro eliminato'");

    $calendar = new \App\Plugins\MobileApi\Support\UserLoanCalendar($db);
    $ics = $calendar->generate($userA, 'Biblioteca: I miei prestiti');
    $lines = explode("\r\n", rtrim($ics, "\r\n"));
    $check(str_starts_with($ics, "BEGIN:VCALENDAR\r\n") && str_ends_with($ics, "END:VCALENDAR\r\n"), 'VCALENDAR envelope with CRLF');
    $check(!str_contains(str_replace("\r\n", '', $ics), "\n"), 'no bare LF anywhere');
    $long = array_filter($lines, static fn (string $l): bool => strlen($l) > 75);
    $check($long === [], 'every physical line is at most 75 octets');
    $check(array_filter($lines, static fn (string $l): bool => !mb_check_encoding($l, 'UTF-8')) === [], 'folding never splits a UTF-8 sequence');
    $unfolded = str_replace("\r\n ", '', $ics);
    $check(str_contains($unfolded, "UID:loan-{$running}@pinakes") && str_contains($unfolded, "UID:loan-{$scheduled}@pinakes"), "the owner's approved loans are in");
    $check(!str_contains($unfolded, "loan-{$others}@") && !str_contains($unfolded, 'Libro altrui'), "another user's loan is not");
    $check(!str_contains($unfolded, "loan-{$returned}@") && !str_contains($unfolded, "loan-{$deletedBookLoan}@"), 'returned loans and soft-deleted titles are not');
    $check(!str_contains($unfolded, '@example.invalid') && !str_contains($unfolded, 'Anna') && !str_contains($unfolded, 'Owner'), 'no name and no e-mail address');
    $check(str_contains($unfolded, 'Viaggio\\, al centro\\; della terra'), 'TEXT escaping of , and ;');
    $check(str_contains($unfolded, '\\nStato: '), 'newline escaped as \\n in DESCRIPTION');
    $seq = (int) $db->query("SELECT UNIX_TIMESTAMP(updated_at) FROM prestiti WHERE id = {$running}")->fetch_row()[0];
    $check(str_contains($unfolded, "SEQUENCE:{$seq}"), 'SEQUENCE = UNIX_TIMESTAMP(updated_at)');
    $check(substr_count($unfolded, 'BEGIN:VALARM') === 2 && str_contains($unfolded, 'TRIGGER;RELATED=END:-P1D') && str_contains($unfolded, "TRIGGER:-P1D\r\n"), 'one reminder per running/scheduled loan');
    $check($calendar->generate($userA, 'Biblioteca: I miei prestiti') === $ics, 'output is stable between calls (ETag-friendly)');
    $check(substr_count($calendar->generate($userA, 'x', true, true), 'BEGIN:VEVENT') === 2, 'no reservations → still two events with the flag on');
    $check(!str_contains($calendar->generate($userA, 'x', false), 'VALARM'), 'alarms can be switched off');
    $check(\App\Plugins\MobileApi\Support\UserLoanCalendar::line(str_repeat('é', 60)) === mb_strcut(str_repeat('é', 60), 0, 75, 'UTF-8') . "\r\n " . substr(str_repeat('é', 60), strlen(mb_strcut(str_repeat('é', 60), 0, 75, 'UTF-8'))) . "\r\n", 'fold helper cuts at 75 octets on a character boundary');

    $db->close();
} catch (\Throwable $error) {
    $fatal = $error;
} finally {
    try {
        $cleanup = $connect($sandbox);
        $wipe($cleanup);
        $cleanup->close();
    } catch (\Throwable $error) {
        fwrite(STDERR, "CLEANUP FAILED: {$error->getMessage()}\n");
        $fail++;
    }
}

if ($fatal !== null) {
    fwrite(STDERR, "\nFAIL: {$fatal->getMessage()}\n{$fatal->getTraceAsString()}\n");
    exit(1);
}

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
