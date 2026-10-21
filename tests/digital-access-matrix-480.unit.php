<?php
declare(strict_types=1);

/**
 * Issue #480 — who may open a Digital Library file, as a matrix, through the real
 * ProtectedUploadController and the real database:
 *
 *   every kind of account (anonymous, reader, premium, borrower in each loan state, the
 *   individual grant, each custom role, staff, admin, a suspended account)
 *   × every visibility (public, users, staff, loan, and "Predefinita" under each default)
 *   × every way a file is opened: a PDF in the browser, a PDF download, an audiobook
 *     seek (Range), the player's HEAD, a JSON client, the app's signed link;
 *
 * then what the book page lists for each of them, the same answer for a file still in
 * the old public folder, revocation inside an open session, expired and revoked app
 * links, and the hardening of #480's review (fail-closed default, legacy URLs, off-site
 * links in OAI-PMH, malformed ranges, unknown file types, the migration lock, the index).
 *
 * Run:  php tests/digital-access-matrix-480.unit.php
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
    function url(string $path = '/'): string
    {
        return $path;
    }
}

use App\Support\DigitalAccess as Access;
use App\Support\DigitalStorage as Storage;

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
if ($dbUser === '' || $dbName === '') {
    echo "SKIP: no database credentials\n";
    exit(0);
}
$dbHost = getenv('E2E_DB_HOST') ?: '';
$db = $dbHost === '' && is_string($socket) && $socket !== '' && file_exists($socket)
    ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
    : new mysqli($dbHost ?: ($env['DB_HOST'] ?? '127.0.0.1'), $dbUser, $dbPass, $dbName, (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
$db->set_charset('utf8mb4');
require_once $root . '/storage/plugins/digital-library/DigitalLibraryPlugin.php';
\DigitalLibraryPlugin::ensureIndexTable($db);

$tag = 'M480' . bin2hex(random_bytes(3));
$settings = new \App\Models\SettingsRepository($db);
$saved = [];
foreach ([Access::DEFAULT_KEY, Access::SHOW_LOCKED_KEY, Access::SIGNING_KEY] as $key) {
    $saved[$key] = $settings->get(Access::CATEGORY, $key, null);
}
$bookIds = $userIds = $roleIds = $files = $scratch = [];
register_shutdown_function(static function () use ($db, &$bookIds, &$userIds, &$roleIds, &$files, &$scratch, $settings, $saved): void {
    foreach ($bookIds as $id) {
        $db->query('DELETE FROM prestiti WHERE libro_id = ' . (int) $id);
        $db->query('DELETE FROM digital_library_files WHERE libro_id = ' . (int) $id);
        $db->query('DELETE FROM libri WHERE id = ' . (int) $id);
    }
    foreach ($userIds as $id) {
        $db->query('DELETE FROM utenti WHERE id = ' . (int) $id);
    }
    foreach ($roleIds as $id) {
        $db->query('DELETE FROM ruoli WHERE id = ' . (int) $id);
    }
    foreach ($files as $file) {
        @unlink($file);
    }
    foreach ($scratch as $dir) {
        $remove = static function (string $d) use (&$remove): void {
            if (!is_dir($d) || is_link($d)) {
                @unlink($d);
                return;
            }
            @chmod($d, 0755);
            foreach (scandir($d) ?: [] as $e) {
                if ($e !== '.' && $e !== '..') {
                    @chmod($d . '/' . $e, 0644);
                    $remove($d . '/' . $e);
                }
            }
            @rmdir($d);
        };
        $remove($dir);
    }
    foreach ($saved as $key => $value) {
        if ($value === null) {
            $stmt = $db->prepare("DELETE FROM system_settings WHERE category = 'digital_library' AND setting_key = ?");
            $stmt->bind_param('s', $key);
            $stmt->execute();
        } else {
            $settings->set(Access::CATEGORY, $key, (string) $value);
        }
    }
    Access::forgetDefault();
});

// ── Fixtures ────────────────────────────────────────────────────────────────
$setDefault = static function (string $value) use ($settings): void {
    $settings->set(Access::CATEGORY, Access::DEFAULT_KEY, $value);
    Access::forgetDefault();
};
$setDefault('public');
$settings->set(Access::CATEGORY, Access::SHOW_LOCKED_KEY, '1');

$role = static function (array $permissions) use ($db, $tag, &$roleIds): int {
    $name = $tag . '-role-' . count($roleIds);
    (new \App\Models\CustomRoleRepository($db))->save([], ['nome' => $name, 'permissions' => $permissions]);
    $stmt = $db->prepare('SELECT id FROM ruoli WHERE nome = ?');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $id = (int) $stmt->get_result()->fetch_assoc()['id'];
    $stmt->close();
    $roleIds[] = $id;
    return $id;
};
$account = static function (string $type, string $state = 'attivo', ?int $roleId = null, int $grant = 0) use ($db, $tag, &$userIds): array {
    $n = count($userIds);
    $card = substr($tag . 'U' . $n, 0, 20);
    $email = strtolower($tag) . $n . '@example.invalid';
    $hash = password_hash('x', PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, ruolo_id, accesso_digitale) VALUES (?, \'T\', \'T\', ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('sssssii', $card, $email, $hash, $type, $state, $roleId, $grant);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    $userIds[] = $id;
    return ['id' => $id, 'tipo_utente' => $type];
};
$book = static function (array $attachments, array $extra = []) use ($db, $tag, &$bookIds): int {
    $fields = \App\Support\DigitalAttachments::applySubmission([], ['digital_attachments_present' => '1', 'digital_attachments' => $attachments]);
    $title = $tag . ' book ' . count($bookIds);
    $json = $extra['digital_attachments'] ?? $fields['digital_attachments'];
    $file = $extra['file_url'] ?? $fields['file_url'];
    $audio = $extra['audio_url'] ?? $fields['audio_url'];
    $stmt = $db->prepare('INSERT INTO libri (titolo, digital_attachments, file_url, audio_url, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
    $stmt->bind_param('ssss', $title, $json, $file, $audio);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    $bookIds[] = $id;
    return $id;
};
$loan = static function (int $bookId, int $userId, string $state, int $active = 1) use ($db): void {
    $stmt = $db->prepare('INSERT INTO prestiti (libro_id, utente_id, data_prestito, data_scadenza, stato, attivo) VALUES (?, ?, DATE_SUB(CURDATE(), INTERVAL 20 DAY), DATE_SUB(CURDATE(), INTERVAL 6 DAY), ?, ?)');
    $stmt->bind_param('iisi', $bookId, $userId, $state, $active);
    $stmt->execute();
    $stmt->close();
};

$dir = Storage::ensureDir(Storage::targetDir($root));
$name = static fn (string $v, string $ext): string => $tag . '_' . $v . '.' . $ext;
$body = [];
$put = static function (string $file, string $content) use ($dir, &$files, &$body): void {
    file_put_contents($dir . '/' . $file, $content);
    $files[] = $dir . '/' . $file;
    $body[$file] = $content;
};
$visibilities = ['public', 'users', 'staff', 'loan', 'inherit'];
$attachments = [];
foreach ($visibilities as $v) {
    $put($name($v, 'pdf'), '%PDF-1.4 ' . $v . ' ' . str_repeat('p', 2000));
    $put($name($v, 'wav'), 'RIFF' . $v . str_repeat("\x01\x02", 3000));
    $attachments[] = ['url' => '/uploads/digital/' . $name($v, 'pdf'), 'label' => 'PDF ' . $v, 'kind' => 'ebook', 'visibility' => $v];
    $attachments[] = ['url' => '/uploads/digital/' . $name($v, 'wav'), 'label' => 'Audio ' . $v, 'kind' => 'audio', 'visibility' => $v];
}
$put($name('unlisted', 'pdf'), '%PDF-1.4 uploaded, not saved on a record yet');
$bookId = $book($attachments);

$roles = [
    'files' => $role(['digital.access']),
    'catalog' => $role(['backend.access', 'catalog.manage']),
    'loans' => $role(['backend.access', 'loans.manage']),
    'archivist' => $role(['backend.access', 'catalog.manage', 'digital.manage', 'digital.access']),
    'manager' => $role(['backend.access', 'digital.manage']),
];
$viewers = [
    'anonymous' => null,
    'standard reader' => $account('standard'),
    'premium reader' => $account('premium'),
    'borrower (in corso)' => $account('standard'),
    'borrower (in ritardo)' => $account('premium'),
    'loan requested (pendente)' => $account('standard'),
    'loan to collect (da ritirare)' => $account('standard'),
    'loan returned' => $account('standard'),
    'reader with the individual grant' => $account('standard', 'attivo', null, 1),
    'role: open restricted files only' => $account('standard', 'attivo', $roles['files']),
    'role: cataloguer' => $account('standard', 'attivo', $roles['catalog']),
    'role: loan desk' => $account('premium', 'attivo', $roles['loans']),
    'role: archivist' => $account('standard', 'attivo', $roles['archivist']),
    'role: manages files (opens them too)' => $account('standard', 'attivo', $roles['manager']),
    'staff' => $account('staff'),
    'admin' => $account('admin'),
    'suspended reader' => $account('standard', 'sospeso'),
];
$loan($bookId, $viewers['borrower (in corso)']['id'], 'in_corso');
$loan($bookId, $viewers['borrower (in ritardo)']['id'], 'in_ritardo');
$loan($bookId, $viewers['loan requested (pendente)']['id'], 'pendente');
$loan($bookId, $viewers['loan to collect (da ritirare)']['id'], 'da_ritirare');
$loan($bookId, $viewers['loan returned']['id'], 'restituito', 0);

/** Who opens restricted files whatever the visibility. */
// "Manage digital attachments" includes opening them: whoever may set a file's visibility, or
// attach it to a public record (the most open record wins), could open it anyway.
$privileged = ['reader with the individual grant', 'role: open restricted files only', 'role: archivist', 'role: manages files (opens them too)', 'staff', 'admin'];
$borrowers = ['borrower (in corso)', 'borrower (in ritardo)'];
$signedOut = ['anonymous', 'suspended reader']; // a suspended account is no account: as anonymous
/** 'ok', 'login' (sent to sign in) or 'refused' for one viewer and one visibility. */
$expected = static function (string $who, string $visibility, string $default) use ($privileged, $borrowers, $signedOut): string {
    $resolved = $visibility === 'inherit' ? $default : $visibility;
    if ($resolved === 'public' || in_array($who, $privileged, true)) {
        return 'ok';
    }
    if (in_array($who, $signedOut, true)) {
        return $resolved === 'staff' ? 'refused' : 'login';
    }
    return match ($resolved) {
        'users' => 'ok',
        'loan' => in_array($who, $borrowers, true) ? 'ok' : 'refused',
        default => 'refused',
    };
};
$pageAccess = ['ok' => Access::GRANTED, 'login' => Access::LOGIN_REQUIRED];

