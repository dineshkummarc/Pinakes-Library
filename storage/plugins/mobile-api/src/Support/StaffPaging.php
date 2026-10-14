<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Support;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Offset-cursor paging and small input helpers shared by the Mobile API 1.7.0
 * staff controllers. The cursor stays opaque to clients (CursorCodec); an
 * offset is enough for desk-sized lists.
 */
final class StaffPaging
{
    private function __construct()
    {
    }

    /** `limit` query parameter clamped to 1..$max (default $default). */
    public static function limit(ServerRequestInterface $request, int $default = 30, int $max = 100): int
    {
        $raw = $request->getQueryParams()['limit'] ?? null;
        $limit = is_scalar($raw) && is_numeric((string) $raw) ? (int) $raw : $default;

        return max(1, min($max, $limit));
    }

    public static function offset(ServerRequestInterface $request): int
    {
        $raw = $request->getQueryParams()['cursor'] ?? null;
        $decoded = CursorCodec::decode(is_string($raw) ? $raw : null);
        $offset = is_array($decoded) ? ($decoded['o'] ?? 0) : 0;

        return is_int($offset) && $offset > 0 ? min($offset, 1_000_000) : 0;
    }

    /**
     * @return array<string, mixed>
     */
    public static function meta(int $count, int $limit, int $offset, bool $hasMore, ?int $total = null): array
    {
        $meta = [
            'count'       => $count,
            'limit'       => $limit,
            'next_cursor' => $hasMore ? CursorCodec::encode(['o' => $offset + $limit]) : null,
            'has_more'    => $hasMore,
        ];
        if ($total !== null) {
            $meta['total_count'] = $total;
        }

        return $meta;
    }

    public static function query(ServerRequestInterface $request, string $key): string
    {
        return trim(Input::str($request->getQueryParams()[$key] ?? ''));
    }

    /**
     * A strict Y-m-d date, '' for absent/empty, null for anything malformed.
     */
    public static function isoDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return \App\Support\DateHelper::isISODateFormat($value) ? $value : null;
    }

    /** Positive int from a JSON value, 0 when absent or invalid. */
    public static function id(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }
        if (is_string($value) && preg_match('/^\d{1,10}$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return 0;
    }

    /**
     * A list of positive ids, or null when the value is not a list of ids.
     *
     * @return list<int>|null
     */
    public static function ids(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $ids = [];
        foreach ($value as $item) {
            $id = self::id($item);
            if ($id <= 0) {
                return null;
            }
            $ids[$id] = $id;
        }

        return array_values($ids);
    }

    /** Free text with control characters stripped, trimmed and capped. */
    public static function text(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_scalar($value)) {
            return null;
        }
        $clean = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $value));

        return mb_substr($clean, 0, $max);
    }

    /** Authenticated user (AppAuthMiddleware attribute). @return array<string, mixed> */
    public static function user(ServerRequestInterface $request): array
    {
        $user = $request->getAttribute(AppAuthMiddleware::ATTR_USER);

        return is_array($user) ? $user : [];
    }
}
