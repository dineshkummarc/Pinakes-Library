<?php

declare(strict_types=1);

namespace App\Plugins\Emeroteca\Modules;

use App\Support\SecureLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * Staff desk of the periodicals for the companion app (/api/v1/periodicals/staff,
 * Mobile API 1.7.0 Tier 2): scan an issue barcode, receive or claim the issue,
 * add a year or an issue, and the subscriptions.
 *
 * Mounted next to the read-only MobileModule, only when the mobile-api
 * plugin is active and new enough to ship its staff role guard and
 * delegation helper (class probes, so an older mobile-api simply leaves the
 * desk off). Every write goes through this plugin's own admin controllers
 * (IssueAdminController::manageSubmit, SubscriptionAdminController) with
 * the form body the admin pages post; their flash message tells success
 * from refusal. Deletes and the admin-only actions of the web desk
 * (claim-overdue, mark-missing) are not exposed.
 */
final class MobileStaffModule
{
    public const STATUSES = ['posseduto', 'mancante', 'atteso', 'smarrito', 'reclamato', 'scartato'];

    public function __construct(private \mysqli $db, private \EmerotecaPlugin $plugin)
    {
    }

    /** True when mobile-api ships what the desk needs (1.7.0+). */
    public static function supported(): bool
    {
        return class_exists(\App\Plugins\MobileApi\Support\StaffRoleMiddleware::class)
            && class_exists(\App\Plugins\MobileApi\Support\Delegation::class)
            && class_exists(\App\Plugins\MobileApi\Support\ResponseEnvelope::class);
    }

    /**
     * @param callable(): \App\Plugins\MobileApi\Support\AppAuthMiddleware $authMw
     * @param callable(): \App\Plugins\MobileApi\Support\TokenQuotaMiddleware $quotaMw
     */
    public function register(mixed $app, callable $authMw, callable $quotaMw): void
    {
        if (!self::supported()) {
            return;
        }
        $module = $this;
        $staffMw = static fn (): \App\Plugins\MobileApi\Support\StaffRoleMiddleware => new \App\Plugins\MobileApi\Support\StaffRoleMiddleware();
        $routes = [
            ['GET', '/scan-lookup', static fn ($q, $r, $a) => $module->scanLookup($q, $r)],
            ['POST', '/issues/{id:[0-9]+}/receive', static fn ($q, $r, $a) => $module->issueAction($q, $r, (int) $a['id'], 'receive_issue')],
            ['POST', '/issues/{id:[0-9]+}/claim', static fn ($q, $r, $a) => $module->issueAction($q, $r, (int) $a['id'], 'claim_issue')],
            ['POST', '/years/{id:[0-9]+}/issues', static fn ($q, $r, $a) => $module->addIssue($q, $r, (int) $a['id'])],
            ['POST', '/{id:[0-9]+}/years', static fn ($q, $r, $a) => $module->addYear($q, $r, (int) $a['id'])],
            ['GET', '/subscriptions', static fn ($q, $r, $a) => $module->subscriptions($q, $r)],
            ['POST', '/subscriptions', static fn ($q, $r, $a) => $module->saveSubscription($q, $r, null)],
            ['PUT', '/subscriptions/{id:[0-9]+}', static fn ($q, $r, $a) => $module->saveSubscription($q, $r, (int) $a['id'])],
        ];
        $group = $app->group('/api/v1/periodicals/staff', function ($g) use ($routes, $staffMw, $authMw, $quotaMw): void {
            foreach ($routes as [$method, $pattern, $handler]) {
                $g->map([$method], $pattern, function (ServerRequestInterface $rq, ResponseInterface $rs, array $a) use ($handler): ResponseInterface {
                    return $handler($rq, $rs, $a);
                })->add($staffMw())->add($quotaMw())->add($authMw());
            }
        });
        $group->add(new \App\Plugins\MobileApi\Support\HttpsEnforceMiddleware());
    }

    // ─── Desk ───────────────────────────────────────────────────────────────

