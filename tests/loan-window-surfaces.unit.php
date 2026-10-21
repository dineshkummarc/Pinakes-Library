<?php
declare(strict_types=1);

/**
 * Reservation system: every surface that refuses a window for lack of a free
 * copy answers with the same LoanWindow figures, and the edge cases around
 * them.
 *
 * Companion of loan-window-cap.unit.php (public calendar endpoint, user-area
 * forms). Covered here, against the real database with a throwaway sandbox:
 *   - the admin loan form (PrestitiController::store), the admin edit of a
 *     loan's dates (update), the admin approval of a pending request
 *     (LoanApprovalController::approveLoan), the admin reservation form
 *     (ReservationsAdminController::store / update), a reader moving their own
 *     reservation (UserActionsController::changeReservationDate);
 *   - the Mobile API (POST /reservations) with and without end_date, and its
 *     error/meta contract for the app;
 *   - the availability payload the calendars read their durations from;
 *   - the edge case of a commitment starting the very next day (no loan of at
 *     least one day is possible: no figures, the generic refusal stays).
 *
 * Run: php tests/loan-window-surfaces.unit.php
 */

use App\Controllers\LoanApprovalController;
use App\Controllers\PrestitiController;
use App\Controllers\ReservationsAdminController;
use App\Controllers\ReservationsController;
use App\Controllers\UserActionsController;
use App\Models\SettingsRepository;
use App\Plugins\MobileApi\Controllers\ActionsController as MobileActionsController;
use App\Plugins\MobileApi\Support\AppAuthMiddleware;
use App\Services\CapacityService;
use App\Support\DateHelper;
use App\Support\LoanWindow;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as SlimResponse;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/mobile-api/src/Support/AppAuthMiddleware.php';
require_once $root . '/storage/plugins/mobile-api/src/Support/JsonBody.php';
require_once $root . '/storage/plugins/mobile-api/src/Support/ResponseEnvelope.php';
require_once $root . '/storage/plugins/mobile-api/src/Support/Input.php';
require_once $root . '/storage/plugins/mobile-api/src/Controllers/ActionsController.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

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
    fwrite(STDERR, "FAIL: database unreachable — loan window surfaces suite is mandatory: {$e->getMessage()}\n");
    exit(1);
}

$run = bin2hex(random_bytes(5));
$prefix = "ZZ_LWSRF_{$run}";
$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : "  [{$detail}]") . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

