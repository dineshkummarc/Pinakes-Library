<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Controllers;

use App\Plugins\MobileApi\Support\Delegation;
use App\Plugins\MobileApi\Support\DelegationOutcome;
use App\Plugins\MobileApi\Support\JsonBody;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Plugins\MobileApi\Support\StaffPaging;
use App\Support\HookManager;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * FRBR works (opere) and expressions for the staff app (Mobile API 1.7.0,
 * Tier 2) — only while the frbr-lrm plugin is active.
 *
 * Writes go through the frbr-lrm admin actions (adminStoreAction, …,
 * attachBookToOperaAction); the API maps its English keys to their form keys
 * and merges a PUT over the stored row, because those forms are full forms.
 * Reads use the plugin's repositories.
 */
final class StaffWorksController
{
    /** API key → opere form key. */
    public const WORK_FIELDS = [
        'uniform_title'     => 'titolo_uniforme',
        'original_title'    => 'titolo_originale',
        'main_author_id'    => 'autore_principale_id',
        'created_from'      => 'data_creazione_da',
        'created_to'        => 'data_creazione_a',
        'original_language' => 'lingua_originale',
        'viaf_work_id'      => 'viaf_work_id',
        'wikidata_id'       => 'wikidata_id',
        'notes'             => 'note',
    ];

    /** API key → espressioni form key. */
    public const EXPRESSION_FIELDS = [
        'type'          => 'tipo_espressione',
        'language'      => 'lingua',
        'title'         => 'titolo_espressione',
        'year'          => 'anno_espressione',
        'translator_id' => 'traduttore_autore_id',
        'curator_id'    => 'curatore_autore_id',
        'reviser_id'    => 'revisore_autore_id',
        'notes'         => 'note',
    ];

    public const EXPRESSION_TYPES = ['testo', 'traduzione', 'revisione', 'adattamento', 'edizione_critica', 'audio', 'altro'];

    public function __construct(private mysqli $db, private HookManager $hooks)
    {
    }

    public function search(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        try {
            $q = mb_substr(StaffPaging::query($request, 'q'), 0, 200);
            $limit = StaffPaging::limit($request, 30, 100);
            $repo = new \App\Plugins\FrbrLrm\OpereRepository($this->db);
            $rows = $q === '' ? array_slice($repo->list(500), 0, $limit) : array_map(
                fn (array $hit): array => (array) $repo->getById((int) $hit['id']),
                $repo->search($q, $limit)
            );
            $items = array_map(fn (array $r): array => $this->mapWork($r), array_filter($rows, static fn (array $r): bool => isset($r['id'])));

            return ResponseEnvelope::success($response, array_values($items), ['count' => count($items), 'limit' => $limit, 'next_cursor' => null, 'has_more' => false]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'works search', $e);
        }
    }

    public function detail(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        try {
            $work = $this->loadWork($id);

            return $work === null ? $this->notFound($response) : ResponseEnvelope::success($response, $work);
        } catch (\Throwable $e) {
            return $this->fail($response, 'work detail', $e);
        }
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        $form = $this->workForm(JsonBody::parse($request), null);
        if (isset($form['error_field'])) {
            return $this->invalid($response, (string) $form['error_field']);
        }
        try {
            $outcome = $this->delegate(static fn ($p): ResponseInterface => $p->adminStoreAction(
                Delegation::request('POST', '/admin/opere', $form),
                new SlimResponse()
            ));
            $id = $outcome->locationId('#/admin/opere/(\d+)$#');
            if ($id === null) {
                return $this->invalid($response, 'uniform_title');
            }
            $work = $this->loadWork($id);

            return $work === null ? $this->notFound($response) : ResponseEnvelope::success($response, $work, [], 201);
        } catch (\Throwable $e) {
            return $this->fail($response, 'work create', $e);
        }
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        try {
            $row = (new \App\Plugins\FrbrLrm\OpereRepository($this->db))->getById($id);
            if ($row === null) {
                return $this->notFound($response);
            }
            $form = $this->workForm(JsonBody::parse($request), $row);
            if (isset($form['error_field'])) {
                return $this->invalid($response, (string) $form['error_field']);
            }
            $outcome = $this->delegate(static fn ($p): ResponseInterface => $p->adminUpdateAction(
                Delegation::request('POST', '/admin/opere/' . $id . '/edit', $form),
                new SlimResponse(),
                $id
            ));
            if ($this->rejected($outcome)) {
                return ResponseEnvelope::error($response, 'validation_error', $outcome->flash('error_message') ?? __('Valore non valido.'), 422);
            }
            $work = $this->loadWork($id);

            return $work === null ? $this->notFound($response) : ResponseEnvelope::success($response, $work);
        } catch (\Throwable $e) {
            return $this->fail($response, 'work update', $e);
        }
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        try {
            if (!(new \App\Plugins\FrbrLrm\OpereRepository($this->db))->exists($id)) {
                return $this->notFound($response);
            }
            $this->delegate(static fn ($p): ResponseInterface => $p->adminDeleteAction(
                Delegation::request('POST', '/admin/opere/' . $id . '/delete'),
                new SlimResponse(),
                $id
            ));
            // The action redirects whatever happened: the record is the truth.
            if ((new \App\Plugins\FrbrLrm\OpereRepository($this->db))->exists($id)) {
                throw new \RuntimeException('work still present after delete');
            }

            return ResponseEnvelope::success($response, ['id' => $id, 'deleted' => true]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'work delete', $e);
        }
    }

