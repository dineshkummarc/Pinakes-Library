<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Controllers;

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
 * Duplicate merges for the staff app (Mobile API 1.7.0): author duplicate
 * groups, author / publisher merge (delegated to MergeHelper, the same JSON
 * endpoint the web lists use) and genre merge (GenereRepository::merge(),
 * which owns its transaction, the `genre.merging` hook and the cache bump).
 */
final class StaffMergeController
{
    public function __construct(private mysqli $db)
    {
    }

    public function authorDuplicates(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $groups = (new \App\Models\AuthorRepository($this->db))->findDuplicates();
            $ids = [];
            foreach ($groups as $group) {
                foreach ($group as $author) {
                    $ids[] = (int) $author['id'];
                }
            }
            $counts = [];
            if ($ids !== []) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $this->db->prepare(
                    "SELECT la.autore_id, COUNT(DISTINCT la.libro_id) AS n FROM libri_autori la
                       JOIN libri l ON l.id = la.libro_id AND l.deleted_at IS NULL
                      WHERE la.autore_id IN ({$in}) GROUP BY la.autore_id"
                );
                if ($stmt === false) {
                    throw new \RuntimeException('count prepare failed');
                }
                $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($result !== false && ($r = $result->fetch_assoc()) !== null) {
                    $counts[(int) $r['autore_id']] = (int) $r['n'];
                }
                $stmt->close();
            }
            $data = array_map(static fn (array $group): array => array_map(static fn (array $a): array => [
                'id'          => (int) $a['id'],
                'name'        => HtmlHelper::decode((string) $a['nome']),
                'books_count' => $counts[(int) $a['id']] ?? 0,
            ], $group), $groups);

            return ResponseEnvelope::success($response, $data, ['count' => count($data)]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'author duplicates', $e);
        }
    }

    public function mergeAuthors(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->merge($request, $response, 'autori');
    }

    public function mergePublishers(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->merge($request, $response, 'editori');
    }

    private function merge(ServerRequestInterface $request, ResponseInterface $response, string $entity): ResponseInterface
    {
        $json = JsonBody::parse($request);
        $ids = StaffPaging::ids($json['ids'] ?? null);
        if ($ids === null || count($ids) < 2) {
            return ResponseEnvelope::error($response, 'validation_error', __('Selezione non valida.'), 422, ['field' => 'ids']);
        }
        $body = ['ids' => $ids];
        if (array_key_exists('primary_id', $json) && $json['primary_id'] !== null) {
            $primary = StaffPaging::id($json['primary_id']);
            if (!in_array($primary, $ids, true)) {
                return ResponseEnvelope::error($response, 'validation_error', __('Selezione non valida.'), 422, ['field' => 'primary_id']);
            }
            $body['primary_id'] = $primary;
        }
        $newName = StaffPaging::text($json['new_name'] ?? null, 255);
        if ($newName !== null && $newName !== '') {
            $body['new_name'] = $newName;
        }
        try {
            // An id merged away earlier (a retried merge) would otherwise be
            // silently skipped by the repository: refuse it explicitly.
            $table = $entity === 'autori' ? 'autori' : 'editori';
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare("SELECT id FROM {$table} WHERE id IN ({$in})");
            if ($stmt === false) {
                throw new \RuntimeException('merge probe prepare failed');
            }
            $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
            $stmt->execute();
            $result = $stmt->get_result();
            $found = [];
            while ($result !== false && ($r = $result->fetch_row()) !== null) {
                $found[] = (int) $r[0];
            }
            $stmt->close();
            $missing = array_values(array_diff($ids, $found));
            if ($missing !== []) {
                return ResponseEnvelope::error($response, 'merge_error', __('Alcuni elementi da unire non esistono più.'), 409, ['missing' => $missing]);
            }

            $db = $this->db;
            $raw = (string) json_encode($body, JSON_UNESCAPED_UNICODE);
            $outcome = Delegation::run(static fn (): ResponseInterface => \App\Support\MergeHelper::handleMergeRequest(
                Delegation::request('POST', '/api/' . $entity . '/merge', [], [], $raw),
                new SlimResponse(),
                $db,
                $entity
            ));
            $message = is_string($outcome->json['error'] ?? null) ? (string) $outcome->json['error'] : '';
            if ($outcome->status === 400) {
                return ResponseEnvelope::error($response, 'validation_error', $message !== '' ? $message : __('Selezione non valida.'), 422, ['field' => 'ids']);
            }
            if ($outcome->status !== 200) {
                throw new \RuntimeException('merge answered HTTP ' . $outcome->status);
            }
            if (($outcome->json['success'] ?? false) !== true) {
                // e.g. one of the ids no longer exists, or two different GND ids.
                return ResponseEnvelope::error($response, 'merge_error', $message !== '' ? $message : __('Errore interno.'), 409);
            }
            \App\Support\ContentCache::booksChanged();

            return ResponseEnvelope::success($response, ['primary_id' => (int) ($outcome->json['primary_id'] ?? 0)]);
        } catch (\Throwable $e) {
            return $this->fail($response, $entity . ' merge', $e);
        }
    }

    public function mergeGenre(ServerRequestInterface $request, ResponseInterface $response, int $id): ResponseInterface
    {
        $targetId = StaffPaging::id(JsonBody::parse($request)['target_id'] ?? null);
        if ($targetId <= 0 || $targetId === $id) {
            return ResponseEnvelope::error($response, 'validation_error', __('Seleziona un genere di destinazione.'), 422, ['field' => 'target_id']);
        }
        try {
            $repo = new \App\Models\GenereRepository($this->db);
            if (!$repo->getById($id) || !$repo->getById($targetId)) {
                return ResponseEnvelope::error($response, 'not_found', __('Genere non trovato.'), 404);
            }
            if ($this->isDescendant($targetId, $id)) {
                return ResponseEnvelope::error($response, 'conflict', __('Impossibile unire un genere con un suo discendente.'), 409, ['reason' => 'descendant']);
            }
            $stats = $repo->merge($id, $targetId);

            return ResponseEnvelope::success($response, [
                'target_id'      => $targetId,
                'books_updated'  => $stats['books_updated'],
                'children_moved' => $stats['children_moved'],
            ]);
        } catch (\InvalidArgumentException $e) {
            return ResponseEnvelope::error($response, 'conflict', __('Impossibile unire un genere con un suo discendente.'), 409, ['reason' => 'descendant']);
        } catch (\Throwable $e) {
            return $this->fail($response, 'genre merge', $e);
        }
    }

    /** True when $candidate sits below $ancestor in the genre tree. */
    private function isDescendant(int $candidate, int $ancestor): bool
    {
        $stmt = $this->db->prepare('SELECT parent_id FROM generi WHERE id = ?');
        if ($stmt === false) {
            throw new \RuntimeException('genre parent prepare failed');
        }
        $current = $candidate;
        for ($guard = 0; $guard < 50; $guard++) {
            $stmt->bind_param('i', $current);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result !== false ? $result->fetch_assoc() : null;
            $parent = $row !== null && $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
            if ($parent === null) {
                break;
            }
            if ($parent === $ancestor) {
                $stmt->close();
                return true;
            }
            $current = $parent;
        }
        $stmt->close();

        return false;
    }

    private function fail(ResponseInterface $response, string $what, \Throwable $e): ResponseInterface
    {
        SecureLogger::error('[MobileApi] staff ' . $what . ' failed: ' . $e->getMessage());

        return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
    }
}
