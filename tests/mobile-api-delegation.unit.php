<?php
declare(strict_types=1);

/**
 * Mobile API 1.7.0 — Delegation: a core web action called from the API must
 * never leave anything in the PHP session.
 *
 *   A. Every isolated key the action writes (flash messages, the loan form
 *      draft, CSV/LibraryThing job state, one-time form tokens) is captured
 *      in the outcome and removed afterwards; the value a concurrent web
 *      session held before is restored.
 *   B. The same holds when the action throws.
 *   C. A one-time token issued INSIDE the action is consumable inside it and
 *      gone afterwards (the loan-create pattern), while the web session's
 *      own tokens survive.
 *   D. Outcome parsing: Location query, error code, JSON body, location id.
 *   E. Every session key a delegated core controller writes is in the list:
 *      grepped from the controllers the API delegates to.
 *
 * Run: php tests/mobile-api-delegation.unit.php
 */

require __DIR__ . '/../vendor/autoload.php';

$plugin = __DIR__ . '/../storage/plugins/mobile-api/src';
require_once $plugin . '/Support/Delegation.php';

use App\Plugins\MobileApi\Support\Delegation;
use Slim\Psr7\Response;

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    echo ($condition ? '  OK  ' : '  FAIL ') . $label . "\n";
    $condition ? $passed++ : $failed++;
};

$_SESSION = [];

// ── A. Capture and restore ───────────────────────────────────────────────────
echo "A. Session keys are isolated\n";
$_SESSION = ['user' => ['id' => 7], 'success_message' => 'web flash', 'csv_import_data' => ['web' => true]];
$outcome = Delegation::run(static function (): \Psr\Http\Message\ResponseInterface {
    $_SESSION['success_message'] = 'api flash';
    $_SESSION['error_message'] = 'api error';
    $_SESSION['loan_form_old'] = ['utente_id' => '3'];
    $_SESSION['csv_import_data'] = ['api' => true, 'processed' => 10];
    $_SESSION['error'] = 'Formato non valido';
    return (new Response())->withStatus(302)->withHeader('Location', '/admin/loans?created=1&pdf=42');
});
$check($outcome->flash('success_message') === 'api flash', 'success_message captured');
$check($outcome->flash('error_message') === 'api error', 'error_message captured');
$check(($outcome->session['csv_import_data'] ?? null) === ['api' => true, 'processed' => 10], 'csv_import_data captured (job state swap)');
$check(($outcome->session['loan_form_old'] ?? null) === ['utente_id' => '3'], 'loan_form_old captured');
$check($_SESSION['success_message'] === 'web flash', 'the web session flash is restored');
$check($_SESSION['csv_import_data'] === ['web' => true], 'the web session import state is restored');
$check(!array_key_exists('error_message', $_SESSION), 'error_message written by the action is gone');
$check(!array_key_exists('loan_form_old', $_SESSION), 'loan_form_old written by the action is gone');
$check(!array_key_exists('error', $_SESSION), 'error written by the action is gone');
$check($_SESSION['user'] === ['id' => 7], 'keys outside the list are untouched');

// ── B. Exceptions still restore ──────────────────────────────────────────────
echo "\nB. A throwing action restores the session\n";
$_SESSION = ['error_message' => 'web'];
$thrown = false;
try {
    Delegation::run(static function (): \Psr\Http\Message\ResponseInterface {
        $_SESSION['error_message'] = 'api';
        $_SESSION['success_message'] = 'api';
        throw new \RuntimeException('boom');
    });
} catch (\RuntimeException $e) {
    $thrown = $e->getMessage() === 'boom';
}
$check($thrown, 'the exception propagates');
$check($_SESSION === ['error_message' => 'web'], 'session back to its prior state');