    public function expressions(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        try {
            if (!(new \App\Plugins\FrbrLrm\OpereRepository($this->db))->exists($id)) {
                return $this->notFound($response);
            }
            $rows = (new \App\Plugins\FrbrLrm\EspressioniRepository($this->db))->listForOpera($id);

            return ResponseEnvelope::success($response, array_map(fn (array $r): array => $this->mapExpression($r), $rows));
        } catch (\Throwable $e) {
            return $this->fail($response, 'expressions list', $e);
        }
    }

    public function createExpression(ServerRequestInterface $request, ResponseInterface $response, int $workId): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        $form = $this->expressionForm(JsonBody::parse($request), null);
        if (isset($form['error_field'])) {
            return $this->invalid($response, (string) $form['error_field']);
        }
        try {
            if (!(new \App\Plugins\FrbrLrm\OpereRepository($this->db))->exists($workId)) {
                return $this->notFound($response);
            }
            $outcome = $this->delegate(static fn ($p): ResponseInterface => $p->adminEspressioneStoreAction(
                Delegation::request('POST', '/admin/opere/' . $workId . '/espressioni', $form),
                new SlimResponse(),
                $workId
            ));
            // The redirect lands on the work, so the action hands its own insert
            // id over in a header: two concurrent creates each get their row.
            $id = (int) $outcome->response->getHeaderLine(\App\Plugins\FrbrLrm\FrbrLrmPlugin::CREATED_ID_HEADER);
            $row = $id > 0 ? (new \App\Plugins\FrbrLrm\EspressioniRepository($this->db))->getById($id) : null;
            if ($row === null) {
                throw new \RuntimeException('new expression not resolvable');
            }

            return ResponseEnvelope::success($response, $this->mapExpression($row), [], 201);
        } catch (\Throwable $e) {
            return $this->fail($response, 'expression create', $e);
        }
    }

    public function updateExpression(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        try {
            $repo = new \App\Plugins\FrbrLrm\EspressioniRepository($this->db);
            $row = $repo->getById($id);
            if ($row === null) {
                return $this->notFound($response);
            }
            $form = $this->expressionForm(JsonBody::parse($request), $row);
            if (isset($form['error_field'])) {
                return $this->invalid($response, (string) $form['error_field']);
            }
            $outcome = $this->delegate(static fn ($p): ResponseInterface => $p->adminEspressioneUpdateAction(
                Delegation::request('POST', '/admin/espressioni/' . $id . '/edit', $form),
                new SlimResponse(),
                $id
            ));
            if ($this->rejected($outcome)) {
                return ResponseEnvelope::error($response, 'validation_error', $outcome->flash('error_message') ?? __('Valore non valido.'), 422);
            }
            $after = $repo->getById($id);

            return $after === null ? $this->notFound($response) : ResponseEnvelope::success($response, $this->mapExpression($after));
        } catch (\Throwable $e) {
            return $this->fail($response, 'expression update', $e);
        }
    }

    public function deleteExpression(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        try {
            if ((new \App\Plugins\FrbrLrm\EspressioniRepository($this->db))->getById($id) === null) {
                return $this->notFound($response);
            }
            $this->delegate(static fn ($p): ResponseInterface => $p->adminEspressioneDeleteAction(
                Delegation::request('POST', '/admin/espressioni/' . $id . '/delete'),
                new SlimResponse(),
                $id
            ));
            if ((new \App\Plugins\FrbrLrm\EspressioniRepository($this->db))->getById($id) !== null) {
                throw new \RuntimeException('expression still present after delete');
            }

            return ResponseEnvelope::success($response, ['id' => $id, 'deleted' => true]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'expression delete', $e);
        }
    }

    /** POST /staff/books/{id}/work {work_id|null, expression_id?} */
    public function attachBook(ServerRequestInterface $request, ResponseInterface $response, int $bookId): ResponseInterface
    {
        if (!$this->active()) {
            return $this->disabled($response);
        }
        $json = JsonBody::parse($request);
        if (!array_key_exists('work_id', $json) || ($json['work_id'] !== null && StaffPaging::id($json['work_id']) <= 0)) {
            return $this->invalid($response, 'work_id');
        }
        // Present means "set": null clears the expression, anything else must
        // be a positive id. A malformed value ("abc", "12x", -3, true) would
        // otherwise collapse to 0 and silently clear the book's expression.
        if (array_key_exists('expression_id', $json) && $json['expression_id'] !== null && StaffPaging::id($json['expression_id']) <= 0) {
            return $this->invalid($response, 'expression_id');
        }
        try {
            if ($this->scalar('SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL', [$bookId]) === null) {
                return ResponseEnvelope::error($response, 'not_found', __('Libro non trovato.'), 404);
            }
            $body = ['opera_id' => $json['work_id'] === null ? '' : (string) StaffPaging::id($json['work_id'])];
            if (array_key_exists('expression_id', $json)) {
                $body['espressione_id'] = $json['expression_id'] === null ? '' : (string) StaffPaging::id($json['expression_id']);
            }
            $outcome = $this->delegate(static fn ($p): ResponseInterface => $p->attachBookToOperaAction(
                Delegation::request('POST', '/admin/books/' . $bookId . '/attach-opera', $body)->withHeader('Accept', 'application/json'),
                new SlimResponse(),
                $bookId
            ));
            if ($outcome->status === 404) {
                return $this->notFound($response);
            }
            if ($outcome->status === 422) {
                return $this->invalid($response, 'expression_id');
            }
            if ($outcome->status !== 200) {
                throw new \RuntimeException('attachBookToOperaAction answered HTTP ' . $outcome->status);
            }
            $current = (new \App\Plugins\FrbrLrm\OpereRepository($this->db))->getForBook($bookId);

            return ResponseEnvelope::success($response, [
                'book_id'       => $bookId,
                'work'          => $current === null ? null : ['id' => (int) $current['id'], 'uniform_title' => (string) $current['titolo_uniforme']],
                'expression_id' => $current !== null && $current['espressione_id'] !== null ? (int) $current['espressione_id'] : null,
            ]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'book work attach', $e);
        }
    }

    // ─── Mapping ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $json
     * @param array<string, mixed>|null $row
     * @return array<string, string>|array{error_field:string}
     */
    private function workForm(array $json, ?array $row): array
    {
        $form = [];
        foreach (self::WORK_FIELDS as $api => $column) {
            if (array_key_exists($api, $json)) {
                $value = $json[$api];
                if ($value !== null && !is_scalar($value)) {
                    return ['error_field' => $api];
                }
                if (in_array($api, ['main_author_id', 'created_from', 'created_to'], true) && $value !== null && !is_int($value)) {
                    return ['error_field' => $api];
                }
                $form[$column] = $value === null ? '' : (string) $value;
            } else {
                $form[$column] = $row !== null && $row[$column] !== null ? (string) $row[$column] : '';
            }
        }
        if (trim($form['titolo_uniforme']) === '' || mb_strlen($form['titolo_uniforme']) > 255) {
            return ['error_field' => 'uniform_title'];
        }

        return $form;
    }

    /**
     * @param array<string, mixed> $json
     * @param array<string, mixed>|null $row
     * @return array<string, string>|array{error_field:string}
     */
    private function expressionForm(array $json, ?array $row): array
    {
        $form = [];
        foreach (self::EXPRESSION_FIELDS as $api => $column) {
            if (array_key_exists($api, $json)) {
                $value = $json[$api];
                if ($value !== null && !is_scalar($value)) {
                    return ['error_field' => $api];
                }
                if (in_array($api, ['year', 'translator_id', 'curator_id', 'reviser_id'], true) && $value !== null && !is_int($value)) {
                    return ['error_field' => $api];
                }
                $form[$column] = $value === null ? '' : (string) $value;
            } else {
                $form[$column] = $row !== null && $row[$column] !== null ? (string) $row[$column] : '';
            }
        }
        if ($form['tipo_espressione'] === '') {
            $form['tipo_espressione'] = 'testo';
        }
        if (!in_array($form['tipo_espressione'], self::EXPRESSION_TYPES, true)) {
            return ['error_field' => 'type'];
        }

        return $form;
    }

    /** @return array<string, mixed>|null */
    private function loadWork(int $id): ?array
    {
        $repo = new \App\Plugins\FrbrLrm\OpereRepository($this->db);
        $row = $repo->getById($id);
        if ($row === null) {
            return null;
        }
        $editions = array_map(static fn (array $e): array => [
            'id' => (int) $e['id'], 'title' => (string) $e['titolo'], 'year' => $e['anno_pubblicazione'] !== null ? (int) $e['anno_pubblicazione'] : null,
            'isbn13' => $e['isbn13'] ?? null, 'expression_id' => $e['espressione_id'] !== null ? (int) $e['espressione_id'] : null,
        ], $repo->editionsForOpera($id));

        return $this->mapWork($row) + [
            'editions'    => $editions,
            'expressions' => array_map(fn (array $r): array => $this->mapExpression($r), (new \App\Plugins\FrbrLrm\EspressioniRepository($this->db))->listForOpera($id)),
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function mapWork(array $r): array
    {
        $out = ['id' => (int) $r['id']];
        foreach (self::WORK_FIELDS as $api => $column) {
            $value = $r[$column] ?? null;
            $out[$api] = in_array($api, ['main_author_id', 'created_from', 'created_to'], true)
                ? ($value !== null ? (int) $value : null)
                : ($value !== null && $value !== '' ? (string) $value : null);
        }
        $out['main_author_name'] = isset($r['autore_nome']) && $r['autore_nome'] !== '' ? (string) $r['autore_nome'] : null;
        $out['slug'] = (string) ($r['slug'] ?? '');
        $out['editions_count'] = isset($r['num_edizioni']) ? (int) $r['num_edizioni'] : null;

        return $out;
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function mapExpression(array $r): array
    {
        $out = ['id' => (int) $r['id'], 'work_id' => (int) $r['opera_id']];
        foreach (self::EXPRESSION_FIELDS as $api => $column) {
            $value = $r[$column] ?? null;
            $out[$api] = in_array($api, ['year', 'translator_id', 'curator_id', 'reviser_id'], true)
                ? ($value !== null ? (int) $value : null)
                : ($value !== null && $value !== '' ? (string) $value : null);
        }

        return $out;
    }

    // ─── Plumbing ───────────────────────────────────────────────────────────

    /**
     * @param callable(\App\Plugins\FrbrLrm\FrbrLrmPlugin): ResponseInterface $action
     */
    private function delegate(callable $action): DelegationOutcome
    {
        $plugin = new \App\Plugins\FrbrLrm\FrbrLrmPlugin($this->db, $this->hooks);

        return Delegation::run(static fn (): ResponseInterface => $action($plugin));
    }

    private function active(): bool
    {
        if (!(new CollectionsController($this->db, $this->hooks))->available('frbr-lrm')) {
            return false;
        }
        if (!class_exists(\App\Plugins\FrbrLrm\OpereRepository::class, false)) {
            $wrapper = dirname(__DIR__, 3) . '/frbr-lrm/wrapper.php';
            if (!is_file($wrapper)) {
                return false;
            }
            require_once dirname(__DIR__, 3) . '/frbr-lrm/FrbrLrmPlugin.php';
            require_once dirname(__DIR__, 3) . '/frbr-lrm/OpereRepository.php';
            require_once dirname(__DIR__, 3) . '/frbr-lrm/EspressioniRepository.php';
        }

        return true;
    }

    /** @param list<int> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('prepare failed');
        }
        if ($params !== []) {
            $stmt->bind_param(str_repeat('i', count($params)), ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_row() : null;
        $stmt->close();

        return $row[0] ?? null;
    }

    /** A web action that did not redirect, or left an error flash, refused the write. */
    private function rejected(DelegationOutcome $outcome): bool
    {
        return !$outcome->isRedirect() || $outcome->flash('error_message') !== null;
    }

    private function invalid(ResponseInterface $response, string $field): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'validation_error', __('Valore non valido.'), 422, ['field' => $field]);
    }

    private function disabled(ResponseInterface $response): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'feature_disabled', __('Funzione non disponibile su questa istanza.'), 404);
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'not_found', __('Opera non trovata'), 404);
    }

    private function fail(ResponseInterface $response, string $what, \Throwable $e): ResponseInterface
    {
        SecureLogger::error('[MobileApi] staff ' . $what . ' failed: ' . $e->getMessage());

        return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
    }
}
