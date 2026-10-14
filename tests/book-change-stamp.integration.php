<?php
declare(strict_types=1);

/**
 * Change feeds and the library calendar, driven through the real classes.
 *
 *   A. Renaming an author, a publisher or a genre moves libri.updated_at of the
 *      linked books (BookChangeStamp), so /catalog/sync and ResourceSync see
 *      the new name; soft-deleted books are left alone.
 *   B. MaintenanceService::healPublicIcsFile() rewrites a public calendar file
 *      that still carries borrower emails, and leaves a clean one untouched.
 *   C. LoanCalendarLinks::libraryFeedOperator() accepts an operator link only
 *      for an active admin/staff account with the right token.
 *
 * Runs against the INSTALLATION's database, scoped to the prefixed rows it
 * inserts and deletes itself.
 *
 * Run:  php tests/book-change-stamp.integration.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!function_exists('__')) {
    function __(string $text, mixed ...$args): string
    {
        return $args ? vsprintf($text, $args) : $text;
    }
}
if (!function_exists('url')) {
    function url(string $path): string
    {
        return $path;
    }
}

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
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
$db = is_string($socket) && $socket !== '' && file_exists($socket)
    ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
    : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $dbUser, $dbPass, $dbName, (int) ($env['DB_PORT'] ?? 3306));
$db->set_charset('utf8mb4');

$run = 'zzstamp_' . getmypid() . '_' . time();
$old = '2020-01-01 00:00:00';
$created = ['libri' => [], 'autori' => [], 'editori' => [], 'generi' => [], 'utenti' => []];

$insert = static function (string $sql) use ($db): int {
    $db->query($sql);
    return (int) $db->insert_id;
};
$q = static fn (string $v): string => "'" . $db->real_escape_string($v) . "'";
$updatedAt = static function (int $bookId) use ($db): string {
    return (string) $db->query("SELECT updated_at FROM libri WHERE id = {$bookId}")->fetch_row()[0];
};
$age = static function (array $ids) use ($db, $old): void {
    $db->query("UPDATE libri SET updated_at = '{$old}' WHERE id IN (" . implode(',', $ids) . ')');
};

try {
    echo "A. Renames reach the change feeds\n";
    $genre = $insert("INSERT INTO generi (nome) VALUES ({$q("Genere {$run}")})");
    $created['generi'][] = $genre;
    $sub = $insert("INSERT INTO generi (nome, parent_id) VALUES ({$q("Sottogenere {$run}")}, {$genre})");
    $created['generi'][] = $sub;
    $author = $insert("INSERT INTO autori (nome) VALUES ({$q("Autore {$run}")})");
    $created['autori'][] = $author;
    $publisher = $insert("INSERT INTO editori (nome) VALUES ({$q("Editore {$run}")})");
    $created['editori'][] = $publisher;

    $live = $insert("INSERT INTO libri (titolo, genere_id, editore_id, copie_totali, copie_disponibili) VALUES ({$q("Libro {$run}")}, {$genre}, {$publisher}, 1, 1)");
    $bySub = $insert("INSERT INTO libri (titolo, genere_id, sottogenere_id, copie_totali, copie_disponibili) VALUES ({$q("Libro sotto {$run}")}, {$genre}, {$sub}, 1, 1)");
    $gone = $insert("INSERT INTO libri (titolo, genere_id, editore_id, copie_totali, copie_disponibili, deleted_at) VALUES ({$q("Libro eliminato {$run}")}, {$genre}, {$publisher}, 1, 1, NOW())");
    $unrelated = $insert("INSERT INTO libri (titolo, copie_totali, copie_disponibili) VALUES ({$q("Libro estraneo {$run}")}, 1, 1)");
    array_push($created['libri'], $live, $bySub, $gone, $unrelated);
    $db->query("INSERT INTO libri_autori (libro_id, autore_id, ruolo) VALUES ({$live}, {$author}, 'principale'), ({$gone}, {$author}, 'principale')");

    $age([$live, $bySub, $gone, $unrelated]);
    (new \App\Models\AuthorRepository($db))->update($author, ['nome' => "Autore rinominato {$run}"]);
    $check($updatedAt($live) > $old, 'author rename moves the linked book');
    $check($updatedAt($gone) === $old, 'a soft-deleted book is not touched');
    $check($updatedAt($unrelated) === $old, 'an unrelated book is not touched');

    $age([$live, $bySub, $gone, $unrelated]);
    (new \App\Models\PublisherRepository($db))->update($publisher, ['nome' => "Editore rinominato {$run}"]);
    $check($updatedAt($live) > $old, 'publisher rename moves the linked book');
    $check($updatedAt($gone) === $old, 'publisher rename leaves the soft-deleted book alone');

    $age([$live, $bySub, $gone, $unrelated]);
    (new \App\Models\GenereRepository($db))->update($sub, ['nome' => "Sottogenere rinominato {$run}"]);
    $check($updatedAt($bySub) > $old, 'subgenre rename moves the book filed under it');
    $check($updatedAt($live) === $old, 'a subgenre rename does not touch books outside it');

    $age([$live, $bySub, $gone, $unrelated]);
    (new \App\Models\GenereRepository($db))->update($genre, ['nome' => "Genere rinominato {$run}"]);
    $check($updatedAt($live) > $old && $updatedAt($bySub) > $old, 'genre rename moves every book of the genre');
    $check($updatedAt($unrelated) === $old, 'genre rename leaves unrelated books alone');

    echo "B. Public calendar file self-heal\n";
    $withPii = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:loan-1@pinakes\r\nDESCRIPTION:Libro: X\\nUtente: Mario Rossi\\nEmail: mario.rossi@exa\r\n mple.org\\nStato: In corso\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    $clean = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:loan-1@pinakes\r\nDESCRIPTION:Libro: X\\nStato: In corso\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    $check(\App\Support\MaintenanceService::icsHasPersonalData($withPii), 'an email in a folded DESCRIPTION is detected');
    $check(!\App\Support\MaintenanceService::icsHasPersonalData($clean), 'a UID with @ alone is not personal data');

    $tmpDir = sys_get_temp_dir() . '/' . $run;
    @mkdir($tmpDir, 0700, true);
    $file = $tmpDir . '/library-calendar.ics';
    file_put_contents($file, $withPii);
    $healed = \App\Support\MaintenanceService::healPublicIcsFile($db, $file);
    $after = is_file($file) ? (string) file_get_contents($file) : '';
    $check($healed, 'a file with personal data is rewritten');
    $check($after !== '' && !\App\Support\MaintenanceService::icsHasPersonalData($after) && str_contains($after, 'BEGIN:VCALENDAR'), 'the rewritten file is a calendar without personal data');
    file_put_contents($file, $clean);
    $check(!\App\Support\MaintenanceService::healPublicIcsFile($db, $file) && file_get_contents($file) === $clean, 'a clean file is left as it is');
    @unlink($file);
    @rmdir($tmpDir);

    echo "C. Operator calendar links\n";
    $links = new \App\Support\LoanCalendarLinks($db);
    $staff = $insert("INSERT INTO utenti (nome, cognome, email, password, tipo_utente, stato, email_verificata, codice_tessera) VALUES ('Op', {$q($run)}, {$q("op_{$run}@pinakes.test")}, 'x', 'staff', 'attivo', 1, {$q(substr('T' . md5($run), 0, 20))})");
    $created['utenti'][] = $staff;
    $token = $links->libraryFeedToken($staff);
    $check($links->libraryFeedOperator((string) $staff, $token) === $staff, 'an active staff member with the right token is accepted');
    $check($links->libraryFeedOperator((string) $staff, str_repeat('0', 64)) === null, 'a wrong token is refused');
    $check($links->libraryFeedOperator('abc', $token) === null, 'a non-numeric operator id is refused');
    $check(str_contains($links->libraryFeedUrl($staff), 'op=' . $staff . '&token=' . $token), 'the URL carries the operator and the token');
    $db->query("UPDATE utenti SET tipo_utente = 'standard' WHERE id = {$staff}");
    $check($links->libraryFeedOperator((string) $staff, $token) === null, 'a demoted operator loses the link');
    $db->query("UPDATE utenti SET tipo_utente = 'admin', stato = 'sospeso' WHERE id = {$staff}");
    $check($links->libraryFeedOperator((string) $staff, $token) === null, 'a suspended operator loses the link');
} catch (\Throwable $e) {
    $check(false, 'unexpected ' . get_class($e) . ': ' . $e->getMessage());
} finally {
    foreach ($created['libri'] as $id) {
        $db->query("DELETE FROM libri_autori WHERE libro_id = {$id}");
        $db->query("DELETE FROM libri_editori WHERE libro_id = {$id}");
        $db->query("DELETE FROM libri WHERE id = {$id}");
    }
    foreach ($created['autori'] as $id) {
        $db->query("DELETE FROM autori WHERE id = {$id}");
    }
    foreach ($created['editori'] as $id) {
        $db->query("DELETE FROM editori WHERE id = {$id}");
    }
    foreach (array_reverse($created['generi']) as $id) {
        $db->query("DELETE FROM generi WHERE id = {$id}");
    }
    foreach ($created['utenti'] as $id) {
        $db->query("DELETE FROM utenti WHERE id = {$id}");
    }
}

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS" : "{$fail} FAILED, {$pass} passed") . "\n";
exit($fail === 0 ? 0 : 1);