    public function scanLookup(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $code = is_scalar($params['code'] ?? null) ? trim((string) $params['code']) : '';
        if ($code === '') {
            return $this->error($response, 'validation_error', __('Codice a barre non valido.'), 422, ['field' => 'code']);
        }
        $query = ['code' => $code];
        $periodical = is_scalar($params['periodical_id'] ?? null) ? (int) $params['periodical_id'] : 0;
        if ($periodical > 0) {
            $query['testata'] = (string) $periodical;
        }
        try {
            $outcome = $this->delegate(\App\Plugins\Emeroteca\Controllers\ExportAdminController::class, 'scanLookup', 'GET', '/admin/periodicals/scan-lookup', [], [], $query);
            if ($outcome->status !== 200 || $outcome->json === []) {
                throw new \RuntimeException('scanLookup answered HTTP ' . $outcome->status);
            }

            return $this->ok($response, $outcome->json);
        } catch (\Throwable $e) {
            return $this->fail($response, 'scan lookup', $e);
        }
    }

    public function issueAction(ServerRequestInterface $request, ResponseInterface $response, int $issueId, string $action): ResponseInterface
    {
        try {
            $issue = $this->issueRow($issueId);
            if ($issue === null) {
                return $this->error($response, 'not_found', __('Fascicolo non trovato.'), 404);
            }
            $outcome = $this->delegate(
                \App\Plugins\Emeroteca\Controllers\IssueAdminController::class,
                'manageSubmit',
                'POST',
                '/admin/periodicals/' . $issue['testata_id'] . '/issues',
                ['action' => $action, 'fascicolo_id' => (string) $issueId, 'annata_id' => (string) $issue['annata_id']],
                ['id' => (string) $issue['testata_id']]
            );
            $error = $outcome->flash('error_message');
            if ($error !== null) {
                return $this->error($response, 'conflict', $error, 409, ['status' => (string) $issue['stato']]);
            }

            return $this->ok($response, $this->mapIssue((array) $this->issueRow($issueId)));
        } catch (\Throwable $e) {
            return $this->fail($response, 'issue ' . $action, $e);
        }
    }

    public function addIssue(ServerRequestInterface $request, ResponseInterface $response, int $yearId): ResponseInterface
    {
        $json = $this->json($request);
        $number = is_scalar($json['number'] ?? null) ? trim((string) $json['number']) : '';
        if ($number === '') {
            return $this->error($response, 'validation_error', __('Il numero del fascicolo è obbligatorio.'), 422, ['field' => 'number']);
        }
        $status = is_string($json['status'] ?? null) ? (string) $json['status'] : '';
        if ($status !== '' && !in_array($status, self::STATUSES, true)) {
            return $this->error($response, 'validation_error', __('Stato del fascicolo non valido.'), 422, ['field' => 'status']);
        }
        try {
            $year = $this->rows('SELECT id, testata_id FROM emeroteca_annate WHERE id = ?', [$yearId])[0] ?? null;
            if ($year === null) {
                return $this->error($response, 'not_found', __('Annata non trovata per questa testata.'), 404);
            }
            $before = (int) ($this->rows('SELECT COALESCE(MAX(id), 0) AS m FROM emeroteca_fascicoli', [])[0]['m'] ?? 0);
            $outcome = $this->delegate(
                \App\Plugins\Emeroteca\Controllers\IssueAdminController::class,
                'manageSubmit',
                'POST',
                '/admin/periodicals/' . $year['testata_id'] . '/issues',
                [
                    'action' => 'add_fascicolo', 'annata_id' => (string) $yearId, 'numero' => $number,
                    'data_pubblicazione' => is_string($json['date'] ?? null) ? (string) $json['date'] : '',
                    'stato' => $status,
                ],
                ['id' => (string) $year['testata_id']]
            );
            $error = $outcome->flash('error_message');
            if ($error !== null) {
                return $this->error($response, 'validation_error', $error, 422);
            }
            $id = (int) ($this->rows('SELECT MAX(id) AS m FROM emeroteca_fascicoli WHERE id > ? AND annata_id = ?', [$before, $yearId])[0]['m'] ?? 0);
            $row = $id > 0 ? $this->issueRow($id) : null;
            if ($row === null) {
                throw new \RuntimeException('new issue not resolvable');
            }

            return $this->ok($response, $this->mapIssue($row), 201);
        } catch (\Throwable $e) {
            return $this->fail($response, 'add issue', $e);
        }
    }