$factory = new \Slim\Psr7\Factory\ServerRequestFactory();
$controller = new \App\Controllers\ProtectedUploadController($db);
$request = static function (string $file, array $headers = [], array $query = [], string $method = 'GET') use ($factory, $controller) {
    $req = $factory->createServerRequest($method, '/uploads/digital/' . $file)->withQueryParams($query);
    foreach ($headers as $h => $value) {
        $req = $req->withHeader($h, $value);
    }
    return $controller->serve($req, new \Slim\Psr7\Response(), ['path' => 'digital/' . $file]);
};
$as = static function (?array $who): void {
    if ($who === null) {
        unset($_SESSION);
        $_SESSION = [];
    } else {
        $_SESSION = ['user' => $who];
    }
};
/** One way of opening, judged against the expected outcome; '' when it behaved. */
$judge = static function ($res, string $outcome, string $file, string $way) use ($body): string {
    $status = $res->getStatusCode();
    $content = (string) $res->getBody();
    $size = strlen($body[$file]);
    $good = match ($outcome) {
        'refused' => $status === 403 && $content === '',
        'login' => $way === 'json' ? $status === 401 : ($status === 302 && str_contains($res->getHeaderLine('Location'), 'return_url=')),
        default => match ($way) {
            'browser', 'json' => $status === 200 && $content === $body[$file] && str_starts_with($res->getHeaderLine('Content-Disposition'), 'inline'),
            'download' => $status === 200 && $content === $body[$file] && str_starts_with($res->getHeaderLine('Content-Disposition'), 'attachment'),
            'seek' => $status === 206 && $content === substr($body[$file], 100, 50) && $res->getHeaderLine('Content-Range') === 'bytes 100-149/' . $size,
            'tail' => $status === 206 && $content === substr($body[$file], -64),
            // What a player sends on every seek forward. (string) rewinds the body first, as
            // Slim's ResponseEmitter does: the bytes must still start at 1000.
            'from' => $status === 206 && $content === substr($body[$file], 1000) && $res->getHeaderLine('Content-Range') === 'bytes 1000-' . ($size - 1) . '/' . $size,
            'head' => $status === 200 && $content === '' && $res->getHeaderLine('Content-Length') === (string) $size,
        },
    };
    return $good ? '' : "{$way} {$file}: {$status}";
};
$ways = [
    'browser' => [['Accept' => 'text/html'], [], 'GET'],
    'download' => [[], ['download' => '1'], 'GET'],
    'json' => [['Accept' => 'application/json'], [], 'GET'],
    'seek' => [['Range' => 'bytes=100-149'], [], 'GET'],
    'tail' => [['Range' => 'bytes=-64'], [], 'GET'],
    'from' => [['Range' => 'bytes=1000-'], [], 'GET'],
    'head' => [[], [], 'HEAD'],
];

