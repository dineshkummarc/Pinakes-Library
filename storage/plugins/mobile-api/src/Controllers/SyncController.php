<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Controllers;

use App\Plugins\MobileApi\Support\CursorCodec;
use App\Plugins\MobileApi\Support\Input;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/catalog/sync — delta feed for the app's offline catalogue.
 *
 * Three arms, the same visibility rules as ResourceSync's change list:
 *   - live rows       deleted_at IS NULL AND catalogue-visible AND updated_at >= since  → upsert
 *   - de-listings     deleted_at IS NULL AND now a wanted title that WAS once in the
 *                     catalogue AND updated_at >= since                              → delete
 *   - tombstones      deleted_at >= since, at most 30 days old                       → delete
 *
 * Paging is keyset on (change_ts, id) with change_ts = COALESCE(deleted_at,
 * updated_at). `server_time` is the database clock read BEFORE the first page
 * and carried in the cursor, so every page of one pass shares one watermark;
 * the client stores it only after the last page and sends it back as `since`
 * (inclusive: a row changed in the same second comes back once more, which is
 * a harmless upsert).
 *
 * No `since`, an unparsable one or one older than the tombstone window means
 * `full_resync: true`: live rows only, and the client replaces its cache.
 */
final class SyncController
{
    private const DEFAULT_LIMIT = 200;
    private const MAX_LIMIT = 200;
    private const TOMBSTONE_DAYS = 30;
    private const LOOKBACK_SECONDS = 5;
    private const DESCRIPTION_MAX = 2000;

    public function __construct(private mysqli $db)
    {
    }