// ── C. One-time tokens ───────────────────────────────────────────────────────
echo "\nC. One-time form tokens\n";
$_SESSION = [];
$webToken = \App\Support\OneTimeFormToken::issue('loan.create');
$consumedInside = null;
Delegation::run(static function () use (&$consumedInside): \Psr\Http\Message\ResponseInterface {
    $token = \App\Support\OneTimeFormToken::issue('loan.create');
    $consumedInside = \App\Support\OneTimeFormToken::consume('loan.create', $token);
    return new Response();
});
$check($consumedInside === true, 'a token issued inside the action is consumable there');
$check(\App\Support\OneTimeFormToken::consume('loan.create', $webToken), "the web session's own token survived the API call");

// ── D. Outcome parsing ───────────────────────────────────────────────────────
echo "\nD. Outcome parsing\n";
$o = Delegation::run(static fn () => (new Response())->withStatus(302)->withHeader('Location', 'http://x/admin/loans/create?error=max_loans_reached'));
$check($o->isRedirect() && $o->errorCode() === 'max_loans_reached', '?error= code read from Location');
$o = Delegation::run(static fn () => (new Response())->withStatus(302)->withHeader('Location', '/admin/archives/42'));
$check($o->locationId('#/admin/archives/(\d+)$#') === 42 && $o->errorCode() === null, 'location id parsed, no error');
$json = new Response();
$json->getBody()->write('{"success":false,"message":"Nessuna copia"}');
$o = Delegation::run(static fn () => $json->withStatus(400));
$check($o->status === 400 && $o->json === ['success' => false, 'message' => 'Nessuna copia'], 'JSON body decoded');
$req = Delegation::request('POST', '/admin/books/import/chunk', [], [], '{"start":0,"size":10}');
$check((string) $req->getBody() === '{"start":0,"size":10}' && str_contains($req->getHeaderLine('Content-Type'), 'json'), 'raw JSON body request (processChunk reads the raw body)');
$req = Delegation::request('POST', '/admin/loans/create', ['a' => '1']);
$check($req->getParsedBody() === ['a' => '1'], 'form request carries the parsed body');

// ── E. Every session key the delegated controllers write is isolated ─────────
echo "\nE. Session keys written by delegated controllers\n";
$delegated = [
    'Controllers/PrestitiController.php', 'Controllers/ReservationsAdminController.php', 'Controllers/LoanApprovalController.php',
    'Controllers/CopyController.php', 'Controllers/CollaneController.php', 'Controllers/CsvImportController.php',
    'Controllers/LibraryThingImportController.php', 'Controllers/LibriController.php', 'Controllers/SettingsController.php',
    'Support/OneTimeFormToken.php',
];
$written = [];
foreach ($delegated as $file) {
    $src = (string) file_get_contents(__DIR__ . '/../app/' . $file);
    preg_match_all("/\\\$_SESSION\\[['\"]([a-z_]+)['\"]\\]\\s*(?:\\[[^\\]]*\\]\\s*)?=(?!=)/", $src, $m);
    foreach ($m[1] as $key) {
        $written[$key] = true;
    }
    if (str_contains($src, "SESSION_KEY = '_one_time_form_tokens'")) {
        $written['_one_time_form_tokens'] = true;
    }
}
// `user` is AppAuthMiddleware's own per-request mirror, restored by it.
// `last_regeneration` is the session keep-alive timestamp a LibraryThing chunk
// refreshes (owned by the session layer, harmless to keep); `new_api_key` is
// written only by SettingsController's API-key creation, never delegated.
unset($written['user'], $written['last_regeneration'], $written['new_api_key']);
$missing = array_values(array_diff(array_keys($written), Delegation::SESSION_KEYS));
$check($missing === [], 'written keys ⊆ Delegation::SESSION_KEYS (missing: ' . implode(', ', $missing) . '; found: ' . implode(', ', array_keys($written)) . ')');

echo "\n" . ($failed === 0 ? "ALL {$passed} PASS\n" : "{$passed} PASS, {$failed} FAIL\n");
exit($failed === 0 ? 0 : 1);