// ── A. The matrix, under each library default ──────────────────────────────
foreach (['public', 'users', 'staff', 'loan'] as $default) {
    echo "A. Library default \"{$default}\": every account × every visibility × every way of opening\n";
    $setDefault($default);
    foreach ($viewers as $who => $viewer) {
        $problems = [];
        $count = 0;
        foreach ($visibilities as $v) {
            $outcome = $expected($who, $v, $default);
            foreach (['pdf', 'wav'] as $ext) {
                $file = $name($v, $ext);
                foreach ($ways as $way => [$headers, $query, $method]) {
                    if ($ext === 'pdf' && in_array($way, ['seek', 'tail', 'from'], true)) {
                        continue; // seeking is what a player does
                    }
                    $as($viewer);
                    $problem = $judge($request($file, $headers, $query, $method), $outcome, $file, $way);
                    $count++;
                    if ($problem !== '') {
                        $problems[] = "{$problem} (expected {$outcome})";
                    }
                }
            }
        }
        $check($problems === [], sprintf('%-46s %d requests as expected%s', $who, $count, $problems ? ': ' . implode('; ', array_slice($problems, 0, 4)) : ''));
    }

    // The book page: what each account sees listed, locked or nothing at all.
    $problems = [];
    $bookRow = $db->query('SELECT id, file_url, audio_url, digital_attachments FROM libri WHERE id = ' . $bookId)->fetch_assoc();
    foreach ($viewers as $who => $viewer) {
        $fresh = $viewer === null ? null : \App\Support\Permissions::viewer($viewer['id'], $db);
        foreach (Access::forViewer($bookRow, \App\Support\DigitalAttachments::fromBook($bookRow), $fresh, $db) as $row) {
            $v = $row['visibility'];
            $outcome = $expected($who, $v, $default);
            $resolved = $v === 'inherit' ? $default : $v;
            $want = $pageAccess[$outcome] ?? ($resolved === 'loan' ? Access::LOAN_REQUIRED : Access::STAFF_ONLY);
            if ($row['access'] !== $want) {
                $problems[] = "{$who}/{$row['label']}: {$row['access']} instead of {$want}";
            }
        }
    }
    $check($problems === [], 'the book page gives every account the same answer as the file route' . ($problems ? ': ' . implode('; ', array_slice($problems, 0, 4)) : ''));
}
$setDefault('public');

$stored = json_decode((string) $db->query('SELECT permessi FROM ruoli WHERE id = ' . $roles['manager'])->fetch_row()[0], true);
$check(in_array('digital.access', $stored, true), 'a role saved with "manage attachments" is stored with "open restricted attachments" too');

// ── B. The app's signed links behave as the account they name ──────────────
echo "B. Signed links (Mobile API)\n";
$problems = [];
foreach ($viewers as $who => $viewer) {
    if ($viewer === null) {
        continue;
    }
    foreach ($visibilities as $v) {
        foreach (['pdf', 'wav'] as $ext) {
            $file = $name($v, $ext);
            parse_str((string) parse_url(Access::signedUrl('/uploads/digital/' . $file, $viewer['id'], $db), PHP_URL_QUERY), $sq);
            $as(null);
            $status = $request($file, ['Range' => 'bytes=0-9'], $sq)->getStatusCode();
            $outcome = $expected($who, $v, 'public');
            // Without a session there is no login page to go to: a link that does not open is
            // refused. A suspended account's link is dead: it opens nothing, public files included.
            $want = $outcome === 'ok' && !in_array($who, $signedOut, true) ? 206 : 403;
            if ($status !== $want) {
                $problems[] = "{$who}/{$file}: {$status} instead of {$want}";
            }
        }
    }
}
$check($problems === [], 'a signed link opens exactly what its account may open, nothing else' . ($problems ? ': ' . implode('; ', array_slice($problems, 0, 4)) : ''));

