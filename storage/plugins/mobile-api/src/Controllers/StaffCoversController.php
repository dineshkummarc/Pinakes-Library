<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Controllers;

use App\Controllers\BulkEnrichController;
use App\Controllers\LibriController;
use App\Plugins\MobileApi\Support\Delegation;
use App\Plugins\MobileApi\Support\JsonBody;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Plugins\MobileApi\Support\StaffPaging;
use App\Support\HtmlHelper;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * Missing covers and bulk enrichment for the staff app (Mobile API 1.7.0).
 *
 * Fetch / sync / batch / cron toggle are delegated to LibriController and
 * BulkEnrichController (the same scraping sources, local download and cache
 * invalidation as the admin pages). The missing-cover list uses the same
 * rule as LibriController::syncCovers(): an ISBN and no LOCAL cover file
 * (empty, placeholder, remote URL, or a local path whose file is gone).
 */
final class StaffCoversController
{
    public function __construct(private mysqli $db)
    {
    }

    public function missing(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $limit = StaffPaging::limit($request);
        $offset = StaffPaging::offset($request);
        try {
            $all = $this->missingCovers();
            $page = array_slice($all, $offset, $limit);

            return ResponseEnvelope::success(
                $response,
                $page,
                StaffPaging::meta(count($page), $limit, $offset, $offset + $limit < count($all), count($all))
            );
        } catch (\Throwable $e) {
            return $this->fail($response, 'missing covers', $e);
        }
    }

    public function fetch(ServerRequestInterface $request, ResponseInterface $response, int $bookId): ResponseInterface
    {
        try {
            if (!$this->bookExists($bookId)) {
                return ResponseEnvelope::error($response, 'not_found', __('Libro non trovato.'), 404);
            }
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new LibriController())->fetchCover(
                Delegation::request('POST', '/api/libri/' . $bookId . '/fetch-cover'),
                new SlimResponse(),
                $db,
                $bookId
            ));
            if ($outcome->status === 404) {
                return ResponseEnvelope::error($response, 'not_found', __('Libro non trovato.'), 404);
            }
            if ($outcome->status === 502) {
                return ResponseEnvelope::error($response, 'cover_download_failed', (string) ($outcome->json['error'] ?? __('Download copertina fallito')), 502);
            }
            if ($outcome->status !== 200 || ($outcome->json['success'] ?? false) !== true) {
                throw new \RuntimeException('fetchCover answered HTTP ' . $outcome->status);
            }
            $fetched = ($outcome->json['fetched'] ?? false) === true;
            $cover = is_string($outcome->json['cover_url'] ?? null) ? (string) $outcome->json['cover_url'] : null;
            if ($cover === null) {
                $cover = $this->coverOf($bookId);
            }