    public function addYear(ServerRequestInterface $request, ResponseInterface $response, int $periodicalId): ResponseInterface
    {
        $json = $this->json($request);
        if (!is_int($json['year'] ?? null)) {
            return $this->error($response, 'validation_error', __('Valore non valido.'), 422, ['field' => 'year']);
        }
        try {
            if ($this->rows('SELECT id FROM emeroteca_testate WHERE id = ?', [$periodicalId]) === []) {
                return $this->error($response, 'not_found', __('Testata non trovata.'), 404);
            }
            $before = (int) ($this->rows('SELECT COALESCE(MAX(id), 0) AS m FROM emeroteca_annate', [])[0]['m'] ?? 0);
            $form = ['action' => 'add_annata', 'anno' => (string) $json['year'], 'volume' => is_scalar($json['volume'] ?? null) ? (string) $json['volume'] : ''];
            if (($json['bound'] ?? false) === true) {
                $form['rilegata'] = '1';
            }
            $outcome = $this->delegate(
                \App\Plugins\Emeroteca\Controllers\IssueAdminController::class,
                'manageSubmit',
                'POST',
                '/admin/periodicals/' . $periodicalId . '/issues',
                $form,
                ['id' => (string) $periodicalId]
            );
            $error = $outcome->flash('error_message');
            if ($error !== null) {
                return $this->error($response, 'validation_error', $error, 422, ['field' => 'year']);
            }
            $row = $this->rows('SELECT id, testata_id, anno, volume, rilegata FROM emeroteca_annate WHERE id > ? AND testata_id = ? ORDER BY id DESC LIMIT 1', [$before, $periodicalId])[0] ?? null;
            if ($row === null) {
                throw new \RuntimeException('new year not resolvable');
            }

            return $this->ok($response, [
                'id' => (int) $row['id'], 'periodical_id' => (int) $row['testata_id'], 'year' => (int) $row['anno'],
                'volume' => (string) $row['volume'] !== '' ? (string) $row['volume'] : null, 'bound' => (int) $row['rilegata'] === 1,
            ], 201);
        } catch (\Throwable $e) {
            return $this->fail($response, 'add year', $e);
        }
    }

    // ─── Subscriptions ──────────────────────────────────────────────────────

    /** API key → admin form key. */
    private const SUBSCRIPTION_FIELDS = [
        'supplier' => 'fornitore', 'cost' => 'costo', 'currency' => 'valuta', 'start_date' => 'data_inizio',
        'end_date' => 'data_scadenza', 'notes' => 'note',
    ];

    public function subscriptions(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $periodical = (int) ($request->getQueryParams()['periodical_id'] ?? 0);
        try {
            $sql = 'SELECT s.*, t.titolo FROM emeroteca_abbonamenti s JOIN emeroteca_testate t ON t.id = s.testata_id'
                . ($periodical > 0 ? ' WHERE s.testata_id = ?' : '') . ' ORDER BY s.data_scadenza IS NULL, s.data_scadenza ASC, s.id ASC LIMIT 500';
            $rows = $this->rows($sql, $periodical > 0 ? [$periodical] : []);

            return $this->ok($response, array_map(fn (array $r): array => $this->mapSubscription($r), $rows));
        } catch (\Throwable $e) {
            return $this->fail($response, 'subscriptions', $e);
        }
    }