$admin = $viewers['admin'];
$reader = $viewers['standard reader'];
$staffFile = $name('staff', 'pdf');
parse_str((string) parse_url(Access::signedUrl('/uploads/digital/' . $staffFile, $admin['id'], $db, time() - 3 * 86400), PHP_URL_QUERY), $expired);
$as(null);
$check($request($staffFile, [], $expired)->getStatusCode() === 403, 'an expired signed link is refused without a session');
$as($admin);
$check($request($staffFile, [], $expired)->getStatusCode() === 200, 'an expired signed link does not lock out an administrator signed in on the same browser');
$as($reader);
$check($request($staffFile, [], $expired)->getStatusCode() === 403, 'nor does it open the file to a signed-in reader who may not open it');
parse_str((string) parse_url(Access::signedUrl('/uploads/digital/' . $name('users', 'pdf'), $reader['id'], $db), PHP_URL_QUERY), $readerLink);
parse_str((string) parse_url(Access::signedUrl('/uploads/digital/' . $staffFile, $admin['id'], $db), PHP_URL_QUERY), $adminLink);
$as(null);
$check($request($name('loan', 'pdf'), [], $readerLink)->getStatusCode() === 403, 'a signed link opens only the file it was signed for');
// Private mode lets any request carrying pk_sig reach the file route: a dead link must not
// turn into an anonymous visit, or a forged pk_sig would serve public files past it.
$check($request($name('public', 'pdf'), [], $readerLink)->getStatusCode() === 403, 'a link signed for another file opens nothing, not even a public one');
$check($request($name('public', 'pdf'), [], ['pk_uid' => (string) $reader['id'], 'pk_exp' => (string) (time() + 3600), 'pk_sig' => 'forged'])->getStatusCode() === 403,
    'a forged pk_sig on a public file is refused to an anonymous visitor (private mode cannot be bypassed)');
$as($reader);
$check($request($name('public', 'pdf'), [], ['pk_sig' => 'forged'])->getStatusCode() === 200, 'a reader signed in on this browser is still judged as themselves');
$as(null);
$check($request($name('users', 'pdf'), [], $readerLink)->getStatusCode() === 200, 'before the revocation the reader link works');
$check((new \DigitalLibraryPlugin($db))->revokeSignedLinks(), '"Revoca tutti i link dell’app" replaces the signing key');
$check($request($name('users', 'pdf'), [], $readerLink)->getStatusCode() === 403 && $request($staffFile, [], $adminLink)->getStatusCode() === 403,
    'after it, every link handed out before is refused');
parse_str((string) parse_url(Access::signedUrl('/uploads/digital/' . $name('users', 'pdf'), $reader['id'], $db), PHP_URL_QUERY), $fresh);
$check($request($name('users', 'pdf'), [], $fresh)->getStatusCode() === 200, 'a link signed after the revocation works');

// ── C. Revocation takes effect on the next request of an open session ──────
echo "C. Revocation inside an open session\n";
$grant = $viewers['reader with the individual grant'];
$as($grant);
$check($request($staffFile)->getStatusCode() === 200, 'the individual grant opens a staff-only file');
$db->query('UPDATE utenti SET accesso_digitale = 0 WHERE id = ' . $grant['id']);
$check($request($staffFile)->getStatusCode() === 403, 'withdrawing the grant refuses the very next request, same session');
parse_str((string) parse_url(Access::signedUrl('/uploads/digital/' . $staffFile, $grant['id'], $db), PHP_URL_QUERY), $grantLink);
$as(null);
$check($request($staffFile, [], $grantLink)->getStatusCode() === 403, 'and the app link of that reader');

$filesRole = $viewers['role: open restricted files only'];
$as($filesRole);
$check($request($name('loan', 'wav'), ['Range' => 'bytes=0-9'])->getStatusCode() === 206, 'a role with "open restricted attachments" plays the loan-only audiobook');
$db->query("UPDATE ruoli SET permessi = '[]' WHERE id = " . $roles['files']);
$check($request($name('loan', 'wav'), ['Range' => 'bytes=10-19'])->getStatusCode() === 403, 'emptying the role stops the next seek of the same player');

$borrower = $viewers['borrower (in corso)'];
$as($borrower);
$check($request($name('loan', 'wav'), ['Range' => 'bytes=0-9'])->getStatusCode() === 206, 'the borrower plays the loan-only audiobook');
$db->query("UPDATE prestiti SET stato = 'restituito', attivo = 0 WHERE libro_id = {$bookId} AND utente_id = " . $borrower['id']);
$check($request($name('loan', 'wav'), ['Range' => 'bytes=10-19'])->getStatusCode() === 403, 'once the book is returned the next seek is refused');
$check($request($name('users', 'wav'), ['Range' => 'bytes=0-9'])->getStatusCode() === 206, 'files for signed-in readers still play');