/** @var int[] $bookIds */
$bookIds = [];
/** @var int[] $userIds */
$userIds = [];
$cleanup = static function () use ($db, &$bookIds, &$userIds): void {
    foreach ($bookIds as $id) {
        $db->query("DELETE FROM prestiti WHERE libro_id = {$id}");
        $db->query("DELETE FROM prenotazioni WHERE libro_id = {$id}");
        $db->query("DELETE FROM copie WHERE libro_id = {$id}");
        $db->query("DELETE FROM libri WHERE id = {$id}");
    }
    foreach ($userIds as $id) {
        $db->query("DELETE FROM utenti WHERE id = {$id}");
    }
};
set_exception_handler(static function (Throwable $e) use ($cleanup, $db): void {
    try { $cleanup(); } catch (Throwable) {}
    fwrite(STDERR, 'FAIL: uncaught ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
    $db->close();
    exit(1);
});

// ── Sandbox ──────────────────────────────────────────────────────────────────
$mkUser = static function (int $n, string $tipo = 'standard') use ($db, $prefix, &$userIds): int {
    $card = "{$prefix}U{$n}";
    $email = strtolower("{$prefix}-u{$n}@example.invalid");
    $cognome = "{$prefix} {$n}";
    $stmt = $db->prepare("INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata)
                          VALUES (?, 'LoanWindow', ?, ?, 'x', 'attivo', ?, 1)");
    $stmt->bind_param('ssss', $card, $cognome, $email, $tipo);
    $stmt->execute();
    $stmt->close();
    $userIds[] = (int) $db->insert_id;
    return (int) $db->insert_id;
};
$mkBook = static function (int $copies, int $n) use ($db, $prefix, &$bookIds): array {
    $title = "{$prefix} book {$n}";
    $stmt = $db->prepare("INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
    $stmt->bind_param('sii', $title, $copies, $copies);
    $stmt->execute();
    $stmt->close();
    $bookId = (int) $db->insert_id;
    $bookIds[] = $bookId;
    $copyIds = [];
    for ($i = 1; $i <= $copies; $i++) {
        $code = "{$prefix}-{$bookId}-C{$i}";
        $stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'disponibile')");
        $stmt->bind_param('is', $bookId, $code);
        $stmt->execute();
        $stmt->close();
        $copyIds[] = (int) $db->insert_id;
    }
    return [$bookId, $copyIds];
};
$mkLoan = static function (int $bookId, ?int $copyId, int $userId, string $stato, int $attivo, string $from, string $to) use ($db): int {
    $stmt = $db->prepare("INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo, warning_sent, overdue_notification_sent)
                          VALUES (?, ?, ?, ?, ?, ?, 'diretto', ?, 0, 0)");
    $stmt->bind_param('iiisssi', $bookId, $copyId, $userId, $from, $to, $stato, $attivo);
    $stmt->execute();
    $stmt->close();
    return (int) $db->insert_id;
};
$addDays = static fn (string $ymd, int $days): string => (new DateTimeImmutable($ymd))->modify(($days >= 0 ? '+' : '') . "{$days} days")->format('Y-m-d');
$redirectParams = static function ($response): array {
    $query = parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY);
    $params = [];
    parse_str(is_string($query) ? $query : '', $params);
    return $params;
};
$jsonBody = static function ($response): array {
    $decoded = json_decode((string) $response->getBody(), true);
    return is_array($decoded) ? $decoded : [];
};
/** Drop what a request wrote for one patron on one title, so later checks start clean. */
$resetPatron = static function (int $bookId, int $userId, array $copyIds) use ($db): void {
    $db->query("DELETE FROM prestiti WHERE libro_id = {$bookId} AND utente_id = {$userId}");
    $db->query("DELETE FROM prenotazioni WHERE libro_id = {$bookId} AND utente_id = {$userId}");
    foreach ($copyIds as $copyId) {
        $db->query("UPDATE copie SET stato = 'disponibile' WHERE id = {$copyId}");
    }
};

$settings = new SettingsRepository($db);
$maxDays = max(1, (int) ($settings->get('loans', 'max_loan_duration_days', '90') ?? 90));
$defaultDays = min($settings->loanDurationDays(), $maxDays);
$offset = max(2, min(5, $defaultDays - 1));
$today = DateHelper::today();
$conflictStart = $addDays($today, $offset);
$conflictEnd = $addDays($today, $offset + 14);
$expectedMaxEnd = $addDays($today, $offset - 1);
$expectedMaxDays = $offset - 1;

$otherUser = $mkUser(1);
$requester = $mkUser(2);
$admin = $mkUser(3, 'admin');
[$book, $copies] = $mkBook(1, 1);
$mkLoan($book, $copies[0], $otherUser, 'prenotato', 1, $conflictStart, $conflictEnd);
echo "Sandbox: today {$today}, scheduled loan {$conflictStart}..{$conflictEnd}, default {$defaultDays} days, max {$maxDays} days" . PHP_EOL;

$asAdmin = static function () use ($admin): void {
    $_SESSION['user'] = ['tipo_utente' => 'admin', 'id' => $admin];
};
$asRequester = static function () use ($requester, $book): void {
    $_SESSION['user'] = ['tipo_utente' => 'standard', 'id' => $requester];
    $_SERVER['HTTP_REFERER'] = "/libro/{$book}";
};
$post = static fn (string $uri, array $body) => (new ServerRequestFactory())->createServerRequest('POST', $uri)->withParsedBody($body);

// ═════════ 1: the admin loan form ════════════════════════════════════════════
$asAdmin();
$response = (new PrestitiController())->store($post('/admin/loans/store', [
    'loan_submission_token' => \App\Support\OneTimeFormToken::issue('loan.create'),
    'utente_id' => (string) $requester,
    'libro_id' => (string) $book,
    'data_prestito' => $today,
    'data_scadenza' => $addDays($today, $offset + 2),
]), new SlimResponse(), $db);
$params = $redirectParams($response);
$check(($params['error'] ?? null) === 'no_copies_available'
    && ($params['max_end_date'] ?? null) === $expectedMaxEnd
    && (int) ($params['max_loan_days'] ?? -1) === $expectedMaxDays
    && ($params['blocked_from'] ?? null) === $conflictStart,
    "1 admin create loan crossing the scheduled loan: no_copies_available with max_end_date {$expectedMaxEnd}, max_loan_days {$expectedMaxDays}, blocked_from {$conflictStart}",
    $response->getHeaderLine('Location'));
$check(LoanWindow::explainFromParams($params) !== null
    && str_contains((string) LoanWindow::explainFromParams($params), (string) $expectedMaxDays),
    '2 the admin form renders those parameters as the maximum-duration sentence');

// ═════════ 3: editing a loan's dates across the scheduled loan ═══════════════
$ownLoan = $mkLoan($book, $copies[0], $requester, 'in_corso', 1, $addDays($today, -2), $addDays($today, 1));
$db->query("UPDATE copie SET stato = 'prestato' WHERE id = {$copies[0]}");
$asAdmin();
$response = (new PrestitiController())->update($post("/admin/loans/update/{$ownLoan}", [
    'utente_id' => (string) $requester,
    'data_prestito' => $addDays($today, -2),
    'data_scadenza' => $addDays($today, $offset + 2),
]), new SlimResponse(), $db, $ownLoan);
$params = $redirectParams($response);
// The added days run from the day after the current due date: the cap is
// measured from there, and the loan being edited is never its own conflict.
$claimStart = $addDays($today, 2);
$check(($params['error'] ?? null) === 'no_copies_available'
    && ($params['max_end_date'] ?? null) === $expectedMaxEnd
    && ($params['blocked_from'] ?? null) === $conflictStart
    && (int) ($params['max_loan_days'] ?? -1) === (int) (new DateTimeImmutable($claimStart))->diff(new DateTimeImmutable($expectedMaxEnd))->days,
    '3 admin edit of a loan due date across the scheduled loan: explained from the first added day, own loan excluded',
    $response->getHeaderLine('Location'));
$resetPatron($book, $requester, $copies);

// ═════════ 4: approving a pending request ════════════════════════════════════
$pending = $mkLoan($book, null, $requester, 'pendente', 0, $today, $addDays($today, $defaultDays));
$asAdmin();
$response = (new LoanApprovalController())->approveLoan($post('/admin/loans/approve', ['loan_id' => (string) $pending]), new SlimResponse(), $db);
$body = $jsonBody($response);
$check($response->getStatusCode() === 400
    && ($body['success'] ?? true) === false
    && ($body['max_end_date'] ?? null) === $expectedMaxEnd
    && ($body['max_duration_days'] ?? null) === $expectedMaxDays
    && str_contains((string) ($body['message'] ?? ''), (string) $expectedMaxDays),
    '4 approving a pending request whose default window crosses the scheduled loan: 400 JSON with the figures and the sentence',
    json_encode($body));
$resetPatron($book, $requester, $copies);

// ═════════ 5-7: the admin reservation form, create then edit ═════════════════
$asAdmin();
$response = (new ReservationsAdminController())->store($post('/admin/reservations/store', [
    'libro_id' => (string) $book,
    'utente_id' => (string) $requester,
    'data_prenotazione' => $today,
    'data_scadenza' => $addDays($today, $offset + 2),
    'data_inizio_richiesta' => $today,
    'data_fine_richiesta' => $addDays($today, $offset + 2),
]), new SlimResponse(), $db);
$params = $redirectParams($response);
$check(($params['error'] ?? null) === 'capacity_full'
    && ($params['max_end_date'] ?? null) === $expectedMaxEnd
    && ($params['blocked_from'] ?? null) === $conflictStart,
    '5 admin reservation crossing the scheduled loan: capacity_full with the figures',
    $response->getHeaderLine('Location'));

$response = (new ReservationsAdminController())->store($post('/admin/reservations/store', [
    'libro_id' => (string) $book,
    'utente_id' => (string) $requester,
    'data_prenotazione' => $today,
    'data_scadenza' => $expectedMaxEnd,
    'data_inizio_richiesta' => $today,
    'data_fine_richiesta' => $expectedMaxEnd,
]), new SlimResponse(), $db);
$reservationRow = $db->query("SELECT id FROM prenotazioni WHERE libro_id = {$book} AND utente_id = {$requester} AND stato = 'attiva' ORDER BY id DESC LIMIT 1")->fetch_assoc();
$reservationId = (int) ($reservationRow['id'] ?? 0);
$check($reservationId > 0 && !isset($redirectParams($response)['error']),
    "6 the same reservation ending {$expectedMaxEnd} is created",
    $response->getHeaderLine('Location'));

$response = (new ReservationsAdminController())->update($post("/admin/reservations/update/{$reservationId}", [
    'stato' => 'attiva',
    'data_prenotazione' => $today,
    'data_scadenza_prenotazione' => $addDays($today, $offset + 2),
    'data_inizio_richiesta' => $today,
    'data_fine_richiesta' => $addDays($today, $offset + 2),
]), new SlimResponse(), $db, $reservationId);
$params = $redirectParams($response);
$check(($params['error'] ?? null) === 'capacity_full'
    && ($params['max_end_date'] ?? null) === $expectedMaxEnd
    && ($params['blocked_from'] ?? null) === $conflictStart,
    '7 extending that reservation across the scheduled loan: capacity_full with the figures, own row excluded (cap still from the start)',
    $response->getHeaderLine('Location'));

// ═════════ 8: a reader moving their own reservation ══════════════════════════
$asRequester();
$response = (new UserActionsController())->changeReservationDate($post('/reservation/change-date', [
    'reservation_id' => (string) $reservationId,
    'desired_date' => $today,
]), new SlimResponse(), $db);
$params = $redirectParams($response);
$check(($params['error'] ?? null) === 'not_available'
    && ($params['blocked_from'] ?? null) === $conflictStart
    && ($params['max_end_date'] ?? null) === $expectedMaxEnd,
    '8 moving one\'s own reservation to a start whose default window crosses the scheduled loan: not_available with the figures',
    $response->getHeaderLine('Location'));
$resetPatron($book, $requester, $copies);

// ═════════ 9-12: the Mobile API contract ═════════════════════════════════════
$mobile = static function (array $body) use ($db, $requester, $book, $asRequester, $jsonBody): array {
    $asRequester();
    $request = (new ServerRequestFactory())
        ->createServerRequest('POST', '/api/v1/reservations')
        ->withAttribute(AppAuthMiddleware::ATTR_USER, ['id' => $requester])
        ->withParsedBody(['book_id' => $book] + $body);
    $response = (new MobileActionsController($db))->requestReservation($request, new SlimResponse());
    return ['status' => $response->getStatusCode(), 'body' => $jsonBody($response)];
};

$r = $mobile(['desired_date' => $today]);
$check($r['status'] === 409
    && ($r['body']['error']['code'] ?? null) === 'not_available'
    && ($r['body']['meta']['max_end_date'] ?? null) === $expectedMaxEnd
    && ($r['body']['meta']['max_duration_days'] ?? null) === $expectedMaxDays
    && ($r['body']['meta']['first_conflict_date'] ?? null) === $conflictStart
    && str_contains((string) ($r['body']['error']['message'] ?? ''), (string) $expectedMaxDays),
    '9 Mobile POST /reservations without end_date: 409 not_available, the sentence as error.message, the figures in meta',
    json_encode($r));

$r = $mobile(['desired_date' => $today, 'end_date' => $expectedMaxEnd]);
$check($r['status'] === 201 && in_array($r['body']['data']['type'] ?? null, ['loan', 'reservation'], true),
    "10 Mobile POST /reservations with end_date {$expectedMaxEnd}: 201, the request is accepted",
    json_encode($r));
$resetPatron($book, $requester, $copies);

$r = $mobile(['desired_date' => $today, 'end_date' => $addDays($today, $maxDays + 1)]);
$check($r['status'] === 422 && ($r['body']['error']['code'] ?? null) === 'max_duration_exceeded',
    '11 Mobile end_date beyond max_loan_duration_days: 422 max_duration_exceeded',
    json_encode($r));

$r = $mobile(['desired_date' => $today, 'end_date' => $addDays($today, -1)]);
$check($r['status'] === 422 && ($r['body']['error']['code'] ?? null) === 'invalid_date',
    '12 Mobile end_date before the start: 422 invalid_date',
    json_encode($r));

// ═════════ 13-14: the durations every calendar reads ═════════════════════════
$availability = (new ReservationsController($db))->getBookAvailabilityData($book, null, 10, $requester);
$check(is_array($availability)
    && ($availability['loan_duration_days'] ?? null) === $defaultDays
    && ($availability['max_loan_duration_days'] ?? null) === $maxDays,
    "13 the availability payload carries loan_duration_days ({$defaultDays}, capped by the maximum) and max_loan_duration_days ({$maxDays})",
    json_encode([$availability['loan_duration_days'] ?? null, $availability['max_loan_duration_days'] ?? null]));

$blocked = (new CapacityService($db))->unavailableDatesInRange($book, $today, $addDays($today, $defaultDays), $requester);
$check(LoanWindow::redirectParams($today, $blocked) === ['max_loan_days' => $expectedMaxDays, 'max_end_date' => $expectedMaxEnd, 'blocked_from' => $conflictStart],
    '14 LoanWindow::redirectParams carries exactly the three figures every redirect uses');

// ═════════ 15-17: a commitment starting the very next day ════════════════════
[$tightBook, $tightCopies] = $mkBook(1, 2);
$mkLoan($tightBook, $tightCopies[0], $otherUser, 'prenotato', 1, $addDays($today, 1), $addDays($today, 10));
$check(LoanWindow::cap($today, [$addDays($today, 1)]) === null,
    '15 LoanWindow::cap: a conflict on the next day leaves no loan of at least one day, so no figures');

$asRequester();
$request = $post("/api/libro/{$tightBook}/reservation", ['start_date' => $today]);
$response = (new ReservationsController($db))->createReservation($request, new SlimResponse(), ['id' => (string) $tightBook]);
$body = $jsonBody($response);
$check($response->getStatusCode() === 400
    && ($body['error'] ?? null) === 'no_capacity'
    && !array_key_exists('max_end_date', $body)
    && ($body['message'] ?? '') === __('Nessuna copia disponibile nelle date richieste'),
    '16 public request from today with the next day booked: generic refusal, no misleading "0 days" figure',
    json_encode($body));

$r = $mobile(['desired_date' => $today]);
$asRequester();
$request = (new ServerRequestFactory())
    ->createServerRequest('POST', '/api/v1/reservations')
    ->withAttribute(AppAuthMiddleware::ATTR_USER, ['id' => $requester])
    ->withParsedBody(['book_id' => $tightBook, 'desired_date' => $today]);
$response = (new MobileActionsController($db))->requestReservation($request, new SlimResponse());
$body = $jsonBody($response);
$check($response->getStatusCode() === 409
    && ($body['error']['code'] ?? null) === 'not_available'
    && !isset($body['meta']['max_end_date']),
    '17 Mobile request on that title: 409 not_available with no figures in meta',
    json_encode($body));

$cleanup();
$db->close();

echo PHP_EOL . "Passed: {$passed}   Failed: {$failed}" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
