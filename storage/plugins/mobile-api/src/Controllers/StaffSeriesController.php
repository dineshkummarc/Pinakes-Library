<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Controllers;

use App\Controllers\CollaneController;
use App\Models\SeriesRepository;
use App\Plugins\MobileApi\Support\Delegation;
use App\Plugins\MobileApi\Support\HttpCache;
use App\Plugins\MobileApi\Support\JsonBody;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Plugins\MobileApi\Support\StaffLoanReader;
use App\Plugins\MobileApi\Support\StaffPaging;
use App\Support\HtmlHelper;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * Series (collane) for the staff app (Mobile API 1.7.0): /staff/series*.
 *
 * The web series pages are keyed by NAME; the API is keyed by `collane.id`
 * and resolves the name before delegating. Reads come from SeriesRepository
 * (the same queries as /admin/series); every write is delegated to
 * CollaneController so the hierarchy rules (parent auto-created by name, cycle
 * guard, children re-attached on delete, merge on rename) stay the web's.
 */
final class StaffSeriesController
{
    private SeriesRepository $repo;

    /** @var array<string, int>|null collane.nome → id, loaded once per request */
    private ?array $idsByName = null;

    public function __construct(private mysqli $db)
    {
        $this->repo = new SeriesRepository($db);
    }

    // ─── Reads ──────────────────────────────────────────────────────────────

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            if (!$this->repo->hasCollaneTable()) {
                return $this->disabled($response);
            }
            $q = mb_strtolower(StaffPaging::query($request, 'q'));
            $flat = StaffPaging::query($request, 'flat') === '1';
            $nodes = [];
            foreach ($this->repo->listSeries() as $row) {
                $node = $this->mapRow($row);
                if ($node['id'] <= 0) {
                    continue;
                }
                if ($q !== '' && !str_contains(mb_strtolower($node['name']), $q)) {
                    continue;
                }
                $nodes[$node['id']] = $node;
            }
            $data = $flat ? array_values($nodes) : self::tree($nodes);

