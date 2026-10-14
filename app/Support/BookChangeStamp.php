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
 * A failure throws: callers run it in the same transaction as the entity
 * write ({@see DbAtomic}), so a rename either reaches the change feeds or does
 * not happen at all.
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
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('[BookChangeStamp] prepare failed: ' . $db->error);
        }
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('[BookChangeStamp] update failed: ' . $error);
        }
        $stmt->close();
    }
}