    public function sync(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $params = $request->getQueryParams();
            $limit = $this->limit($params['limit'] ?? null);
            $cursor = CursorCodec::decode(isset($params['cursor']) ? Input::str($params['cursor']) : null);

            if (is_array($cursor) && $this->validCursor($cursor)) {
                $serverTime = (string) $cursor['server_time'];
                $since = $cursor['since'] === '' ? null : (string) $cursor['since'];
                $lastTs = (string) $cursor['last_ts'];
                $lastId = (int) $cursor['last_id'];
            } else {
                $serverTime = $this->dbNow();
                $since = $this->parseSince(Input::str($params['since'] ?? ''), $serverTime);
                $lastTs = null;
                $lastId = 0;
            }
            $fullResync = $since === null;

            [$sql, $types, $bind] = $this->query($since, $serverTime, $lastTs, $lastId, $limit + 1);
            $stmt = $this->db->prepare($sql);
            if ($stmt === false) {
                throw new \RuntimeException('sync prepare failed: ' . $this->db->error);
            }
            $stmt->bind_param($types, ...$bind);
            $stmt->execute();
            $result = $stmt->get_result();
            $rows = [];
            while ($result !== false && ($r = $result->fetch_assoc()) !== null) {
                $rows[] = $r;
            }
            $stmt->close();

            $hasMore = count($rows) > $limit;
            if ($hasMore) {
                $rows = array_slice($rows, 0, $limit);
            }

            $catalog = new CatalogController($this->db, true);
            $items = [];
            foreach ($rows as $r) {
                $change = (string) $r['change_kind'];
                $item = [
                    'id'         => (int) $r['id'],
                    'change'     => $change,
                    'changed_at' => (string) $r['change_ts'],
                ];
                if ($change === 'upsert') {
                    $book = $catalog->summaryFromRow($r);
                    $description = self::plainText((string) ($r['descrizione'] ?? ''));
                    if (mb_strlen($description) > self::DESCRIPTION_MAX) {
                        $description = mb_substr($description, 0, self::DESCRIPTION_MAX);
                    }
                    $book['isbn10'] = self::nullable($r['isbn10'] ?? null);
                    $book['ean'] = self::nullable($r['ean'] ?? null);
                    $book['pages'] = isset($r['numero_pagine']) ? (int) $r['numero_pagine'] : null;
                    $book['description'] = $description !== '' ? $description : null;
                    $book['location_label'] = \App\Plugins\MobileApi\Support\StaffBookReader::locationLabel($r);
                    $book['is_wanted'] = false;
                    $book['updated_at'] = self::nullable($r['updated_at'] ?? null);
                    $item['book'] = $book;
                }
                $items[] = $item;
            }

            $nextCursor = null;
            if ($hasMore && $rows !== []) {
                $last = $rows[count($rows) - 1];
                $nextCursor = CursorCodec::encode([
                    'since'       => $since ?? '',
                    'server_time' => $serverTime,
                    'last_ts'     => (string) $last['change_ts'],
                    'last_id'     => (int) $last['id'],
                ]);
            }

            return ResponseEnvelope::success($response, [
                'items'       => $items,
                'server_time' => $serverTime,
                'full_resync' => $fullResync,
            ], [
                'next_cursor' => $nextCursor,
                'has_more'    => $hasMore,
                'count'       => count($items),
            ])->withHeader('Cache-Control', 'private, no-store');
        } catch (\Throwable $e) {
            SecureLogger::error('[MobileApi] catalog sync failed: ' . $e->getMessage());
            return ResponseEnvelope::error($response, 'internal_error', __('Sincronizzazione non disponibile.'), 500);
        }
    }

    /**
     * @return array{0:string, 1:string, 2:list<int|string>}
     */
    private function query(?string $since, string $serverTime, ?string $lastTs, int $lastId, int $fetch): array
    {
        $visible = \App\Support\BookVisibility::catalogue($this->db, 'l');
        $delisted = \App\Support\BookVisibility::delisted($this->db, 'l');
        $everCatalogued = \App\Support\BookVisibility::everCatalogued($this->db, 'l');
        // Same "never announce a request nobody was ever given" guard as ResourceSync.
        $publishedOnce = 'NOT (' . $delisted . ' AND NOT (' . $everCatalogued . '))';

        $bind = [];
        $types = '';
        if ($since === null) {
            $arms = "(l.deleted_at IS NULL AND {$visible})";
        } else {
            $arms = "((l.deleted_at IS NULL AND {$visible} AND l.updated_at >= ?)
                    OR (l.deleted_at IS NULL AND {$delisted} AND {$everCatalogued} AND l.updated_at >= ?)
                    OR (l.deleted_at IS NOT NULL AND l.deleted_at >= ? AND {$publishedOnce}
                        AND l.deleted_at >= DATE_SUB(?, INTERVAL " . self::TOMBSTONE_DAYS . " DAY)))";
            array_push($bind, $since, $since, $since, $serverTime);
            $types .= 'ssss';
        }

        $changeTs = 'COALESCE(l.deleted_at, l.updated_at)';
        // Changes written after the watermark belong to the next pass.
        $where = "{$arms} AND {$changeTs} <= ?";
        $bind[] = $serverTime;
        $types .= 's';
        if ($lastTs !== null) {
            $where .= " AND ({$changeTs} > ? OR ({$changeTs} = ? AND l.id > ?))";
            array_push($bind, $lastTs, $lastTs, $lastId);
            $types .= 'ssi';
        }

        $authorDisplaySql = \App\Support\AuthorName::displaySql('a');
        $liveCase = "CASE WHEN l.deleted_at IS NULL AND {$visible} THEN 'upsert' ELSE 'delete' END";

        // CI-SOFT-DELETE-EXEMPT: the tombstone arm deliberately reads soft-deleted rows (<= 30 days) to report deletions; live rows are gated by l.deleted_at IS NULL.
        $sql = "SELECT l.id, {$liveCase} AS change_kind,
                       DATE_FORMAT({$changeTs}, '%Y-%m-%d %H:%i:%s') AS change_ts,
                       l.titolo, l.sottotitolo, l.anno_pubblicazione, l.lingua, l.copertina_url,
                       l.copie_totali, l.copie_disponibili, l.isbn13, l.isbn10, l.ean, l.tipo_media,
                       l.numero_pagine, l.descrizione, l.collocazione,
                       l.posizione_progressiva, l.updated_at,
                       s.codice AS shelf_code, m.numero_livello AS level_number,
                       (SELECT {$authorDisplaySql} FROM libri_autori la JOIN autori a ON la.autore_id = a.id
                         WHERE la.libro_id = l.id AND la.ruolo IN ('principale', 'co-autore')
                         ORDER BY (la.ruolo = 'principale') DESC, la.ordine_credito, la.autore_id LIMIT 1) AS autore,
                       e.nome AS editore, g.nome AS genere
                  FROM libri l
                  LEFT JOIN editori e ON e.id = l.editore_id
                  LEFT JOIN generi g ON g.id = l.genere_id
                  LEFT JOIN scaffali s ON s.id = l.scaffale_id
                  LEFT JOIN mensole m ON m.id = l.mensola_id
                 WHERE {$where}
                 ORDER BY {$changeTs} ASC, l.id ASC
                 LIMIT ?";
        $bind[] = $fetch;
        $types .= 'i';

        return [$sql, $types, $bind];
    }

    /**
     * Normalise `since` to the DB clock format, or null for a full resync.
     */
    private function parseSince(string $raw, string $serverTime): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $raw = str_replace('T', ' ', $raw);
        $ts = self::strictTimestamp($raw);
        if ($ts === null) {
            return null;
        }
        $now = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $serverTime);
        if ($now === false) {
            return null;
        }
        if ($ts < $now->modify('-' . self::TOMBSTONE_DAYS . ' days')) {
            // Deletions older than the tombstone window are gone: only a full
            // reload can make the client's cache correct again.
            return null;
        }
        if ($ts > $now) {
            $ts = $now;
        }
        // Look back a few seconds: a write whose transaction began before the
        // previous watermark but committed after that pass carries an
        // updated_at just below it and would otherwise be skipped for good.
        // Re-sending a handful of rows is harmless (the client upserts).
        $ts = $ts->modify('-' . self::LOOKBACK_SECONDS . ' seconds');

        return $ts->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $cursor
     */
    private function validCursor(array $cursor): bool
    {
        $isTs = static fn (mixed $v): bool => is_string($v) && self::strictTimestamp($v) !== null;

        return $isTs($cursor['server_time'] ?? null)
            && $isTs($cursor['last_ts'] ?? null)
            && isset($cursor['last_id']) && is_numeric($cursor['last_id'])
            && array_key_exists('since', $cursor)
            && ($cursor['since'] === '' || $isTs($cursor['since']));
    }

    /**
     * A 'Y-m-d H:i:s' value that round-trips exactly (no 2026-13-45 overflow).
     */
    private static function strictTimestamp(string $value): ?\DateTimeImmutable
    {
        $ts = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);

        return $ts !== false && $ts->format('Y-m-d H:i:s') === $value ? $ts : null;
    }

    private function dbNow(): string
    {
        $result = $this->db->query("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s') AS now");
        $row = $result instanceof \mysqli_result ? $result->fetch_assoc() : null;
        if ($result instanceof \mysqli_result) {
            $result->free();
        }
        if (!is_array($row) || !is_string($row['now'] ?? null)) {
            throw new \RuntimeException('unable to read the database clock');
        }

        return $row['now'];
    }

    /**
     * Rich-text description → plain text with paragraph breaks kept.
     */
    public static function plainText(string $html): string
    {
        $text = (string) preg_replace('#<(br|/p|/div|/li|/h[1-6])\b[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = (string) preg_replace('/ *\n */', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    private function limit(mixed $raw): int
    {
        $limit = is_numeric($raw) ? (int) $raw : self::DEFAULT_LIMIT;

        return max(1, min(self::MAX_LIMIT, $limit));
    }

    private static function nullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);

        return $s !== '' ? $s : null;
    }
}