            return ResponseEnvelope::success($response, [
                'fetched'   => $fetched,
                'cover_url' => $cover !== null && $cover !== '' ? (preg_match('#^https?://#i', $cover) === 1 ? $cover : absoluteUrl($cover)) : null,
                'reason'    => $fetched ? null : (is_string($outcome->json['reason'] ?? null) ? (string) $outcome->json['reason'] : null),
            ]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'cover fetch', $e);
        }
    }

    public function sync(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new LibriController())->syncCovers(
                Delegation::request('POST', '/admin/books/sync-covers'),
                new SlimResponse(),
                $db
            ));
            if ($outcome->status !== 200 || ($outcome->json['success'] ?? false) !== true) {
                throw new \RuntimeException('syncCovers answered HTTP ' . $outcome->status);
            }

            return ResponseEnvelope::success($response, [
                'synced'  => (int) ($outcome->json['synced'] ?? 0),
                'skipped' => (int) ($outcome->json['skipped'] ?? 0),
                'errors'  => (int) ($outcome->json['errors'] ?? 0),
                'total'   => (int) ($outcome->json['total'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'cover sync', $e);
        }
    }

    public function enrichOverview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $service = new \App\Services\BulkEnrichmentService($this->db);
            $stats = $service->getStats();

            return ResponseEnvelope::success($response, [
                'stats' => [
                    'total_with_isbn'     => $stats['total_with_isbn'],
                    'missing_cover'       => $stats['missing_cover'],
                    'missing_description' => $stats['missing_description'],
                    'pending'             => $stats['pending'],
                ],
                'cron_enabled' => $service->isEnabled(),
            ]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'enrich overview', $e);
        }
    }

    public function enrichBatch(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $db = $this->db;
            $outcome = Delegation::run(static fn (): ResponseInterface => (new BulkEnrichController())->start(
                Delegation::request('POST', '/admin/books/bulk-enrich/start'),
                new SlimResponse(),
                $db
            ));
            $results = is_array($outcome->json['results'] ?? null) ? $outcome->json['results'] : null;
            if ($outcome->status !== 200 || $results === null) {
                return ResponseEnvelope::error($response, 'internal_error', (string) ($outcome->json['error'] ?? __('Errore interno.')), 500);
            }
            $details = [];
            foreach ((array) ($results['details'] ?? []) as $d) {
                if (is_array($d)) {
                    $details[] = [
                        'book_id'        => (int) ($d['book_id'] ?? 0),
                        'status'         => (string) ($d['status'] ?? ''),
                        'fields_updated' => array_values(array_map('strval', (array) ($d['fields_updated'] ?? []))),
                    ];
                }
            }

            return ResponseEnvelope::success($response, [
                'processed' => (int) ($results['processed'] ?? 0),
                'enriched'  => (int) ($results['enriched'] ?? 0),
                'not_found' => (int) ($results['not_found'] ?? 0),
                'skipped'   => (int) ($results['skipped'] ?? 0),
                'errors'    => (int) ($results['errors'] ?? 0),
                'details'   => $details,
            ]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'enrich batch', $e);
        }
    }

    public function cron(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $json = JsonBody::parse($request);
        if (!array_key_exists('enabled', $json) || !is_bool($json['enabled'])) {
            return ResponseEnvelope::error($response, 'validation_error', __('Valore non valido.'), 422, ['field' => 'enabled']);
        }
        try {
            $db = $this->db;
            $enabled = $json['enabled'];
            $outcome = Delegation::run(static fn (): ResponseInterface => (new BulkEnrichController())->toggle(
                Delegation::request('POST', '/admin/books/bulk-enrich/toggle', ['enabled' => $enabled ? '1' : '0']),
                new SlimResponse(),
                $db
            ));
            if ($outcome->status !== 200) {
                throw new \RuntimeException('toggle answered HTTP ' . $outcome->status);
            }

            return ResponseEnvelope::success($response, ['enabled' => (new \App\Services\BulkEnrichmentService($this->db))->isEnabled()]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'enrich cron', $e);
        }
    }

    /**
     * @return list<array{id:int, title:string, isbn13:?string}>
     */
    private function missingCovers(): array
    {
        $result = $this->db->query(
            "SELECT id, isbn13, isbn10, titolo, copertina_url FROM libri
              WHERE (isbn13 IS NOT NULL AND isbn13 != '' OR isbn10 IS NOT NULL AND isbn10 != '')
                AND deleted_at IS NULL
              ORDER BY id DESC"
        );
        if (!$result instanceof \mysqli_result) {
            throw new \RuntimeException('missing covers query failed');
        }
        $baseDir = realpath(dirname(__DIR__, 5) . '/public/uploads/copertine');
        $out = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $url = (string) ($row['copertina_url'] ?? '');
            $needs = $url === '' || str_contains($url, 'placeholder.jpg') || preg_match('#^https?://#i', $url) === 1;
            if (!$needs) {
                if ($baseDir === false) {
                    $needs = true;
                } else {
                    $resolved = realpath($baseDir . DIRECTORY_SEPARATOR . basename($url));
                    $needs = $resolved === false || !str_starts_with($resolved, $baseDir . DIRECTORY_SEPARATOR) || !is_file($resolved);
                }
            }
            if ($needs) {
                $isbn = trim((string) ($row['isbn13'] ?? ''));
                $out[] = [
                    'id'     => (int) $row['id'],
                    'title'  => HtmlHelper::decode((string) $row['titolo']),
                    'isbn13' => $isbn !== '' ? $isbn : null,
                ];
            }
        }
        $result->free();

        return $out;
    }

    private function coverOf(int $bookId): ?string
    {
        $stmt = $this->db->prepare('SELECT copertina_url FROM libri WHERE id = ? AND deleted_at IS NULL');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result !== false ? $result->fetch_row() : null;
        $stmt->close();

        return isset($row[0]) ? (string) $row[0] : null;
    }

    private function bookExists(int $bookId): bool
    {
        return $this->exists($bookId);
    }

    private function exists(int $bookId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM libri WHERE id = ? AND deleted_at IS NULL');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('i', $bookId);
        $stmt->execute();
        $result = $stmt->get_result();
        $found = $result !== false && $result->fetch_row() !== null;
        $stmt->close();

        return $found;
    }

    private function fail(ResponseInterface $response, string $what, \Throwable $e): ResponseInterface
    {
        SecureLogger::error('[MobileApi] staff ' . $what . ' failed: ' . $e->getMessage());

        return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
    }
}