$as($reader);
$db->query("UPDATE utenti SET stato = 'sospeso' WHERE id = " . $reader['id']);
$check($request($name('users', 'pdf'), ['Accept' => 'text/html'])->getStatusCode() === 302, 'suspending a reader sends their open session to the login page');
$db->query("UPDATE utenti SET stato = 'attivo' WHERE id = " . $reader['id']);
parse_str((string) parse_url(Access::signedUrl('/uploads/digital/' . $name('users', 'wav'), $reader['id'], $db), PHP_URL_QUERY), $beforePassword);
$as(null);
$check($request($name('users', 'wav'), ['Range' => 'bytes=0-9'], $beforePassword)->getStatusCode() === 206, 'an app link works before the password changes');
$newHash = password_hash('changed after a lost phone', PASSWORD_DEFAULT);
$stmt = $db->prepare('UPDATE utenti SET password = ? WHERE id = ?');
$stmt->bind_param('si', $newHash, $reader['id']);
$stmt->execute();
$stmt->close();
$check($request($name('users', 'wav'), ['Range' => 'bytes=0-9'], $beforePassword)->getStatusCode() === 403, 'changing the password ends every app link of that account at once');
$db->query("UPDATE utenti SET stato = 'sospeso' WHERE id = " . $reader['id']);

// ── D. Files nobody has listed yet, and the old public folder ──────────────
echo "D. Unlisted files and the old folder\n";
$unlisted = $name('unlisted', 'pdf');
foreach ($viewers as $who => $viewer) {
    $as($viewer);
    $want = in_array($who, ['staff', 'admin', 'role: archivist', 'role: manages files (opens them too)'], true) ? 200 : 403;
    $check($request($unlisted)->getStatusCode() === $want, "unlisted file, {$who}: {$want}");
}
// A file still waiting in public/uploads/digital answers exactly like one already moved.
$legacyDir = Storage::legacyDir($root);
$legacyExisted = is_dir($legacyDir);
@mkdir($legacyDir, 0775, true);
$legacyName = $tag . '_legacy.pdf';
file_put_contents($legacyDir . '/' . $legacyName, '%PDF-1.4 still in the old folder');
$files[] = $legacyDir . '/' . $legacyName;
$files[] = $dir . '/' . $legacyName;
$legacyBook = $book([['url' => '/uploads/digital/' . $legacyName, 'label' => 'Old', 'kind' => 'ebook', 'visibility' => 'users']]);
$as(null);
$check($request($legacyName, ['Accept' => 'text/html'])->getStatusCode() === 302, 'a signed-in-only file still in the old folder sends an anonymous visitor to sign in');
$as($viewers['premium reader']);
$res = $request($legacyName);
$check($res->getStatusCode() === 200 && (string) $res->getBody() === '%PDF-1.4 still in the old folder', 'and opens for a signed-in reader, from wherever it is');
if (!$legacyExisted) {
    $scratch[] = $legacyDir;
}

// ── E. Hardening from the review ────────────────────────────────────────────
echo "E. Hardening\n";
$settings->set(Access::CATEGORY, Access::DEFAULT_KEY, 'everyone');
Access::forgetDefault();
$check(Access::defaultVisibility($db) === 'staff', 'a default that cannot be understood closes the files instead of opening them');
$db->query("DELETE FROM system_settings WHERE category = 'digital_library' AND setting_key = 'default_visibility'");
Access::forgetDefault();
$check(Access::defaultVisibility($db) === 'public', 'a library that never chose keeps the historical "public"');
$setDefault('users');
// Older versions wrote the column as free text: no leading slash.
$legacyText = $book([], ['digital_attachments' => null, 'file_url' => 'uploads/digital/' . $name('inherit', 'pdf'), 'audio_url' => null]);
$check(Access::anonymousUrl('uploads/digital/' . $name('inherit', 'pdf'), $db, $legacyText, true) === null,
    'public API: a legacy "uploads/…" value of a closed file is left out');
// Off-site links follow their record: OAI-PMH passes the record now.
$offsite = $book([['url' => 'https://cdn.example.org/' . $tag . '.pdf', 'label' => 'CDN', 'kind' => 'ebook', 'visibility' => 'staff']]);
$check(Access::anonymousUrl('https://cdn.example.org/' . $tag . '.pdf', $db, $offsite) === null, 'an off-site staff-only link is not given to anonymous consumers');
$check(Access::anonymousUrl('https://cdn.example.org/' . $tag . '-other.pdf', $db, $offsite) === 'https://cdn.example.org/' . $tag . '-other.pdf',
    'a URL the record does not describe (a MAG digital asset) is passed on');
$check(Access::anonymousUrl('javascript:alert(1)', $db, $offsite, true) === null, 'a record value the attachment list rejected is never passed on');
require_once $root . '/storage/plugins/oai-pmh-server/wrapper.php';
$oai = new \App\Plugins\OaiPmhServer\OaiPmhServerPlugin($db, new \App\Support\HookManager($db));
$oaiHost = parse_url((new ReflectionMethod($oai, 'oaiBaseUrl'))->invoke($oai), PHP_URL_HOST) ?: 'localhost';
$xml = (string) $oai->oaiPmhAction($factory->createServerRequest('GET', '/oai')->withQueryParams(
    ['verb' => 'GetRecord', 'metadataPrefix' => 'mag', 'identifier' => "oai:{$oaiHost}:book:{$offsite}"]), new \Slim\Psr7\Response())->getBody();
$check(str_contains($xml, '<metadigit') && !str_contains($xml, 'cdn.example.org'), 'OAI-PMH MAG: the off-site staff-only file is left out');
$setDefault('public');