    public function saveSubscription(ServerRequestInterface $request, ResponseInterface $response, ?int $id): ResponseInterface
    {
        $json = $this->json($request);
        try {
            $current = null;
            if ($id !== null) {
                $current = $this->rows('SELECT * FROM emeroteca_abbonamenti WHERE id = ?', [$id])[0] ?? null;
                if ($current === null) {
                    return $this->error($response, 'not_found', __('Abbonamento non trovato.'), 404);
                }
                $periodical = (int) $current['testata_id'];
            } else {
                $periodical = is_int($json['periodical_id'] ?? null) ? (int) $json['periodical_id'] : 0;
                if ($periodical <= 0) {
                    return $this->error($response, 'validation_error', __('Valore non valido.'), 422, ['field' => 'periodical_id']);
                }
            }
            if ($this->rows('SELECT id FROM emeroteca_testate WHERE id = ?', [$periodical]) === []) {
                return $this->error($response, 'not_found', __('Testata non trovata.'), 404);
            }
            // Full form: absent keys keep the stored value on PUT.
            $form = [];
            foreach (self::SUBSCRIPTION_FIELDS as $api => $column) {
                if (array_key_exists($api, $json)) {
                    $form[$column] = is_scalar($json[$api]) ? (string) $json[$api] : '';
                } else {
                    $form[$column] = $current !== null && $current[$column] !== null ? (string) $current[$column] : '';
                }
            }
            foreach (['auto_renew' => 'rinnovo_automatico', 'active' => 'attivo'] as $api => $column) {
                $on = array_key_exists($api, $json) ? $json[$api] === true : ($current !== null ? (int) $current[$column] === 1 : $api === 'active');
                if ($on) {
                    $form[$column] = '1';
                }
            }
            $before = (int) ($this->rows('SELECT COALESCE(MAX(id), 0) AS m FROM emeroteca_abbonamenti', [])[0]['m'] ?? 0);
            $outcome = $this->delegate(
                \App\Plugins\Emeroteca\Controllers\SubscriptionAdminController::class,
                $id === null ? 'createSubmit' : 'editSubmit',
                'POST',
                '/admin/periodicals/' . $periodical . '/subscriptions',
                $form,
                $id === null ? ['id' => (string) $periodical] : ['id' => (string) $periodical, 'sid' => (string) $id]
            );
            if (!$outcome->isRedirect()) {
                // The form re-rendered with its errors: nothing was saved.
                return $this->error($response, 'validation_error', __('Dati dell\'abbonamento non validi.'), 422);
            }
            $error = $outcome->flash('error_message');
            if ($error !== null) {
                return $this->error($response, 'conflict', $error, 409);
            }
            $savedId = $id ?? (int) ($this->rows('SELECT MAX(id) AS m FROM emeroteca_abbonamenti WHERE id > ? AND testata_id = ?', [$before, $periodical])[0]['m'] ?? 0);
            $row = $this->rows('SELECT s.*, t.titolo FROM emeroteca_abbonamenti s JOIN emeroteca_testate t ON t.id = s.testata_id WHERE s.id = ?', [$savedId])[0] ?? null;
            if ($row === null) {
                throw new \RuntimeException('subscription not resolvable');
            }

            return $this->ok($response, $this->mapSubscription($row), $id === null ? 201 : 200);
        } catch (\Throwable $e) {
            return $this->fail($response, 'subscription save', $e);
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    private function issueRow(int $id): ?array
    {
        return $this->rows(
            'SELECT f.*, a.testata_id, a.anno, t.titolo AS testata FROM emeroteca_fascicoli f
               JOIN emeroteca_annate a ON a.id = f.annata_id JOIN emeroteca_testate t ON t.id = a.testata_id WHERE f.id = ?',
            [$id]
        )[0] ?? null;
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function mapIssue(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'periodical_id' => (int) $r['testata_id'], 'periodical' => (string) $r['testata'],
            'year_id' => (int) $r['annata_id'], 'year' => (int) $r['anno'], 'number' => (string) $r['numero'],
            'date' => $r['data_pubblicazione'] !== null ? (string) $r['data_pubblicazione'] : null,
            'status' => (string) $r['stato'], 'claimed_at' => isset($r['reclamato_il']) ? (string) $r['reclamato_il'] : null,
            'claims' => (int) ($r['n_reclami'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function mapSubscription(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'periodical_id' => (int) $r['testata_id'], 'periodical' => (string) ($r['titolo'] ?? ''),
            'supplier' => (string) $r['fornitore'], 'cost' => $r['costo'] !== null ? (float) $r['costo'] : null,
            'currency' => $r['valuta'] !== null ? (string) $r['valuta'] : null,
            'start_date' => $r['data_inizio'] !== null ? (string) $r['data_inizio'] : null,
            'end_date' => $r['data_scadenza'] !== null ? (string) $r['data_scadenza'] : null,
            'auto_renew' => (int) $r['rinnovo_automatico'] === 1, 'active' => (int) $r['attivo'] === 1,
            'notes' => $r['note'] !== null && $r['note'] !== '' ? (string) $r['note'] : null,
        ];
    }

    /**
     * @param array<string, string> $body
     * @param array<string, string> $args
     * @param array<string, string> $query
     */
    private function delegate(string $class, string $method, string $verb, string $path, array $body, array $args = [], array $query = []): \App\Plugins\MobileApi\Support\DelegationOutcome
    {
        $plugin = $this->plugin;

        return \App\Plugins\MobileApi\Support\Delegation::run(static function () use ($plugin, $class, $method, $verb, $path, $body, $args, $query): ResponseInterface {
            $request = \App\Plugins\MobileApi\Support\Delegation::request($verb, $path, $body, $query);

            return $plugin->dispatch($class, $method, $request, new SlimResponse(), $args);
        });
    }

    /** @return array<string, mixed> */
    private function json(ServerRequestInterface $request): array
    {
        return \App\Plugins\MobileApi\Support\JsonBody::parse($request);
    }

    /**
     * @param list<int|string> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('prepare failed: ' . $this->db->error);
        }
        if ($params !== []) {
            $stmt->bind_param(implode('', array_map(static fn ($p): string => is_int($p) ? 'i' : 's', $params)), ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($result !== false && ($r = $result->fetch_assoc()) !== null) {
            $rows[] = $r;
        }
        $stmt->close();

        return $rows;
    }

    /** @param mixed $data */
    private function ok(ResponseInterface $response, $data, int $status = 200): ResponseInterface
    {
        return \App\Plugins\MobileApi\Support\ResponseEnvelope::success($response, $data, [], $status);
    }

    /** @param array<string, mixed> $meta */
    private function error(ResponseInterface $response, string $code, string $message, int $status, array $meta = []): ResponseInterface
    {
        return \App\Plugins\MobileApi\Support\ResponseEnvelope::error($response, $code, $message, $status, $meta);
    }

    private function fail(ResponseInterface $response, string $what, \Throwable $e): ResponseInterface
    {
        SecureLogger::error('[Emeroteca:mobile-staff] ' . $what . ' failed: ' . $e->getMessage());

        return $this->error($response, 'internal_error', __('Errore interno.'), 500);
    }

    /**
     * OpenAPI paths of the desk (appended by the bridge's mobile_api.openapi filter).
     *
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public static function extendOpenApi(array $doc): array
    {
        if (!self::supported()) {
            return $doc;
        }
        $tag = ['periodicals-staff'];
        $sec = [['bearerAuth' => []]];
        $id = static fn (string $n, string $d): array => ['name' => $n, 'in' => 'path', 'required' => true, 'description' => $d, 'schema' => ['type' => 'integer']];
        $op = static fn (string $summary, array $responses, array $extra = []): array => array_merge([
            'tags' => $tag, 'summary' => $summary, 'security' => $sec,
            'responses' => $responses + ['401' => ['description' => 'Token required.'], '403' => ['description' => '`forbidden`: admin or staff only.']],
        ], $extra);
        $json = static fn (array $props, array $required = []): array => ['required' => true, 'content' => ['application/json' => ['schema' => array_filter(['type' => 'object', 'properties' => $props, 'required' => $required ?: null])]]];
        $str = ['type' => 'string'];
        $subscription = ['supplier' => $str, 'cost' => ['type' => 'number'], 'currency' => $str, 'start_date' => ['type' => 'string', 'format' => 'date'],
            'end_date' => ['type' => 'string', 'format' => 'date'], 'auto_renew' => ['type' => 'boolean'], 'active' => ['type' => 'boolean'], 'notes' => $str];
        $doc['tags'][] = ['name' => 'periodicals-staff', 'description' => 'Periodicals desk for admin and staff accounts (emeroteca plugin).'];
        $doc['paths'] = array_merge(is_array($doc['paths'] ?? null) ? $doc['paths'] : [], [
            '/periodicals/staff/scan-lookup' => ['get' => $op('Resolve a scanned issue barcode (EAN-13 + add-on)', ['200' => ['description' => 'The desk lookup: {found, match: none|issue|periodical|ambiguous, action, issue, …}.'], '422' => ['description' => 'Empty code.']], [
                'parameters' => [['name' => 'code', 'in' => 'query', 'required' => true, 'schema' => $str], ['name' => 'periodical_id', 'in' => 'query', 'schema' => ['type' => 'integer']]],
            ])],
            '/periodicals/staff/issues/{id}/receive' => ['post' => $op('Mark an expected or claimed issue as received', ['200' => ['description' => 'The issue.'], '404' => ['description' => 'Unknown issue.'], '409' => ['description' => '`conflict`: not expected/claimed (message = server text).']], ['parameters' => [$id('id', 'Issue id')]])],
            '/periodicals/staff/issues/{id}/claim' => ['post' => $op('Claim a missing issue from the supplier', ['200' => ['description' => 'The issue.'], '404' => ['description' => 'Unknown issue.'], '409' => ['description' => '`conflict`.']], ['parameters' => [$id('id', 'Issue id')]])],
            '/periodicals/staff/years/{id}/issues' => ['post' => $op('Add an issue to a year', ['201' => ['description' => 'The new issue.'], '404' => ['description' => 'Unknown year.'], '422' => ['description' => '`validation_error` (message = server text).']], [
                'parameters' => [$id('id', 'Year id')],
                'requestBody' => $json(['number' => $str, 'date' => ['type' => 'string', 'format' => 'date'], 'status' => ['type' => 'string', 'enum' => self::STATUSES]], ['number']),
            ])],
            '/periodicals/staff/{id}/years' => ['post' => $op('Add a year (annata) to a periodical', ['201' => ['description' => 'The new year.'], '404' => ['description' => 'Unknown periodical.'], '422' => ['description' => '`validation_error`.']], [
                'parameters' => [$id('id', 'Periodical id')],
                'requestBody' => $json(['year' => ['type' => 'integer'], 'volume' => $str, 'bound' => ['type' => 'boolean']], ['year']),
            ])],
            '/periodicals/staff/subscriptions' => [
                'get' => $op('Subscriptions (optionally of one periodical)', ['200' => ['description' => 'Subscriptions by end date.']], ['parameters' => [['name' => 'periodical_id', 'in' => 'query', 'schema' => ['type' => 'integer']]]]),
                'post' => $op('Create a subscription', ['201' => ['description' => 'Created.'], '404' => ['description' => 'Unknown periodical.'], '422' => ['description' => 'Invalid data.']], [
                    'requestBody' => $json(['periodical_id' => ['type' => 'integer']] + $subscription, ['periodical_id', 'supplier']),
                ]),
            ],
            '/periodicals/staff/subscriptions/{id}' => ['put' => $op('Update a subscription (absent keys keep their value)', ['200' => ['description' => 'Updated.'], '404' => ['description' => 'Unknown subscription.'], '422' => ['description' => 'Invalid data.']], [
                'parameters' => [$id('id', 'Subscription id')], 'requestBody' => $json($subscription),
            ])],
        ]);

        return $doc;
    }
}
