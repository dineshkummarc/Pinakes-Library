<?php
declare(strict_types=1);

/** DB-backed permission boundaries, role changes and privilege-escalation regressions. */
require dirname(__DIR__) . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
use App\Support\Permissions as P;
use App\Support\SessionRoleRevalidator as R;
use App\Support\DigitalAccess as A;
use App\Support\UserRoleAssignment as Assignment;
use App\Models\CustomRoleRepository;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

$env = Dotenv\Dotenv::parse(file_get_contents(dirname(__DIR__) . '/.env'));
$setting = static function (string $key, string $default = '') use ($env): string {
    $process = getenv('E2E_' . $key);
    if ($process === false || $process === '') { $process = getenv($key); }
    return $process === false || $process === '' ? ($env[$key] ?? $default) : $process;
};
// "localhost" means the socket to mysqli: use the configured one, not php.ini's default.
$socket = $setting('DB_SOCKET');
$db = new mysqli($setting('DB_HOST', '127.0.0.1'), $setting('DB_USER'), $setting('DB_PASS'), $setting('DB_NAME'), (int) $setting('DB_PORT', '3306'),
    $socket !== '' && file_exists($socket) ? $socket : null);
$db->set_charset('utf8mb4');
$tag = 'roles480-' . bin2hex(random_bytes(4));
$users = $roles = [];
$pass = 0;
$check = static function (bool $ok, string $label) use (&$pass): void {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    $pass++; echo "OK {$label}\n";
};
$repo = new CustomRoleRepository($db);
$addRole = static function (array $permissions) use ($db, $tag, &$roles, $repo): int {
    $name = $tag . '-' . count($roles);
    $repo->save([], ['nome' => $name, 'permissions' => $permissions]);
    $stmt = $db->prepare('SELECT id FROM ruoli WHERE nome = ?'); $stmt->bind_param('s', $name); $stmt->execute();
    $id = (int) $stmt->get_result()->fetch_assoc()['id']; $stmt->close(); $roles[] = $id; return $id;
};
$addUser = static function (string $type, ?int $roleId = null, int $digital = 0) use ($db, $tag, &$users): int {
    $code = $tag . count($users); $email = $code . '@example.test';
    $stmt = $db->prepare("INSERT INTO utenti (nome,cognome,email,password,codice_tessera,tipo_utente,stato,email_verificata,ruolo_id,accesso_digitale) VALUES ('Test','Roles',?,'unused',?,?,'attivo',1,?,?)");
    $stmt->bind_param('sssii', $email, $code, $type, $roleId, $digital); $stmt->execute();
    $id = (int) $stmt->insert_id; $users[] = $id; $stmt->close(); return $id;
};
$handler = new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface { return new Response(204); }
};
$request = static fn (string $path) => (new ServerRequestFactory())->createServerRequest('GET', $path);
try {
    $catalogId = $addRole(['backend.access', 'catalog.manage']);
    $loanId = $addRole(['backend.access', 'loans.manage']);
    $filesId = $addRole(['digital.access']);
    $managerId = $addRole(['backend.access', 'users.manage', 'settings.manage']);
    $admin = $addUser('admin'); $staff = $addUser('staff'); $reader = $addUser('standard');
    $catalog = $addUser('standard', $catalogId); $loans = $addUser('premium', $loanId);
    $files = $addUser('standard', $filesId); $grant = $addUser('premium', null, 1); $manager = $addUser('standard', $managerId);
    $_SESSION['user'] = P::viewer($staff, $db);
    $check(!P::mayConfigure(), 'legacy staff cannot access full settings secrets');
    $_SESSION['user'] = P::viewer($reader, $db);
    $check(!P::mayConfigure(), 'ordinary reader cannot configure settings');
    $_SESSION['user'] = P::viewer($admin, $db);
    $check(P::mayConfigure(), 'administrator can configure all settings');
    foreach ([$admin, $staff] as $id) {
        foreach (P::ALL as $permission) { $check(P::can($permission, P::viewer($id, $db)), "built-in operator {$id}: {$permission}"); }
    }
    foreach (P::ALL as $permission) { $check(!P::can($permission, P::viewer($reader, $db)), "reader denied: {$permission}"); }
    foreach ([[$catalog, 'catalog.manage'], [$loans, 'loans.manage'], [$files, 'digital.access']] as [$id, $own]) {
        foreach (['catalog.manage', 'loans.manage', 'users.manage', 'settings.manage', 'digital.access'] as $permission) {
            $check(P::can($permission, P::viewer($id, $db)) === ($own === $permission), "custom isolation {$own}: {$permission}");
        }
    }
    $check(!P::allowsPath('/admin/dashboard', P::viewer($files, $db)), 'digital-only role has no backend');
    $check(!P::allowsPath('/admin/books/create', P::viewer($grant, $db)), 'individual file grant has no backend');
    foreach ([$files, $grant] as $id) {
        foreach (['staff', 'loan', 'users'] as $visibility) { $check(A::decide(['visibility' => $visibility], P::viewer($id, $db), 0, $db) === A::GRANTED, "digital privilege opens {$visibility}"); }
    }
    $check(A::decide(['visibility' => 'staff'], P::viewer($reader, $db), 0, $db) === A::STAFF_ONLY, 'ordinary reader cannot open restricted files');
    $check(A::decide(['visibility' => 'users'], P::viewer($catalog, $db), 0, $db) === A::GRANTED, 'cataloguer retains reader access');
    foreach ([[$catalog, '/admin/books/create', true], [$catalog, '/admin/users', false], [$catalog, '/admin/plugins', false], [$catalog, '/admin/roles', false], [$catalog, '/api/libri', true], [$loans, '/admin/loans', true], [$loans, '/admin/settings', false], [$manager, '/admin/users', true], [$manager, '/admin/books', false], [$manager, '/admin/settings', true]] as [$id, $path, $allowed]) {
        $_SESSION['user'] = ['id' => $id, 'tipo_utente' => 'admin', 'permissions' => P::ALL]; R::reset();
        $status = (new App\Middleware\AdminAuthMiddleware($db))->process($request($path), $handler)->getStatusCode();
        $check(($status === 204) === $allowed, "server middleware {$id}: {$path}");
    }
    $_SESSION['user'] = ['id' => $catalog]; R::reset(); R::resolve($db);
    $check(!P::allowsPath('/admin/books-evil'), 'similar prefix never grants another route');
    $check(!P::allowsPath('/admin/settings', ['tipo_utente' => 'standard', 'permissions' => ['settings.manage']]), 'backend permission remains independent');
    $repo->save([$catalogId => ['nome' => $tag . '-catalog-revoked', 'permissions' => []]], []);
    R::reset();
    $check(!P::authorizePath('/admin/books', $db), 'revoking role permission takes effect on next request');
    $db->query("UPDATE utenti SET tipo_utente='standard' WHERE id={$staff}"); $_SESSION['user'] = ['id' => $staff, 'tipo_utente' => 'staff']; R::reset();
    $check(!P::authorizePath('/admin/books', $db), 'demoted staff session loses backend access');
    $db->query("UPDATE utenti SET stato='sospeso' WHERE id={$files}");
    $check(P::viewer($files, $db) === null, 'suspended digital reader loses files immediately');
    $_SESSION['user'] = ['id' => $manager]; R::reset(); R::resolve($db);
    $check(!Assignment::canEdit(['id' => $admin, 'tipo_utente' => 'admin']), 'user manager cannot change administrator password');
    $check(!Assignment::canEdit(['id' => $catalog, 'tipo_utente' => 'standard', 'ruolo_id' => $catalogId]), 'user manager cannot change another operator');
    $check(Assignment::canEdit(['id' => $reader, 'tipo_utente' => 'standard']), 'user manager can edit ordinary reader');
    $check(Assignment::fromSubmission(['permissions_present' => 1, 'ruolo_id' => $managerId, 'accesso_digitale' => '1'], ['ruolo_id' => null, 'accesso_digitale' => 0], $db) === [null, 0], 'crafted assignment cannot escalate user-manager privileges');
    $check(P::mayConfigure(), 'delegated settings manager can configure settings');
    $rolesController = new App\Controllers\RolesController();
    $check($rolesController->index($request('/admin/roles'), new Response(), $db)->getStatusCode() === 403, 'user manager cannot read role administration through direct controller call');
    $check($rolesController->save($request('/admin/roles'), new Response(), $db)->getStatusCode() === 403, 'user manager cannot mutate roles through direct controller call');
    foreach ([['unknown'], [['backend.access']], 'all'] as $bad) {
        try { P::normalize($bad); $check(false, 'invalid permissions rejected'); } catch (InvalidArgumentException) { $check(true, 'invalid permissions rejected'); }
    }
    try { $repo->save([$loanId => ['delete' => '1']], []); $check(false, 'assigned role cannot be deleted'); } catch (mysqli_sql_exception $e) { $check($e->getCode() === 1451, 'assigned role cannot be deleted'); }
    try { $repo->save([$loanId => ['nome' => $tag . '-rollback', 'permissions' => []]], ['nome' => $tag . '-invalid', 'permissions' => ['unknown']]); } catch (InvalidArgumentException) {}
    $check(P::can('loans.manage', P::viewer($loans, $db)), 'failed matrix batch rolls back earlier changes');
    $_SESSION['user'] = P::viewer($catalog, $db);
    $check(!App\Support\DigitalAttachments::mayEditSubmission(['digital_attachments_present' => 1, 'digital_attachments' => [['url' => '/uploads/digital/a.pdf', 'kind' => 'ebook']]]), 'catalogue permission alone cannot add or change attachments');
    $check(App\Support\DigitalAttachments::mayEditSubmission([]), 'cataloguer can edit bibliographic metadata without touching files');
    echo "SUCCESS {$pass} checks\n";
} finally {
    foreach ($users as $id) { $db->query('DELETE FROM utenti WHERE id=' . $id); }
    foreach ($roles as $id) { $db->query('DELETE FROM ruoli WHERE id=' . $id); }
    unset($_SESSION['user']); R::reset();
}