// URL matching must agree with the attachment parser, on a cold index and after a
// second record changes the answer. These are valid stored forms, not new file names.
$storedForms = [
    'relative legacy file' => static fn (string $url): array => ['file_url' => ltrim($url, '/'), 'digital_attachments' => 'null'],
    'relative legacy audio' => static fn (string $url): array => ['audio_url' => ltrim($url, '/'), 'digital_attachments' => 'null'],
    'percent-encoded ASCII' => static fn (string $url): array => ['file_url' => str_replace(['_', '.pdf'], ['%5f', '%2Epdf'], $url), 'digital_attachments' => 'null'],
    'JSON escaped slashes' => static fn (string $url): array => ['digital_attachments' => json_encode([['url' => $url, 'kind' => 'supplement', 'visibility' => 'inherit']], JSON_THROW_ON_ERROR)],
    'JSON Unicode escapes' => static fn (string $url): array => ['digital_attachments' => str_replace('uploads', '\\u0075ploads', json_encode([['url' => $url, 'kind' => 'supplement', 'visibility' => 'inherit']], JSON_THROW_ON_ERROR))],
];
$spellingReader = $viewers['premium reader'];
$spellingGrant = $account('standard', 'attivo', null, 1);
foreach ($storedForms as $label => $storedForm) {
    foreach (['public', 'users', 'staff', 'loan'] as $visibility) {
        $setDefault($visibility);
        $file = $name('spelling_' . count($bookIds), 'pdf');
        $put($file, '%PDF-1.4 ' . $label . ' ' . $visibility);
        $id = $book([], $storedForm('/uploads/digital/' . $file));
        if ($visibility === 'loan') { $loan($id, $spellingReader['id'], 'in_corso'); }
        $as(null);
        $res = $request($file);
        $want = $visibility === 'public' ? 200 : ($visibility === 'staff' ? 403 : 302);
        $check($res->getStatusCode() === $want && ($want !== 200 || (string) $res->getBody() === $body[$file]),
            "{$label}, {$visibility}: anonymous download follows the stored visibility ({$want})");
        $as($spellingReader);
        $res = $request($file);
        $want = $visibility === 'staff' ? 403 : 200;
        $check($res->getStatusCode() === $want && ($want !== 200 || (string) $res->getBody() === $body[$file]),
            "{$label}, {$visibility}: a reader/borrower opens exactly the files they may ({$want})");
        $as($spellingGrant);
        $res = $request($file);
        $check($res->getStatusCode() === 200 && (string) $res->getBody() === $body[$file],
            "{$label}, {$visibility}: the individual grant opens the listed file without backend access");
    }
    $setDefault('public');
    $file = $name('warm_spelling_' . count($bookIds), 'pdf');
    $put($file, '%PDF-1.4 warm index ' . $label);
    $id = $book([['url' => '/uploads/digital/' . $file, 'kind' => 'ebook', 'visibility' => 'staff']]);
    $as(null);
    $check($request($file)->getStatusCode() === 403, "{$label}: the first record starts staff-only");
    // Make the first record older than the cached verification. Only the newly
    // attached record should invalidate it, regardless of its stored URL spelling.
    $escapedFile = $db->real_escape_string($file);
    $db->query("UPDATE libri SET updated_at = DATE_SUB(NOW(), INTERVAL 20 SECOND) WHERE id = {$id}");
    $db->query("UPDATE digital_library_files SET verified_at = UNIX_TIMESTAMP() - 10 WHERE file_name = '{$escapedFile}'");
    $book([], $storedForm('/uploads/digital/' . $file));
    $res = $request($file);
    $check($res->getStatusCode() === 200 && (string) $res->getBody() === $body[$file],
        "{$label}: a newly attached public record invalidates the existing index immediately");
}
$setDefault('public');
// A broader SQL prefilter must never substitute for the exact, local-file comparison.
$file = $name('unlisted_spelling', 'pdf');
$put($file, '%PDF-1.4 not listed');
$book([['url' => '/uploads/digital/' . $file . '.other', 'kind' => 'supplement', 'visibility' => 'public']]);
$book([['url' => 'https://cdn.example.invalid/uploads/digital/' . $file, 'kind' => 'supplement', 'visibility' => 'public']]);
$as(null);
$check($request($file)->getStatusCode() === 403, 'similar names and off-site URLs never grant access to an unlisted local file');
// Percent-encoded off-site links are common (archive.org, %20); they must not make
// every such record a candidate that each lookup decodes.
$filter = (string) (new ReflectionClassConstant(Access::class, 'FILE_CANDIDATE_FILTER'))->getValue();
$offsite = $book([], ['file_url' => 'https://archive.example.invalid/a%20b.pdf', 'audio_url' => 'https://archive.example.invalid/c%20d.mp3',
    'digital_attachments' => json_encode([['url' => 'https://archive.example.invalid/e%20f.pdf', 'kind' => 'supplement', 'visibility' => 'public']], JSON_THROW_ON_ERROR)]);
$encoded = $book([], ['file_url' => '/uploads/digital/%41' . $tag . '.pdf', 'digital_attachments' => 'null']);
$escaped = $book([], ['digital_attachments' => json_encode([['url' => '/uploads/digital/%41' . $tag . '.pdf', 'kind' => 'supplement', 'visibility' => 'public']], JSON_THROW_ON_ERROR)]);
$noMatch = '%' . $tag . '_no_such_name%';
$stmt = $db->prepare("SELECT id FROM libri WHERE id IN ({$offsite}, {$encoded}, {$escaped}) AND {$filter} ORDER BY id");
$stmt->bind_param('sss', $noMatch, $noMatch, $noMatch);
$stmt->execute();
$candidates = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
$stmt->close();
$check($candidates === [$encoded, $escaped], 'percent-encoded off-site links are not scan candidates; encoded local ones still are');

