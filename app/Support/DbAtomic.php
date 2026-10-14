<?php
declare(strict_types=1);

namespace App\Support;

use mysqli;

/**
 * Run a group of writes as one unit on a mysqli connection.
 *
 * Opens (and commits or rolls back) its own transaction when none is active;
 * inside a caller's transaction it only runs the work and lets the exception
 * reach the caller, who owns the rollback. Nesting is detected rather than
 * assumed, per the convention documented on
 * BulkFieldEditor::hasActiveTransaction(): an explicit begin_transaction()
 * leaves @@autocommit at 1, so the flag alone is not enough and a SAVEPOINT
 * probe settles it. Never call begin_transaction() twice: mysqli commits the
 * outer work implicitly.
 */
final class DbAtomic
{
    /**
     * @param callable():void $work
     */
    public static function run(mysqli $db, callable $work, string $context = 'DbAtomic'): void
    {
        $owns = !self::inTransaction($db);
        if ($owns) {
            $db->begin_transaction();
        }
        try {
            $work();
            if ($owns) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($owns) {
                try {
                    $db->rollback();
                } catch (\Throwable $rollbackFailure) {
                    // Keep the original cause: a rollback that throws (a
                    // connection that died mid-statement is the usual way)
                    // would otherwise replace it, and the cause is what has to
                    // reach the log and the caller.
                    SecureLogger::error(
                        '[' . $context . '] Rollback failed after a failed write',
                        ['error' => $rollbackFailure->getMessage()]
                    );
                }
            }
            throw $e;
        }
    }

    /** @see BulkFieldEditor::hasActiveTransaction() — same two-part check. */
    public static function inTransaction(mysqli $db): bool
    {
        $result = $db->query('SELECT @@autocommit AS ac');
        if ($result instanceof \mysqli_result) {
            $row = $result->fetch_assoc();
            $result->free();
            if ((int) ($row['ac'] ?? 1) === 0) {
                return true;
            }
        }

        // Outside a transaction a SAVEPOINT is accepted and discarded at once,
        // so RELEASE cannot find it; inside one it can.
        $probe = 'pinakes_atomic_probe_' . bin2hex(random_bytes(6));
        try {
            if (!$db->query("SAVEPOINT {$probe}")) {
                return false;
            }
            $released = $db->query("RELEASE SAVEPOINT {$probe}");
        } catch (\Throwable $e) {
            return false;
        }

        return $released !== false;
    }
}
