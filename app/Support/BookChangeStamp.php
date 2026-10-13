<?php
declare(strict_types=1);

namespace App\Support;

use mysqli;

/**
 * Moves `libri.updated_at` for books whose displayed data changed through a
 * related row: an author, publisher or genre renamed or merged. Those writes
 * never touch `libri` itself, so change feeds keyed on `libri.updated_at`
 * (the Mobile API /catalog/sync delta, ResourceSync change lists) would keep
 * serving the old name until a full reload.
 *
 * Best effort: a failure is logged and never undoes the entity write.
 */
final class BookChangeStamp
{
    private const CHUNK = 500;

    /**
     * @param array<int|string, int> $bookIds
     */
    public static function touch(mysqli $db, array $bookIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $bookIds), static fn (int $id): bool => $id > 0)));
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $sql = 'UPDATE libri SET updated_at = NOW() WHERE deleted_at IS NULL AND id IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ')';
            self::run($db, $sql, str_repeat('i', count($chunk)), $chunk);
        }
    }

    /** Books filed under a genre, as main genre or as subgenre. */
    public static function touchForGenre(mysqli $db, int $genreId): void
    {
        if ($genreId <= 0) {
            return;
        }
        self::run(
            $db,
            'UPDATE libri SET updated_at = NOW() WHERE deleted_at IS NULL AND (genere_id = ? OR sottogenere_id = ?)',
            'ii',
            [$genreId, $genreId]
        );
    }

    /**
     * @param array<int, int> $params
     */
    private static function run(mysqli $db, string $sql, string $types, array $params): void
    {
        try {
            $stmt = $db->prepare($sql);
            if ($stmt === false) {
                SecureLogger::error('[BookChangeStamp] prepare failed', ['db_error' => $db->error]);
                return;
            }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
            SecureLogger::error('[BookChangeStamp] update failed', ['error' => $e->getMessage()]);
        }
    }
}