// Ranges a player never sends are ignored, not answered with a wrong part.
$as($admin);
$audio = $name('public', 'wav');
$size = strlen($body[$audio]);
$res = $request($audio, ['Range' => 'bytes=-']);
$check($res->getStatusCode() === 200 && strlen((string) $res->getBody()) === $size, '"bytes=-" is not a range: the whole file');
$check($request($audio, ['Range' => 'bytes=50-20'])->getStatusCode() === 200, '"bytes=50-20" is not a range: the whole file');
$check($request($audio, ['Range' => 'bytes=' . ($size + 10) . '-'])->getStatusCode() === 416, 'a range past the end is 416');
$check($request($audio, ['Range' => 'bytes=0-9,20-29'])->getStatusCode() === 200, 'several ranges: the whole file');
// A type outside the list is never rendered on this origin.
$put($tag . '_page.html', '<script>alert(1)</script>');
$book([['url' => '/uploads/digital/' . $tag . '_page.html', 'label' => 'HTML', 'kind' => 'supplement', 'visibility' => 'public']]);
$as(null);
$res = $request($tag . '_page.html');
$check($res->getStatusCode() === 200 && $res->getHeaderLine('Content-Type') === 'application/octet-stream'
    && str_starts_with($res->getHeaderLine('Content-Disposition'), 'attachment'), 'an .html file is sent as an opaque download, never inline');

// The index of which records list a file is not thrown away by every loan in the catalogue.
$as($reader);
$db->query("UPDATE utenti SET stato = 'attivo' WHERE id = " . $reader['id']);
$indexed = $name('users', 'wav');
$request($indexed, ['Range' => 'bytes=0-9']);
$stamp = static fn (): string => (string) $db->query("SELECT GROUP_CONCAT(verified_at) FROM digital_library_files WHERE file_name = '" . $db->real_escape_string($indexed) . "'")->fetch_row()[0];
$before = $stamp();
$db->query("UPDATE libri SET file_url = REPLACE(file_url, '.pdf', '%2Epdf'), updated_at = DATE_ADD(NOW(), INTERVAL 5 SECOND) WHERE id = {$legacyBook}"); // a loan of another book, with an encoded URL
$check($request($indexed, ['Range' => 'bytes=10-19'])->getStatusCode() === 206 && $before !== '' && $stamp() === $before,
    'a loan of another book, even with an encoded URL, keeps the index: the next seek does not rescan the catalogue');
// A loan of this very book changes its record: one rescan, then the seeks read the index again.
$db->query("UPDATE libri SET copie_disponibili = copie_disponibili + 1 WHERE id = {$bookId}");
sleep(2);
$request($indexed, ['Range' => 'bytes=20-29']);
$after = $stamp();
$request($indexed, ['Range' => 'bytes=30-39']);
$request($indexed, ['Range' => 'bytes=40-49']);
$check($after !== $before && $stamp() === $after, 'a loan of the book itself costs one rescan, not one per seek');
// …but a record that newly lists the file does outdate it, and the more open answer wins.
$db->query('UPDATE ruoli SET permessi = \'["digital.access"]\' WHERE id = ' . $roles['files']);
$secondCopy = $book([['url' => '/uploads/digital/' . $staffFile, 'label' => 'Public copy', 'kind' => 'ebook', 'visibility' => 'public']]);
$db->query("UPDATE libri SET updated_at = DATE_ADD(NOW(), INTERVAL 6 SECOND) WHERE id = {$secondCopy}");
$as(null);
$check($request($staffFile)->getStatusCode() === 200, 'a second record listing the file as public is seen at once');

// The migration lock and the state files belong to whoever created them first.
$tmp = sys_get_temp_dir() . '/' . $tag . '-move';
$scratch[] = $tmp;
$legacy = $tmp . '/public/uploads/digital';
$target = $tmp . '/storage/digital';
mkdir($legacy . '/scores/2024', 0775, true);
Storage::ensureDir($target);
file_put_contents($legacy . '/top.pdf', 'top');
file_put_contents($legacy . '/scores/2024/part.pdf', 'part in a subfolder');
file_put_contents($legacy . '/scores/.hidden', 'never moved');
touch($target . '/.migration.lock');
chmod($target . '/.migration.lock', 0444); // created by another user: this one cannot write it
file_put_contents($target . '/' . Storage::MIGRATED_MARKER, "stale\n");
chmod($target . '/' . Storage::MIGRATED_MARKER, 0444);
$check(Storage::countFiles($legacy) === 2, 'files in subfolders of the old folder are counted (dotfiles are not)');
$result = Storage::migrate($legacy, $target, PHP_INT_MAX, 30.0);
$check($result === ['moved' => 2, 'remaining' => 0, 'failed' => 0], 'a lock file this user cannot write does not stop the move: ' . json_encode($result));
$check(is_file($target . '/scores/2024/part.pdf') && file_get_contents($target . '/scores/2024/part.pdf') === 'part in a subfolder'
    && !is_file($legacy . '/scores/2024/part.pdf'), 'a file in a subfolder moves to the same subfolder, out of the web root');
$check(is_file($legacy . '/scores/.hidden'), 'a dotfile in a subfolder stays');
$check(trim((string) file_get_contents($target . '/' . Storage::MIGRATED_MARKER)) !== 'stale' && Storage::migrationDone($legacy, $target),
    'a marker this user cannot write is replaced, so the move is recorded as done');

