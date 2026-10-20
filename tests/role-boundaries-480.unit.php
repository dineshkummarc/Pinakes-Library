<?php
declare(strict_types=1);

/**
 * Issue #480 — where a custom role stops, through the real controllers and middleware:
 *
 *   - settings delegation covers the ordinary tabs; the advanced tab (custom JS/CSS,
 *     caches), mail transport and templates, and API keys stay with administrators;
 *   - only administrators create, delete, promote or demote administrators, and only
 *     administrators delete accounts at all;
 *   - Staff reads every reader's record again (details), a users manager only the
 *     accounts it may edit;
 *   - every route a preset needs is mapped to its permission, circulation views nested
 *     under catalogue paths follow the loan desk, and the method of a request counts;
 *   - a damaged role row never takes the role pages down.
 *
 * Run:  php tests/role-boundaries-480.unit.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/storage/plugins/mobile-api/wrapper.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

use App\Support\Permissions as P;
use App\Support\SessionRoleRevalidator as R;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

$env = Dotenv\Dotenv::parse((string) @file_get_contents(dirname(__DIR__) . '/.env'));
$setting = static function (string $key, string $default = '') use ($env): string {
    $process = getenv('E2E_' . $key);
    if ($process === false || $process === '') {
        $process = getenv($key);
    }
    return $process === false || $process === '' ? ($env[$key] ?? $default) : $process;
};
if ($setting('DB_USER') === '' || $setting('DB_NAME') === '') {
    echo "SKIP: no database credentials\n";
    exit(0);
}
$socket = $setting('DB_SOCKET');
$host = $setting('DB_HOST', '127.0.0.1');
$db = ($host === 'localhost' && $socket !== '' && file_exists($socket))
    ? new mysqli(null, $setting('DB_USER'), $setting('DB_PASS'), $setting('DB_NAME'), 0, $socket)
    : new mysqli($host === 'localhost' ? '127.0.0.1' : $host, $setting('DB_USER'), $setting('DB_PASS'), $setting('DB_NAME'), (int) $setting('DB_PORT', '3306'));
$db->set_charset('utf8mb4');

$tag = 'rb480-' . bin2hex(random_bytes(4));
$users = $roles = [];
$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};
$repo = new App\Models\CustomRoleRepository($db);
$addRole = static function (array $permissions) use ($tag, &$roles, $repo, $db): int {
    $name = $tag . '-' . count($roles);
    $repo->save([], ['nome' => $name, 'permissions' => $permissions]);
    $id = (int) $db->query("SELECT id FROM ruoli WHERE nome = '" . $db->real_escape_string($name) . "'")->fetch_row()[0];
    $roles[] = $id;
    return $id;
};
$addUser = static function (string $type, ?int $roleId = null) use ($db, $tag, &$users): int {
    $code = substr($tag . count($users), 0, 20);
    $email = $code . '@example.test';
    $stmt = $db->prepare("INSERT INTO utenti (nome, cognome, email, password, codice_tessera, tipo_utente, stato, email_verificata, ruolo_id) VALUES ('Test', 'Roles', ?, 'unused', ?, ?, 'attivo', 1, ?)");
    $stmt->bind_param('sssi', $email, $code, $type, $roleId);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    $users[] = $id;
    return $id;
};
$as = static function (int $id) use ($db): void {
    $_SESSION = ['user' => P::viewer($id, $db)];
    R::reset();
    R::resolve($db); // the request's re-validation, on this test's connection
};
$factory = new ServerRequestFactory();
$post = static fn (string $path, array $body = []) => $factory->createServerRequest('POST', $path)->withParsedBody($body);
$get = static fn (string $path) => $factory->createServerRequest('GET', $path);
$handler = new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(204);
    }
};
$value = static function (string $category, string $key) use ($db): ?string {
    $stmt = $db->prepare('SELECT setting_value FROM system_settings WHERE category = ? AND setting_key = ?');
    $stmt->bind_param('ss', $category, $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    return $row === null ? null : (string) $row[0];
};

$savedJs = $value('advanced', 'custom_js_essential');
$savedHost = $value('email', 'smtp_host');
// A refusal that regresses saves the whole tab, not only the probed key: keep both
// categories to put them back as they were.
$guardedSettings = $db->query("SELECT category, setting_key, setting_value, description FROM system_settings WHERE category IN ('advanced', 'email')")->fetch_all(MYSQLI_ASSOC);
try {
    $settingsRole = $addRole(['backend.access', 'settings.manage']);
    $usersRole = $addRole(['backend.access', 'users.manage']);
    $catalogRole = $addRole(['backend.access', 'catalog.manage']);
    $loansRole = $addRole(['backend.access', 'loans.manage']);
    $archivistRole = $addRole(['backend.access', 'catalog.manage', 'digital.manage', 'digital.access']);
    $admin = $addUser('admin');
    $staff = $addUser('staff');
    $reader = $addUser('standard');
    $settingsUser = $addUser('standard', $settingsRole);
    $usersUser = $addUser('premium', $usersRole);
    $catalogUser = $addUser('standard', $catalogRole);
    $loansUser = $addUser('standard', $loansRole);
    $archivist = $addUser('standard', $archivistRole);

    // ── 1. Settings: what a settings role may and may not change ──────────────
    echo "1. Settings delegation\n";
    $settings = new App\Controllers\SettingsController();
    $as($settingsUser);
    $check(P::mayConfigure(), 'a settings role reaches the delegated settings');
    $apiKeys = (int) $db->query('SELECT COUNT(*) FROM api_keys')->fetch_row()[0];
    $refused = [
        'advanced tab (custom JS on every public page)' => fn () => $settings->updateAdvancedSettings($post('/admin/settings/advanced', ['custom_js_essential' => '<script>steal()</script>']), new Response(), $db),
        'mail transport (SMTP host)' => fn () => $settings->updateEmailSettings($post('/admin/settings/email', ['mail_driver' => 'smtp', 'smtp_host' => 'evil.example']), new Response(), $db),
        'mail templates' => fn () => $settings->updateEmailTemplate($post('/admin/settings/templates/registration', ['subject' => 'x', 'body' => 'x']), new Response(), $db, 'registration'),
        'cache flush' => fn () => $settings->flushAllCaches($post('/admin/settings/cache/flush'), new Response(), $db),
        'public API switch' => fn () => $settings->toggleApi($post('/admin/settings/api/toggle', ['enabled' => '1']), new Response(), $db),
        'new API key' => fn () => $settings->createApiKey($post('/admin/settings/api/keys', ['name' => $tag]), new Response(), $db),
    ];
    foreach ($refused as $what => $call) {
        unset($_SESSION['error_message']);
        $call();
        $check(($_SESSION['error_message'] ?? '') !== '', "{$what}: refused to a settings role");
    }
    $check($value('advanced', 'custom_js_essential') === $savedJs, 'no custom JavaScript was stored');
    $check($value('email', 'smtp_host') === $savedHost, 'the SMTP host was not changed');
    $check((int) $db->query('SELECT COUNT(*) FROM api_keys')->fetch_row()[0] === $apiKeys, 'no API key was created');
    $test = json_decode((string) $settings->sendTestEmail($post('/admin/settings/email/test', ['to' => 'x@example.test']), new Response(), $db)->getBody(), true);
    $check(($test['success'] ?? true) === false, 'the test e-mail (stored SMTP credentials) is refused to a settings role');
    // The delegated save must take effect, not merely avoid one wording of the refusal.
    $bannerRows = $db->query("SELECT category, setting_key, setting_value FROM system_settings WHERE category IN ('cookie_banner', 'privacy')")->fetch_all(MYSQLI_ASSOC);
    $before = $value('cookie_banner', 'show_analytics');
    $flipped = $before === '1' ? '0' : '1';
    try {
        $settings->updateCookieBannerSettings($post('/admin/settings/privacy/cookie-banner', ['show_analytics' => $flipped]), new Response(), $db);
        $check($value('cookie_banner', 'show_analytics') === $flipped, 'the cookie banner stays delegated to a settings role: its save takes effect');
    } finally {
        $db->query("DELETE FROM system_settings WHERE category IN ('cookie_banner', 'privacy')");
        $restore = $db->prepare('INSERT INTO system_settings (category, setting_key, setting_value) VALUES (?, ?, ?)');
        foreach ($bannerRows as $row) {
            $restore->bind_param('sss', $row['category'], $row['setting_key'], $row['setting_value']);
            $restore->execute();
        }
        $restore->close();
        \App\Support\ConfigStore::clearCache();
    }
    $as($staff);
    $check(!P::mayConfigure(), 'Staff keeps its narrower historical settings rights');
    unset($_SESSION['error_message']);
    $settings->updateAdvancedSettings($post('/admin/settings/advanced', ['custom_js_essential' => 'x']), new Response(), $db);
    $check(($_SESSION['error_message'] ?? '') !== '' && $value('advanced', 'custom_js_essential') === $savedJs, 'Staff cannot store custom JavaScript either');

    // ── 2. Accounts: administrators only for administrators and for deletion ──
    echo "2. Accounts\n";
    $controller = new App\Controllers\UsersController();
    $victim = $addUser('standard');
    foreach ([[$staff, 'Staff'], [$usersUser, 'a users manager'], [$settingsUser, 'a settings role']] as [$who, $label]) {
        $as($who);
        $status = $controller->delete($post('/admin/users/delete/' . $victim), new Response(), $db, $victim)->getStatusCode();
        $still = (int) $db->query('SELECT COUNT(*) FROM utenti WHERE id = ' . $victim)->fetch_row()[0];
        $check($status === 403 && $still === 1, "{$label} cannot delete an account");
    }
    $as($usersUser);
    $check($controller->delete($post('/admin/users/delete/' . $admin), new Response(), $db, $admin)->getStatusCode() === 403
        && (int) $db->query("SELECT COUNT(*) FROM utenti WHERE id = {$admin} AND tipo_utente = 'admin'")->fetch_row()[0] === 1,
        'nor an administrator');
    foreach ([[$staff, 'Staff'], [$usersUser, 'a users manager']] as [$who, $label]) {
        $as($who);
        $email = $tag . '-promo-' . $who . '@example.test';
        $controller->store($post('/admin/users/store', ['nome' => 'Esc', 'cognome' => 'Alation', 'email' => $email, 'password' => 'Str0ng-pass-480!', 'tipo_utente' => 'admin']), new Response(), $db);
        $row = $db->query("SELECT id, tipo_utente FROM utenti WHERE email = '" . $db->real_escape_string($email) . "'")->fetch_assoc();
        if (is_array($row)) {
            $users[] = (int) $row['id'];
        }
        $check(!is_array($row) || $row['tipo_utente'] !== 'admin', "{$label} cannot create an administrator");
    }
    $as($usersUser);
    $check($controller->update($post('/admin/users/update/' . $admin, ['nome' => 'X', 'cognome' => 'Y', 'email' => 'x@example.test', 'tipo_utente' => 'standard']), new Response(), $db, $admin)->getStatusCode() === 403
        && (int) $db->query("SELECT COUNT(*) FROM utenti WHERE id = {$admin} AND tipo_utente = 'admin'")->fetch_row()[0] === 1,
        'a users manager cannot demote an administrator');
    $as($staff);
    $check($controller->update($post('/admin/users/update/' . $admin, ['nome' => 'X', 'cognome' => 'Y', 'email' => 'x@example.test', 'tipo_utente' => 'standard']), new Response(), $db, $admin)->getStatusCode() === 403,
        'nor can Staff');
    $controller->update($post('/admin/users/update/' . $staff, ['nome' => 'Self', 'cognome' => 'Promo', 'email' => $tag . '-self@example.test', 'tipo_utente' => 'admin']), new Response(), $db, $staff);
    $check((string) $db->query("SELECT tipo_utente FROM utenti WHERE id = {$staff}")->fetch_row()[0] === 'staff', 'Staff cannot promote itself to administrator');
    $as($admin);
    $check($controller->delete($post('/admin/users/delete/' . $victim), new Response(), $db, $victim)->getStatusCode() !== 403, 'an administrator deletes accounts');
    // Promoting an account with a custom role to Staff drops the role and the grant: they
    // would otherwise come back unnoticed if the account were demoted again.
    $promoted = $addUser('standard', $catalogRole);
    $db->query("UPDATE utenti SET accesso_digitale = 1 WHERE id = {$promoted}");
    $email = (string) $db->query("SELECT email FROM utenti WHERE id = {$promoted}")->fetch_row()[0];
    $controller->update($post('/admin/users/update/' . $promoted, ['nome' => 'Promo', 'cognome' => 'Ted', 'email' => $email, 'tipo_utente' => 'staff', 'stato' => 'attivo',
        // fields an installation may make required (Settings → Registration)
        'telefono' => '+39 0123 456789', 'indirizzo' => 'Via Roma 1, Torino', 'data_nascita' => '1990-05-17', 'sesso' => 'M',
        'permissions_present' => '1', 'ruolo_id' => (string) $catalogRole, 'accesso_digitale' => '1']), new Response(), $db, $promoted);
    $after = $db->query("SELECT tipo_utente, ruolo_id, accesso_digitale FROM utenti WHERE id = {$promoted}")->fetch_assoc();
    $check($after['tipo_utente'] === 'staff' && $after['ruolo_id'] === null && (int) $after['accesso_digitale'] === 0,
        'promoting to Staff clears the custom role and the digital grant: ' . json_encode($after));

    // The whole account list, operators included, leaves only to administrators and Staff.
    $api = new App\Controllers\UtentiApiController();
    foreach ([[$usersUser, 'a users manager', 403], [$settingsUser, 'a settings role', 403]] as [$who, $label, $want]) {
        $as($who);
        $check($controller->exportCsv($get('/admin/users/export/csv'), new Response(), $db)->getStatusCode() === $want
            && $api->exportPdf($get('/admin/users/export-pdf'), new Response(), $db)->getStatusCode() === $want,
            "{$label} cannot export every account (CSV, PDF)");
    }
    $as($staff);
    $check($controller->exportCsv($get('/admin/users/export/csv'), new Response(), $db)->getStatusCode() !== 403, 'Staff still exports the account list');

    // ── 3. Reading a record ────────────────────────────────────────────────────
    echo "3. Reader records\n";
    $as($staff);
    $check($controller->details($get('/admin/users/details/' . $reader), new Response(), $db, $reader)->getStatusCode() === 200, 'Staff opens a reader\'s record and loan history again');
    $as($usersUser);
    $check($controller->details($get('/admin/users/details/' . $reader), new Response(), $db, $reader)->getStatusCode() === 200, 'a users manager opens an ordinary reader');
    $check($controller->details($get('/admin/users/details/' . $staff), new Response(), $db, $staff)->getStatusCode() === 403, 'but not an operator');

    // Lists, counts and searches show a custom users manager only the readers it may edit:
    // no operator's email or phone through the users table, the pending box or a search.
    $as($usersUser);
    $listed = json_decode((string) (new App\Controllers\UtentiApiController())->list($get('/api/utenti?length=500&search_text=' . urlencode($tag)), new Response(), $db)->getBody(), true);
    $ids = array_map('intval', array_column($listed['data'] ?? [], 'id'));
    $check(in_array($reader, $ids, true) && !in_array($admin, $ids, true) && !in_array($staff, $ids, true) && !in_array($catalogUser, $ids, true),
        'the users list shows a users manager readers only, never admin, Staff or role holders');
    $found = json_decode((string) (new App\Controllers\SearchController())->users($get('/api/search/utenti?q=' . urlencode($tag)), new Response(), $db)->getBody(), true);
    $foundIds = array_map('intval', array_column($found, 'id'));
    $check(in_array($reader, $foundIds, true) && !in_array($admin, $foundIds, true) && !in_array($staff, $foundIds, true),
        'the user search finds readers only for a users manager');
    $as($loansUser);
    $found = json_decode((string) (new App\Controllers\SearchController())->users($get('/api/search/utenti?q=' . urlencode($tag)), new Response(), $db)->getBody(), true);
    $foundIds = array_map('intval', array_column($found, 'id'));
    $check(in_array($reader, $foundIds, true) && in_array($catalogUser, $foundIds, true) && !in_array($admin, $foundIds, true),
        'the loan desk picks any reader as borrower, role holders included, but no operator');
    // A front desk role holding both Users and Loans still finds every reader as borrower;
    // its users table keeps the manage scope.
    $desk = $addUser('standard', $addRole(['backend.access', 'users.manage', 'loans.manage']));
    $as($desk);
    $found = json_decode((string) (new App\Controllers\SearchController())->users($get('/api/search/utenti?q=' . urlencode($tag)), new Response(), $db)->getBody(), true);
    $check(in_array($catalogUser, array_map('intval', array_column($found, 'id')), true), 'a Users + Loans role still finds a role holder as borrower');
    $listed = json_decode((string) (new App\Controllers\UtentiApiController())->list($get('/api/utenti?length=500&search_text=' . urlencode($tag)), new Response(), $db)->getBody(), true);
    $check(!in_array($catalogUser, array_map('intval', array_column($listed['data'] ?? [], 'id')), true), 'but its users table shows only the readers it may edit');
    $as($staff);
    $listed = json_decode((string) (new App\Controllers\UtentiApiController())->list($get('/api/utenti?length=500&search_text=' . urlencode($tag)), new Response(), $db)->getBody(), true);
    $check(in_array($admin, array_map('intval', array_column($listed['data'] ?? [], 'id')), true), 'Staff still sees every account');

    // Uploading from the app writes the title's attachments: managing files alone is not enough.
    $filesOnly = $addUser('standard', $addRole(['backend.access', 'digital.manage']));
    $as($filesOnly);
    $digital = new App\Plugins\MobileApi\Controllers\StaffDigitalController($db, new App\Support\HookManager($db));
    $status = $digital->upload($factory->createServerRequest('POST', '/api/v1/staff/books/1/digital')->withParsedBody(['digital_type' => 'ebook']), new Response(), 1)->getStatusCode();
    $check($status === 403, 'a role that manages files but cannot catalogue cannot attach from the app (' . $status . ')');

    // ── 4. Routes ──────────────────────────────────────────────────────────────
    echo "4. Routes and permissions\n";
    $routes = [
        // [user, method, path, allowed]
        [$loansUser, 'GET', '/api/search/utenti', true],          // borrower picker of the loan form
        [$loansUser, 'GET', '/api/v1/staff/users/12', true],
        [$loansUser, 'GET', '/api/v1/staff/books/12', true],
        [$loansUser, 'POST', '/api/utenti', false],               // reading is not managing
        [$loansUser, 'GET', '/api/v1/staff/books/12/queue', true],
        [$loansUser, 'GET', '/api/v1/staff/copies/by-code', true],
        [$catalogUser, 'GET', '/api/v1/staff/books/12/queue', false], // names borrowers
        [$catalogUser, 'GET', '/api/v1/staff/copies/by-code', false],
        [$catalogUser, 'GET', '/api/v1/staff/circulation/summary', false],
        [$catalogUser, 'GET', '/api/v1/staff/books/12', true],
        [$catalogUser, 'GET', '/api/search/generi', true],
        [$catalogUser, 'POST', '/api/generi', true],
        [$catalogUser, 'POST', '/api/generi/4', true],
        [$catalogUser, 'GET', '/api/v1/staff/series', true],
        [$catalogUser, 'GET', '/api/v1/staff/works/3', true],
        [$catalogUser, 'GET', '/api/v1/staff/books/12/digital', false], // files are digital.manage
        [$archivist, 'GET', '/api/v1/staff/books/12/digital', true],
        [$archivist, 'GET', '/admin/archives', true],
        [$catalogUser, 'GET', '/api/search/utenti', false],
        [$catalogUser, 'GET', '/admin/notifications/unread-count', false],
        [$catalogUser, 'GET', '/admin/updates', false],
        [$catalogUser, 'GET', '/admin/plugins', false],
        [$usersUser, 'GET', '/api/search/utenti', true],
        [$settingsUser, 'GET', '/admin/settings', true],
        [$settingsUser, 'GET', '/admin/roles', false],
    ];
    foreach ($routes as [$who, $method, $path, $allowed]) {
        $as($who);
        $check(P::allowsPath($path, null, $method) === $allowed, sprintf('%s %s %s: %s', P::viewer($who, $db)['role_name'] ?? $who, $method, $path, $allowed ? 'allowed' : 'denied'));
    }
    // The global guard of plugin and admin API routes, and AuthMiddleware, decide the same way.
    foreach ([[$catalogUser, 'GET', '/admin/plugins/digital-library/upload', false], [$archivist, 'POST', '/admin/plugins/digital-library/upload', true],
              [$catalogUser, 'GET', '/admin/books', true], [$loansUser, 'GET', '/admin/books', false], [$catalogUser, 'GET', '/api/admin/pending-registrations', false],
              [$usersUser, 'GET', '/api/admin/pending-registrations', true]] as [$who, $method, $path, $allowed]) {
        $as($who);
        $status = (new App\Middleware\BackendPermissionsMiddleware($db))->process($factory->createServerRequest($method, $path), $handler)->getStatusCode();
        $check(($status === 204) === $allowed, "BackendPermissionsMiddleware {$method} {$path}: " . ($allowed ? 'passes' : 'stops'));
    }
    $as($loansUser);
    $auth = new App\Middleware\AuthMiddleware(['admin', 'staff'], $db);
    $check($auth->process($factory->createServerRequest('GET', '/api/utenti'), $handler)->getStatusCode() === 204, 'AuthMiddleware: the loan desk reads /api/utenti');
    $check($auth->process($factory->createServerRequest('POST', '/api/utenti'), $handler)->getStatusCode() !== 204, 'AuthMiddleware: but a POST there is judged as a POST');

    // ── 5. Labels and damaged rows ─────────────────────────────────────────────
    echo "5. Labels and damaged roles\n";
    $as($archivist);
    $check(P::backendLabel() !== 'Staff' && P::backendLabel() === ($_SESSION['user']['role_name'] ?? ''), 'the public header names the custom role, not "Staff"');
    $as($staff);
    $check(P::backendLabel() === 'Staff', 'and Staff for Staff');
    $db->query("UPDATE ruoli SET permessi = '[\"backend.access\",\"capability.from.the.future\"]' WHERE id = {$catalogRole}");
    $damaged = null;
    try {
        foreach ($repo->all() as $row) {
            if ((int) $row['id'] === $catalogRole) {
                $damaged = $row;
            }
        }
    } catch (Throwable $e) {
        $damaged = $e;
    }
    $check(is_array($damaged) && $damaged['permissions'] === [], 'a role with an unknown capability lists with no permissions instead of a 500');
    $db->query("UPDATE ruoli SET permessi = '{not json' WHERE id = {$catalogRole}");
    try {
        $repo->all();
        $check(true, 'malformed JSON in a role does not take the roles page down');
    } catch (Throwable) {
        $check(false, 'malformed JSON in a role does not take the roles page down');
    }
    $as($catalogUser);
    $check(!P::can('catalog.manage'), 'and the damaged role grants nothing');
} finally {
    foreach ($users as $id) {
        $db->query('DELETE FROM utenti WHERE id = ' . (int) $id);
    }
    foreach ($roles as $id) {
        $db->query('DELETE FROM ruoli WHERE id = ' . (int) $id);
    }
    $db->query("DELETE FROM api_keys WHERE name = '" . $db->real_escape_string($tag) . "'");
    // A refusal that fails above saves its whole tab: a script on every public page,
    // a foreign SMTP host and the other keys of those forms. Put both categories back
    // whatever happened, or the next suites run against that.
    $db->query("DELETE FROM system_settings WHERE category IN ('advanced', 'email')");
    $restore = $db->prepare('INSERT INTO system_settings (category, setting_key, setting_value, description) VALUES (?, ?, ?, ?)');
    foreach ($guardedSettings as $row) {
        $restore->bind_param('ssss', $row['category'], $row['setting_key'], $row['setting_value'], $row['description']);
        $restore->execute();
    }
    $restore->close();
    \App\Support\ConfigStore::clearCache();
    unset($_SESSION);
    R::reset();
}
echo "\n" . ($fail === 0 ? "ALL {$pass} PASS" : "{$fail} FAILED, {$pass} passed") . "\n";
exit($fail > 0 ? 1 : 0);
