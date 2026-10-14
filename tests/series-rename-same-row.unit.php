<?php
declare(strict_types=1);

/**
 * Series rename / merge must never fold a series into ITSELF.
 *
 * `collane.nome` uses a case- and accent-insensitive collation, so "Foo" and
 * "foo" are the same row for `WHERE nome = ?`. renameSeries('Foo', 'foo')
 * used to see the new name as "already existing", call mergeSeries(), which
 * then deleted the series (by name, again case-insensitively) together with
 * every libri_collane membership — on the web rename form and in the app.
 *
 *   A. A case-only and an accent-only rename keep the row id, rename it in
 *      place, keep every membership and the legacy libri.collana value.
 *   B. mergeSeries() with two names of the same row is a no-op.
 *   C. A rename onto a DIFFERENT existing series still merges (unchanged).
 *
 * Runs on a DISPOSABLE database loaded with schema.sql ("<db>_mobileapi",
 * override with MOBILEAPI_SANDBOX_DB — the same sandbox the other DB units
 * use); it refuses the installation's own database and FAILS rather than
 * skips when the sandbox is unreachable.
 *
 * Run: php tests/series-rename-same-row.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
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
    $repo = new \App\Models\SeriesRepository($db);
    $one = static fn (string $sql): ?array => $db->query($sql)->fetch_assoc() ?: null;
    $seed = static function (string $name, int $books) use ($db): array {
        $db->query("INSERT INTO collane (nome, tipo) VALUES ('" . $db->real_escape_string($name) . "', 'serie')");
        $sid = (int) $db->insert_id;
        $ids = [];
        for ($i = 1; $i <= $books; $i++) {
            $db->query("INSERT INTO libri (titolo, collana, numero_serie, copie_totali, copie_disponibili) VALUES ('Vol {$i} of " . $db->real_escape_string($name) . "', '" . $db->real_escape_string($name) . "', '{$i}', 0, 0)");
            $bid = (int) $db->insert_id;
            $db->query("INSERT INTO libri_collane (libro_id, collana_id, numero_serie, tipo_appartenenza, is_principale) VALUES ({$bid}, {$sid}, '{$i}', 'principale', 1)");
            $ids[] = $bid;
        }
        return [$sid, $ids];
    };
    $rename = static function (string $old, string $new) use ($db, $repo): int {
        $db->begin_transaction();
        $n = $repo->renameSeries($old, $new);
        $db->commit();
        return $n;
    };

    echo "A. Case-only and accent-only renames are in place\n";
    [$sid, $books] = $seed('Foo Saga', 2);
    $rename('Foo Saga', 'foo saga');
    $row = $one("SELECT id, nome FROM collane WHERE id = {$sid}");
    $check($row !== null && $row['nome'] === 'foo saga', 'the row survives with the new spelling (got ' . json_encode($row) . ')');
    $check((int) $one('SELECT COUNT(*) AS n FROM collane')['n'] === 1, 'no second row, nothing deleted');
    $check((int) $one("SELECT COUNT(*) AS n FROM libri_collane WHERE collana_id = {$sid}")['n'] === 2, 'both memberships kept');
    $check($one("SELECT collana FROM libri WHERE id = {$books[0]}")['collana'] === 'foo saga', 'legacy libri.collana follows the new spelling');
    $rename('foo saga', 'Föo Saga');
    $check(($one("SELECT nome FROM collane WHERE id = {$sid}")['nome'] ?? null) === 'Föo Saga' && (int) $one("SELECT COUNT(*) AS n FROM libri_collane WHERE collana_id = {$sid}")['n'] === 2, 'accent-only rename: same row, memberships kept');

    echo "\nB. Merging a series with itself is a no-op\n";
    $db->begin_transaction();
    $merged = $repo->mergeSeries('Föo Saga', 'FOO SAGA');
    $db->commit();
    $check($merged === 0, 'mergeSeries() returns 0');
    $check($one("SELECT id FROM collane WHERE id = {$sid}") !== null && (int) $one("SELECT COUNT(*) AS n FROM libri_collane WHERE collana_id = {$sid}")['n'] === 2, 'row and memberships intact');

    echo "\nC. A rename onto a different series still merges\n";
    [$other, $otherBooks] = $seed('Bar Saga', 1);
    $rename('Bar Saga', 'Föo Saga');
    $check($one("SELECT id FROM collane WHERE id = {$other}") === null, 'the source series is gone');
    $check((int) $one("SELECT COUNT(*) AS n FROM libri_collane WHERE collana_id = {$sid}")['n'] === 3, 'its book moved into the target');
    $check($one("SELECT id FROM collane WHERE id = {$sid}") !== null, 'the target survives');

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