// A file added later to an existing subfolder reopens the move (the subfolder's mtime counts).
sleep(2); // the marker trusts a folder stamp only once it is two seconds old
Storage::migrate($legacy, $target, PHP_INT_MAX, 30.0);
$check(Storage::migrationDone($legacy, $target), 'after a complete move the old folder is recorded as done');
sleep(1);
file_put_contents($legacy . '/scores/2024/late-part.pdf', 'arrived in a subfolder later');
$check(!Storage::migrationDone($legacy, $target), 'a file added to an existing subfolder reopens the move');
Storage::migrate($legacy, $target, PHP_INT_MAX, 30.0);
$check(is_file($target . '/scores/2024/late-part.pdf') && !is_file($legacy . '/scores/2024/late-part.pdf'), 'and is moved by the next run');
$asRoot = function_exists('posix_geteuid') && posix_geteuid() === 0; // root reads whatever the mode
if (!$asRoot) {
    // A subfolder restored with another owner cannot be read: no exception on every page.
    mkdir($legacy . '/locked', 0700);
    file_put_contents($legacy . '/locked/hidden.pdf', 'unreadable');
    chmod($legacy . '/locked', 0000);
    $threw = null;
    try {
        $done = Storage::migrationDone($legacy, $target);
        $count = Storage::countFiles($legacy);
        $result = Storage::migrate($legacy, $target, PHP_INT_MAX, 30.0);
    } catch (Throwable $e) {
        $threw = $e;
    }
    chmod($legacy . '/locked', 0700);
    $check($threw === null, 'an unreadable subfolder does not throw from migrationDone/countFiles/migrate' . ($threw ? ': ' . $threw->getMessage() : ''));
    $check(is_file($legacy . '/locked/hidden.pdf'), 'and its files stay where they are, untouched');
    // Two hashes that cannot be computed are not "the same bytes": the original stays.
    file_put_contents($legacy . '/clash.pdf', 'the original');
    file_put_contents($target . '/clash.pdf', 'the original');
    chmod($target . '/clash.pdf', 0000);
    chmod($legacy . '/clash.pdf', 0000);
    Storage::migrate($legacy, $target, PHP_INT_MAX, 30.0);
    chmod($legacy . '/clash.pdf', 0644);
    chmod($target . '/clash.pdf', 0644);
    $check(is_file($legacy . '/clash.pdf') && file_get_contents($legacy . '/clash.pdf') === 'the original',
        'an original whose bytes could not be compared is never deleted');
}
// An old folder that is storage/digital itself (a symlink) never deletes the only copy.
$aliasRoot = $tmp . '/alias';
mkdir($aliasRoot . '/storage', 0775, true);
$aliasTarget = Storage::ensureDir($aliasRoot . '/storage/digital');
file_put_contents($aliasTarget . '/only-copy.pdf', 'the only copy');
@mkdir($aliasRoot . '/public/uploads', 0775, true);
symlink($aliasTarget, $aliasRoot . '/public/uploads/digital');
$result = Storage::migrate($aliasRoot . '/public/uploads/digital', $aliasTarget, PHP_INT_MAX, 30.0);
$check(is_file($aliasTarget . '/only-copy.pdf') && file_get_contents($aliasTarget . '/only-copy.pdf') === 'the only copy' && $result['moved'] === 0,
    'an old folder that is an alias of storage/digital moves nothing and deletes nothing');

// The single-file columns, written in another form or by another writer, never reopen a file.
$setDefault('public');
$staffOnly = '/uploads/digital/' . $name('staff', 'pdf');
$row = ['id' => 0, 'digital_attachments' => json_encode([['url' => $staffOnly, 'label' => 'S', 'kind' => 'ebook', 'visibility' => 'staff']]), 'audio_url' => ''];
foreach (['uploads/digital/' . $name('staff', 'pdf') => 'without the leading slash', \App\Support\HtmlHelper::absoluteUrl($staffOnly) => 'as an absolute URL of this site'] as $spelling => $how) {
    $rows = \App\Support\DigitalAttachments::fromBook($row + ['file_url' => $spelling]);
    $check(count($rows) === 1 && $rows[0]['visibility'] === 'staff', "the same staff-only file in file_url {$how} keeps its visibility");
}
$rows = \App\Support\DigitalAttachments::fromBook($row + ['file_url' => '/uploads/digital/' . $name('public', 'pdf')]);
$check(count($rows) === 1 && $rows[0]['visibility'] === 'staff' && str_contains($rows[0]['url'], $name('public', 'pdf')),
    'a file_url changed by another writer takes the slot with the slot\'s visibility, never "inherit"');

// public/.htaccess of an upgraded installation gets the 0.8.6 lines, once, with a backup.
$ht = $tmp . '/htaccess';
$shipped = (string) file_get_contents($root . '/public/.htaccess');
$old = str_replace(['[NC,QSA,L]', 'epub|pdf|mp4|webm|mp3|m4a|ogg|wav)'], ['[QSA,L]', 'epub|mp4|webm|mp3|m4a|ogg)'], $shipped);
file_put_contents($ht, $old);
chmod($ht, 0640);
$check(\App\Support\PrivateUploadsRewriteHeal::heal($ht, $tmp . '/backups') && file_get_contents($ht) === $shipped, 'an upgraded .htaccess gets [NC] and the no-gzip types');
$check((fileperms($ht) & 0777) === 0640 && count(glob($tmp . '/backups/htaccess.bak-480-*') ?: []) === 1, 'its mode is kept and a backup is made, outside the web root');
$check(\App\Support\PrivateUploadsRewriteHeal::heal($ht, $tmp . '/backups') && count(glob($tmp . '/backups/htaccess.bak-480-*') ?: []) === 1, 'a second upgrade changes nothing');
$own = str_replace('index.php [QSA,L]', 'index.php [QSA,L,E=MINE:1]', $old);
file_put_contents($ht, $own);
$check(\App\Support\PrivateUploadsRewriteHeal::heal($ht, $tmp . '/backups') && str_contains((string) file_get_contents($ht), '[QSA,L,E=MINE:1]'), 'a rule the administrator edited is left alone');

unset($_SESSION);
echo "\n" . ($fail === 0 ? "ALL {$pass} PASS" : "{$fail} FAILED, {$pass} passed") . "\n";
exit($fail > 0 ? 1 : 0);