            return HttpCache::envelope($request, $response, 'staff-series', $data, ['count' => count($nodes)]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'series list', $e);
        }
    }

    public function detail(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        try {
            if (!$this->repo->hasCollaneTable()) {
                return $this->disabled($response);
            }
            $detail = $this->load($id);

            return $detail === null ? $this->notFound($response) : ResponseEnvelope::success($response, $detail);
        } catch (\Throwable $e) {
            return $this->fail($response, 'series detail', $e);
        }
    }

    public function suggest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = StaffPaging::query($request, 'q');
        if (mb_strlen($q) < 2) {
            return ResponseEnvelope::error($response, 'validation_error', __('Inserisci almeno 2 caratteri.'), 422, ['field' => 'q']);
        }
        $fields = ['name' => 'collana', 'parent' => 'serie_padre', 'group' => 'gruppo_serie', 'cycle' => 'ciclo_serie'];
        $field = StaffPaging::query($request, 'field');
        $field = $field === '' ? 'name' : $field;
        if (!isset($fields[$field])) {
            return ResponseEnvelope::error($response, 'validation_error', __('Valore non valido.'), 422, ['field' => 'field']);
        }
        try {
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new CollaneController())->searchApi(
                Delegation::request('GET', '/api/collane/search', [], ['q' => $q, 'field' => $fields[$field]]),
                new SlimResponse(),
                $db
            ));
            $names = array_values(array_filter($outcome->json, 'is_string'));

            return ResponseEnvelope::success($response, $names);
        } catch (\Throwable $e) {
            return $this->fail($response, 'series suggest', $e);
        }
    }

    // ─── Create / update ────────────────────────────────────────────────────

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            if (!$this->repo->hasCollaneTable()) {
                return $this->disabled($response);
            }
            $json = JsonBody::parse($request);
            $name = StaffPaging::text($json['name'] ?? null, 101) ?? '';
            if ($name === '' || mb_strlen($name) > 100) {
                return $this->invalid($response, 'name', __('Nome collana non valido'));
            }
            $existingId = $this->repo->findCollanaId($name);
            if ($existingId !== null) {
                return ResponseEnvelope::error($response, 'duplicate', __('Esiste già una collana con questo nome.'), 409, [
                    'existing' => ['id' => $existingId, 'name' => $name],
                ]);
            }
            $form = $this->form($json, ['nome' => $name]);
            if (isset($form['error_field'])) {
                return $this->invalid($response, $form['error_field'], $form['error_message']);
            }
            $outcome = $this->save($form);
            if ($outcome !== null) {
                return ResponseEnvelope::error($response, 'conflict', $outcome, 409);
            }
            $id = $this->repo->findCollanaId($name);
            $detail = $id !== null ? $this->load($id) : null;
            if ($detail === null) {
                throw new \RuntimeException('created series not readable');
            }

            return ResponseEnvelope::success($response, $detail, [], 201);
        } catch (\Throwable $e) {
            return $this->fail($response, 'series create', $e);
        }
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        try {
            if (!$this->repo->hasCollaneTable()) {
                return $this->disabled($response);
            }
            $row = $this->row($id);
            if ($row === null) {
                return $this->notFound($response);
            }
            $json = JsonBody::parse($request);
            if (array_key_exists('name', $json) && trim((string) (is_scalar($json['name']) ? $json['name'] : '')) !== (string) $row['nome']) {
                return $this->invalid($response, 'name', __('Per cambiare il nome usa la rinomina.'));
            }
            // saveDescription() is a FULL form: absent keys keep the stored value.
            $form = $this->form($json, ['nome' => (string) $row['nome']], $row);
            if (isset($form['error_field'])) {
                return $this->invalid($response, $form['error_field'], $form['error_message']);
            }
            $outcome = $this->save($form);
            if ($outcome !== null) {
                return ResponseEnvelope::error($response, 'conflict', $outcome, 409);
            }

            return $this->detailOr404($response, $id);
        } catch (\Throwable $e) {
            return $this->fail($response, 'series update', $e);
        }
    }

    /**
     * Map the JSON body onto the CollaneController::saveDescription() form.
     *
     * @param array<string, mixed> $json
     * @param array<string, string> $base
     * @param array<string, mixed>|null $current stored row (PUT merge)
     * @return array<string, string>|array{error_field:string, error_message:string}
     */
    private function form(array $json, array $base, ?array $current = null): array
    {
        $form = $base + [
            'tipo_collana' => (string) ($current['tipo'] ?? 'serie'),
            'serie_padre'  => (string) ($current['parent_nome'] ?? ''),
            'gruppo_serie' => (string) ($current['gruppo_serie'] ?? ''),
            'ciclo'        => (string) ($current['ciclo'] ?? ''),
            'ordine_ciclo' => isset($current['ordine_ciclo']) ? (string) $current['ordine_ciclo'] : '',
            'descrizione'  => (string) ($current['descrizione'] ?? ''),
            'is_completa'  => (int) ($current['is_completa'] ?? 0) === 1 ? '1' : '0',
        ];
        if (array_key_exists('type', $json)) {
            $type = $json['type'];
            if ($type !== null && (!is_string($type) || !array_key_exists($type, \App\Support\SeriesLabels::types()))) {
                return ['error_field' => 'type', 'error_message' => __('Valore non valido.')];
            }
            $form['tipo_collana'] = $type ?? 'serie';
        }
        if (array_key_exists('parent_id', $json) && $json['parent_id'] !== null) {
            $parentId = StaffPaging::id($json['parent_id']);
            $parent = $parentId > 0 ? $this->row($parentId) : null;
            if ($parent === null) {
                return ['error_field' => 'parent_id', 'error_message' => __('Collana padre non trovata.')];
            }
            $form['serie_padre'] = (string) $parent['nome'];
        } elseif (array_key_exists('parent_name', $json) || array_key_exists('parent_id', $json)) {
            $form['serie_padre'] = StaffPaging::text($json['parent_name'] ?? null, 100) ?? '';
        }
        foreach (['group' => 'gruppo_serie', 'cycle' => 'ciclo'] as $key => $column) {
            if (array_key_exists($key, $json)) {
                $form[$column] = StaffPaging::text($json[$key], 100) ?? '';
            }
        }
        if (array_key_exists('cycle_order', $json)) {
            $order = $json['cycle_order'];
            if ($order !== null && (!is_int($order) || $order < 0 || $order > 65535)) {
                return ['error_field' => 'cycle_order', 'error_message' => __('Valore non valido.')];
            }
            $form['ordine_ciclo'] = $order === null ? '' : (string) $order;
        }
        if (array_key_exists('description', $json)) {
            $form['descrizione'] = StaffPaging::text($json['description'], 10000) ?? '';
        }
        if (array_key_exists('complete', $json)) {
            $form['is_completa'] = filter_var($json['complete'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        return $form;
    }

    /**
     * @param array<string, string> $form
     * @return string|null the core error text, or null on success
     */
    private function save(array $form): ?string
    {
        $db = $this->db;
        $outcome = Delegation::run(static fn (): ResponseInterface => (new CollaneController())->saveDescription(
            Delegation::request('POST', '/admin/series/description', $form),
            new SlimResponse(),
            $db
        ));

        return $outcome->flash('error_message');
    }

    // ─── Rename / merge / delete ────────────────────────────────────────────

    public function rename(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        try {
            $row = $this->row($id);
            if ($row === null) {
                return $this->notFound($response);
            }
            $json = JsonBody::parse($request);
            $name = StaffPaging::text($json['name'] ?? null, 101) ?? '';
            if ($name === '' || mb_strlen($name) > 100) {
                return $this->invalid($response, 'name', __('Nome collana non valido'));
            }
            $mergeIfExists = filter_var($json['merge_if_exists'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $targetId = $this->repo->findCollanaId($name);
            $merged = $targetId !== null && $targetId !== $id;
            if ($merged && !$mergeIfExists) {
                return ResponseEnvelope::error($response, 'duplicate', __('Esiste già una collana con questo nome.'), 409, [
                    'existing' => ['id' => $targetId, 'name' => $name],
                ]);
            }
            $old = (string) $row['nome'];
            if ($old !== $name) {
                $db = $this->db;
                $outcome = Delegation::run(static fn (): ResponseInterface => (new CollaneController())->rename(
                    Delegation::request('POST', '/admin/series/rename', ['old_name' => $old, 'new_name' => $name]),
                    new SlimResponse(),
                    $db
                ));
                $error = $outcome->flash('error_message');
                if ($error !== null) {
                    return ResponseEnvelope::error($response, 'conflict', $error, 409);
                }
            }
            $resultId = $this->repo->findCollanaId($name) ?? $id;
            $detail = $this->load($resultId);
            if ($detail === null) {
                return $this->notFound($response);
            }

            return ResponseEnvelope::success($response, $detail, $merged ? ['merged' => true] : []);
        } catch (\Throwable $e) {
            return $this->fail($response, 'series rename', $e);
        }
    }

    public function merge(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        try {
            $targetId = StaffPaging::id(JsonBody::parse($request)['target_id'] ?? null);
            if ($targetId <= 0) {
                return $this->invalid($response, 'target_id', __('Parametri non validi per l\'unione'));
            }
            if ($targetId === $id) {
                return $this->invalid($response, 'target_id', __('Parametri non validi per l\'unione'));
            }
            $source = $this->row($id);
            $target = $this->row($targetId);
            if ($source === null || $target === null) {
                return $this->notFound($response);
            }
            $moved = count($this->repo->getBooksForSeries((string) $source['nome']));
            $db = $this->db;
            $sourceName = (string) $source['nome'];
            $targetName = (string) $target['nome'];
            $outcome = Delegation::run(static fn (): ResponseInterface => (new CollaneController())->merge(
                Delegation::request('POST', '/admin/series/merge', ['source' => $sourceName, 'target' => $targetName]),
                new SlimResponse(),
                $db
            ));
            $error = $outcome->flash('error_message');
            if ($error !== null) {
                return ResponseEnvelope::error($response, 'conflict', $error, 409);
            }

            return ResponseEnvelope::success($response, ['target' => ['id' => $targetId, 'name' => $targetName], 'books_moved' => $moved]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'series merge', $e);
        }
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        try {
            $row = $this->row($id);
            if ($row === null) {
                return $this->notFound($response);
            }
            $name = (string) $row['nome'];
            $books = count($this->repo->getBooksForSeries($name));
            $children = $this->count('SELECT COUNT(*) FROM collane WHERE parent_id = ?', $id);
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new CollaneController())->delete(
                Delegation::request('POST', '/admin/series/delete', ['nome' => $name]),
                new SlimResponse(),
                $db
            ));
            $error = $outcome->flash('error_message');
            if ($error !== null) {
                return ResponseEnvelope::error($response, 'conflict', $error, 409);
            }

            return ResponseEnvelope::success($response, ['id' => $id, 'deleted' => true, 'books_updated' => $books, 'children_reattached' => $children]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'series delete', $e);
        }
    }

    // ─── Membership ─────────────────────────────────────────────────────────

    public function assignBooks(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        try {
            $row = $this->row($id);
            if ($row === null) {
                return $this->notFound($response);
            }
            $ids = StaffPaging::ids(JsonBody::parse($request)['book_ids'] ?? null);
            if ($ids === null || $ids === [] || count($ids) > 200) {
                return $this->invalid($response, 'book_ids', __('Selezione non valida.'));
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM libri WHERE id IN ({$in}) AND deleted_at IS NULL");
            if ($stmt === false) {
                throw new \RuntimeException('book probe prepare failed');
            }
            $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
            $stmt->execute();
            $result = $stmt->get_result();
            $found = (int) (($result !== false ? $result->fetch_row() : null)[0] ?? 0);
            $stmt->close();
            if ($found !== count($ids)) {
                return ResponseEnvelope::error($response, 'not_found', __('Libro non trovato.'), 404, ['field' => 'book_ids']);
            }
            $db = $this->db;
            $raw = (string) json_encode(['book_ids' => $ids, 'collana' => (string) $row['nome']], JSON_UNESCAPED_UNICODE);
            $outcome = Delegation::run(static fn (): ResponseInterface => (new CollaneController())->bulkAssign(
                Delegation::request('POST', '/admin/series/bulk-assign', [], [], $raw),
                new SlimResponse(),
                $db
            ));
            if ($outcome->status !== 200 || ($outcome->json['success'] ?? false) !== true) {
                throw new \RuntimeException('bulkAssign answered HTTP ' . $outcome->status);
            }

            return ResponseEnvelope::success($response, ['assigned' => count($ids)]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'series assign', $e);
        }
    }

    public function updateBook(ServerRequestInterface $request, ResponseInterface $response, int $id, int $bookId): ResponseInterface
    {
        try {
            $row = $this->row($id);
            if ($row === null) {
                return $this->notFound($response);
            }
            $json = JsonBody::parse($request);
            $number = $json['series_number'] ?? null;
            if ($number !== null && !is_scalar($number)) {
                return $this->invalid($response, 'series_number', __('Valore non valido.'));
            }
            $number = $number === null ? '' : mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', (string) $number)), 0, 50);
            $membership = $this->membership($id, (string) $row['nome'], $bookId);
            if ($membership === null) {
                return ResponseEnvelope::error($response, 'not_found', __('Libro non in collana'), 404);
            }
            if ($membership === 'primary') {
                $db = $this->db;
                $raw = (string) json_encode(['book_id' => $bookId, 'numero_serie' => $number], JSON_UNESCAPED_UNICODE);
                $outcome = Delegation::run(static fn (): ResponseInterface => (new CollaneController())->updateOrder(
                    Delegation::request('POST', '/admin/series/order', [], [], $raw),
                    new SlimResponse(),
                    $db
                ));
                if ($outcome->status === 404) {
                    return ResponseEnvelope::error($response, 'not_found', __('Libro non in collana'), 404);
                }
                if ($outcome->status !== 200) {
                    throw new \RuntimeException('updateOrder answered HTTP ' . $outcome->status);
                }
            } else {
                // A secondary membership has its own number on libri_collane;
                // updateOrder() only writes the primary one.
                $value = $number === '' ? null : $number;
                $stmt = $this->db->prepare('UPDATE libri_collane SET numero_serie = ?, updated_at = NOW() WHERE libro_id = ? AND collana_id = ?');
                if ($stmt === false) {
                    throw new \RuntimeException('secondary order prepare failed');
                }
                $stmt->bind_param('sii', $value, $bookId, $id);
                $stmt->execute();
                $stmt->close();
                \App\Support\ContentCache::booksChanged();
            }

            return $this->detailOr404($response, $id);
        } catch (\Throwable $e) {
            return $this->fail($response, 'series order', $e);
        }
    }

    public function removeBook(ServerRequestInterface $request, ResponseInterface $response, int $id, int $bookId): ResponseInterface
    {
        try {
            $row = $this->row($id);
            if ($row === null || !$this->bookExists($bookId)) {
                return $this->notFound($response);
            }
            if ($this->membership($id, (string) $row['nome'], $bookId) !== null) {
                $db = $this->db;
                $name = (string) $row['nome'];
                Delegation::run(static fn (): ResponseInterface => (new CollaneController())->removeBook(
                    Delegation::request('POST', '/admin/series/remove-book', ['book_id' => (string) $bookId, 'collana' => $name]),
                    new SlimResponse(),
                    $db
                ));
            }

            return $this->detailOr404($response, $id);
        } catch (\Throwable $e) {
            return $this->fail($response, 'series remove book', $e);
        }
    }

    public function parentWork(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        try {
            $row = $this->row($id);
            if ($row === null) {
                return $this->notFound($response);
            }
            $title = StaffPaging::text(JsonBody::parse($request)['title'] ?? null, 256) ?? '';
            if ($title === '' || mb_strlen($title) > 255) {
                return $this->invalid($response, 'title', __('Parametri non validi'));
            }
            $name = (string) $row['nome'];
            if ($this->repo->getBooksForSeries($name) === []) {
                return ResponseEnvelope::error($response, 'conflict', __('Nessun libro nella collana'), 409, ['reason' => 'empty_series']);
            }
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new CollaneController())->createParentWork(
                Delegation::request('POST', '/admin/series/create-opera', ['collana' => $name, 'parent_title' => $title]),
                new SlimResponse(),
                $db
            ));
            $bookId = $outcome->locationId('#/admin/books/(\d+)$#');
            if ($bookId === null) {
                $error = $outcome->flash('error_message');
                if ($error !== null) {
                    return ResponseEnvelope::error($response, 'conflict', $error, 409);
                }
                throw new \RuntimeException('createParentWork answered ' . $outcome->status . ' ' . $outcome->location);
            }

            return ResponseEnvelope::success($response, ['book_id' => $bookId, 'title' => $title], [], 201);
        } catch (\Throwable $e) {
            return $this->fail($response, 'series parent work', $e);
        }
    }

    // ─── Read model ─────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null collane row + parent_nome */
    private function row(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT c.*, p.nome AS parent_nome FROM collane c LEFT JOIN collane p ON p.id = c.parent_id WHERE c.id = ? LIMIT 1');
        if ($stmt === false) {
            throw new \RuntimeException('series row prepare failed');
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row;
    }

    /** @return array<string, mixed>|null StaffSeriesDetail */
    private function load(int $id): ?array
    {
        $row = $this->row($id);
        if ($row === null) {
            return null;
        }
        $name = (string) $row['nome'];
        $books = $this->repo->getBooksForSeries($name);
        $ids = array_map(static fn (array $b): int => (int) $b['id'], $books);
        $years = [];
        $primary = [];
        if ($ids !== []) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach ($this->rows("SELECT id, anno_pubblicazione, collana FROM libri WHERE id IN ({$in}) AND deleted_at IS NULL", str_repeat('i', count($ids)), $ids) as $r) {
                $years[(int) $r['id']] = $r['anno_pubblicazione'] !== null ? (int) $r['anno_pubblicazione'] : null;
                if (trim((string) ($r['collana'] ?? '')) === $name) {
                    $primary[(int) $r['id']] = true;
                }
            }
            foreach ($this->rows("SELECT libro_id FROM libri_collane WHERE collana_id = ? AND is_principale = 1", 'i', [$id]) as $r) {
                $primary[(int) $r['libro_id']] = true;
            }
        }
        $numbers = [];
        $bookItems = [];
        foreach ($books as $b) {
            $bid = (int) $b['id'];
            $number = StaffLoanReader::str($b['numero_serie'] ?? null);
            if ($number !== null && preg_match('/^\d+$/', $number) === 1) {
                $numbers[] = (int) $number;
            }
            $bookItems[] = [
                'id'            => $bid,
                'title'         => HtmlHelper::decode((string) ($b['titolo'] ?? '')),
                'cover_url'     => absoluteUrl(StaffLoanReader::coverPath($b['copertina_url'] ?? null)),
                'series_number' => $number,
                'is_primary'    => isset($primary[$bid]),
                'year'          => $years[$bid] ?? null,
            ];
        }
        $related = [];
        foreach ($this->repo->getRelatedSeries($name) as $r) {
            $related[] = [
                'id'       => (int) ($r['id'] ?? 0),
                'name'     => (string) ($r['nome'] ?? ''),
                'type'     => StaffLoanReader::str($r['tipo'] ?? null),
                'relation' => (string) ($r['relation'] ?? 'sibling'),
            ];
        }
        $children = [];
        foreach ($this->rows('SELECT c.*, p.nome AS parent_nome FROM collane c LEFT JOIN collane p ON p.id = c.parent_id WHERE c.parent_id = ? ORDER BY c.nome', 'i', [$id]) as $child) {
            $children[] = $this->mapSeries($child, null);
        }

        $base = $this->mapSeries($row, [
            'book_count' => count($books),
            'min_number' => $numbers !== [] ? min($numbers) : null,
            'max_number' => $numbers !== [] ? max($numbers) : null,
        ]);
        $base['children'] = $children;

        return $base + [
            'description' => StaffLoanReader::str($row['descrizione'] ?? null),
            'books'       => $bookItems,
            'related'     => $related,
        ];
    }

    /**
     * @param array<string, mixed> $row collane row (+ parent_nome)
     * @param array{book_count:int, min_number:?int, max_number:?int}|null $stats
     * @return array<string, mixed>
     */
    private function mapSeries(array $row, ?array $stats): array
    {
        return [
            'id'          => (int) $row['id'],
            'name'        => (string) $row['nome'],
            'type'        => StaffLoanReader::str($row['tipo'] ?? null),
            'parent_id'   => isset($row['parent_id']) ? (int) $row['parent_id'] : null,
            'parent_name' => StaffLoanReader::str($row['parent_nome'] ?? null),
            'group'       => StaffLoanReader::str($row['gruppo_serie'] ?? null),
            'cycle'       => StaffLoanReader::str($row['ciclo'] ?? null),
            'cycle_order' => isset($row['ordine_ciclo']) ? (int) $row['ordine_ciclo'] : null,
            'complete'    => (int) ($row['is_completa'] ?? 0) === 1,
            'book_count'  => $stats['book_count'] ?? 0,
            'min_number'  => $stats['min_number'] ?? null,
            'max_number'  => $stats['max_number'] ?? null,
            'children'    => [],
        ];
    }

    /**
     * listSeries() row (keyed by name) → StaffSeries.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        if ($this->idsByName === null) {
            $this->idsByName = [];
            foreach ($this->rows('SELECT id, nome FROM collane', '', []) as $r) {
                $this->idsByName[(string) $r['nome']] = (int) $r['id'];
            }
        }
        $ids = $this->idsByName;
        $name = (string) ($row['collana'] ?? '');

        return $this->mapSeries([
            'id'           => $ids[$name] ?? 0,
            'nome'         => $name,
            'tipo'         => $row['tipo'] ?? null,
            'parent_id'    => $row['parent_id'] ?? null,
            'parent_nome'  => $row['parent_nome'] ?? null,
            'gruppo_serie' => $row['gruppo_serie'] ?? null,
            'ciclo'        => $row['ciclo'] ?? null,
            'ordine_ciclo' => $row['ordine_ciclo'] ?? null,
            'is_completa'  => $row['is_completa'] ?? 0,
        ], [
            'book_count' => (int) ($row['book_count'] ?? 0),
            'min_number' => isset($row['min_num']) ? (int) $row['min_num'] : null,
            'max_number' => isset($row['max_num']) ? (int) $row['max_num'] : null,
        ]);
    }

    /**
     * Nest the flat nodes by parent_id; a node whose parent is not in the set
     * (filtered out, or a cycle) becomes a root.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    private static function tree(array $nodes): array
    {
        $childrenOf = [];
        $roots = [];
        foreach ($nodes as $id => $node) {
            $parent = $node['parent_id'];
            if ($parent !== null && $parent !== $id && isset($nodes[$parent])) {
                $childrenOf[$parent][] = $id;
            } else {
                $roots[] = $id;
            }
        }
        $build = static function (int $id, array $seen) use (&$build, $nodes, $childrenOf): array {
            $node = $nodes[$id];
            $seen[$id] = true;
            foreach ($childrenOf[$id] ?? [] as $childId) {
                if (!isset($seen[$childId])) {
                    $node['children'][] = $build($childId, $seen);
                }
            }

            return $node;
        };

        return array_map(static fn (int $id): array => $build($id, []), $roots);
    }

    /** 'primary' | 'secondary' | null (not a member). */
    private function membership(int $seriesId, string $name, int $bookId): ?string
    {
        $legacy = $this->rows('SELECT collana FROM libri WHERE id = ? AND deleted_at IS NULL', 'i', [$bookId]);
        if ($legacy === []) {
            return null;
        }
        if (trim((string) ($legacy[0]['collana'] ?? '')) === $name) {
            return 'primary';
        }
        $rows = $this->rows('SELECT is_principale FROM libri_collane WHERE libro_id = ? AND collana_id = ?', 'ii', [$bookId, $seriesId]);
        if ($rows === []) {
            return null;
        }

        return (int) $rows[0]['is_principale'] === 1 ? 'primary' : 'secondary';
    }

    private function bookExists(int $bookId): bool
    {
        return $this->rows('SELECT id FROM libri WHERE id = ? AND deleted_at IS NULL', 'i', [$bookId]) !== [];
    }

    private function count(string $sql, int $id): int
    {
        $rows = $this->rows($sql, 'i', [$id]);

        return (int) (array_values($rows[0] ?? [0])[0] ?? 0);
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, string $types, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('prepare failed: ' . $this->db->error);
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
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

    private function detailOr404(ResponseInterface $response, int $id): ResponseInterface
    {
        $detail = $this->load($id);

        return $detail === null ? $this->notFound($response) : ResponseEnvelope::success($response, $detail);
    }

    private function disabled(ResponseInterface $response): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'feature_disabled', __('Funzione non disponibile su questa istanza.'), 404);
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'not_found', __('Collana non trovata.'), 404);
    }

    private function invalid(ResponseInterface $response, string $field, string $message): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'validation_error', $message, 422, ['field' => $field]);
    }

    private function fail(ResponseInterface $response, string $what, \Throwable $e): ResponseInterface
    {
        SecureLogger::error('[MobileApi] staff ' . $what . ' failed: ' . $e->getMessage());

        return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
    }
}
