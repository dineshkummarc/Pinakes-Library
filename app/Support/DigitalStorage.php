<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Where Digital Library files live, and the move of the old ones (issue #480).
 *
 * Files are kept in storage/digital/, outside the web root; their URL stays
 * /uploads/digital/<name> and ProtectedUploadController serves them after asking
 * DigitalAccess. Before 0.8.6 they were in public/uploads/digital/: this class moves
 * them across, safely and idempotently, and keeps the old folder shut to direct
 * requests. Paths are parameters so the move can be tested on scratch folders.
 */
final class DigitalStorage
{
    /** Marker written once the old folder holds no file left to move (its content: that folder's mtime). */
    public const MIGRATED_MARKER = '.migrated-from-public';

    /** A copy on its way in: never served (the name starts with a dot) and finished atomically. */
    private const PARTIAL_PREFIX = '.partial-';

    /** Across disks a file is copied: during a page request only this many bytes in total. */
    public const REQUEST_COPY_BUDGET = 64 * 1024 * 1024;

    /**
     * Tests only: replaces the first rename() of a move, so the cross-disk copy path (two
     * Docker volumes) can be exercised on one disk. Null in production.
     *
     * @var (\Closure(string, string): bool)|null
     */
    public static ?\Closure $renameForTests = null;

    /** storage/digital under the installation root. */
    public static function targetDir(?string $root = null): string
    {
        return ($root ?? dirname(__DIR__, 2)) . '/storage/digital';
    }

    /** public/uploads/digital under the installation root: where files lived before 0.8.6. */
    public static function legacyDir(?string $root = null): string
    {
        return ($root ?? dirname(__DIR__, 2)) . '/public/uploads/digital';
    }

    /**
     * Create $dir with the mode of the other writable folders (0775, as Installer and
     * Updater do) and shut it to direct requests.
     */
    public static function ensureDir(string $dir): string
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create ' . $dir);
        }
        if (!is_writable($dir)) {
            @chmod($dir, 0775);
        }
        // storage/.htaccess already denies the whole tree; this one keeps the folder
        // shut even when it is copied elsewhere or storage/.htaccess goes missing.
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', self::denyAllHtaccess());
        }
        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }
        return $dir;
    }

    /** The old folder answers nothing directly: only the Pinakes route serves its files. */
    public static function lockLegacyDir(string $legacy): void
    {
        if (!is_dir($legacy)) {
            return;
        }
        $htaccess = $legacy . '/.htaccess';
        $wanted = self::denyAllHtaccess();
        if (!is_file($htaccess) || (string) @file_get_contents($htaccess) !== $wanted) {
            @file_put_contents($htaccess, $wanted);
        }
    }

    public static function denyAllHtaccess(): string
    {
        return implode("\n", [
            '# Digital Library files are served by Pinakes, which checks who may open them.',
            'Options -Indexes',
            '<IfModule mod_authz_core.c>',
            '    Require all denied',
            '</IfModule>',
            '<IfModule !mod_authz_core.c>',
            '    Order Deny,Allow',
            '    Deny from all',
            '</IfModule>',
            '',
        ]);
    }

    /**
     * Done while the old folder is unchanged since the last complete move. A file put back
     * there later (a backup from before 0.8.6 restored, a copy by hand) changes the folder's
     * mtime and the move runs again; a stat and a tiny read per request decide.
     */
    public static function migrationDone(string $legacy, string $target): bool
    {
        $marker = $target . '/' . self::MIGRATED_MARKER;
        if (!is_file($marker)) {
            return false;
        }
        if (!is_dir($legacy)) {
            return true;
        }
        clearstatcache(true, $legacy);
        if (trim((string) @file_get_contents($marker)) === (string) @filemtime($legacy)) {
            return true;
        }
        // The folder changed (or the stamp was too fresh to trust): look for real.
        if (self::hasMovableFile($legacy)) {
            return false;
        }
        self::writeMarker($legacy, $target);
        return true;
    }

    /**
     * The marker holds the old folder's mtime, but only once that mtime is two seconds old:
     * mtime has one-second resolution, so a file arriving in the same second as the last
     * change would otherwise leave it unchanged and never be moved. A fresh folder gets
     * "recheck", and the next request looks at the folder itself.
     */
    private static function writeMarker(string $legacy, string $target): void
    {
        clearstatcache(true, $legacy);
        $mtime = is_dir($legacy) ? (int) @filemtime($legacy) : 0;
        $stamp = !is_dir($legacy) ? 'absent' : (time() - $mtime >= 2 ? (string) $mtime : 'recheck');
        @file_put_contents($target . '/' . self::MIGRATED_MARKER, $stamp . "\n");
    }

    private static function hasMovableFile(string $dir): bool
    {
        foreach (new \DirectoryIterator($dir) as $entry) {
            $name = $entry->getFilename();
            if (!$entry->isDot() && !$entry->isLink() && $entry->isFile() && !str_starts_with($name, '.') && $name !== 'index.html') {
                return true;
            }
        }
        return false;
    }

    /**
     * Move the old files across:
     *   - rename() when both folders are on the same disk (instant, atomic);
     *   - otherwise copy to a hidden partial name in the target folder, compare size and
     *     SHA-256 with the original, rename the copy into place, then delete the original;
     *   - a target that already holds the same bytes (an interrupted earlier run) only
     *     costs the original; a target with DIFFERENT bytes is never overwritten: the file
     *     stays where it is, still served, and the conflict is logged;
     *   - a copy larger than $copyBudget waits for a run with room for it (the cron);
     *   - one run at a time (lock file), and the marker only once nothing is left.
     *
     * @return array{moved:int, remaining:int, failed:int}
     */
    public static function migrate(string $legacy, string $target, int $maxFiles, float $maxSeconds, int $copyBudget = PHP_INT_MAX): array
    {
        self::ensureDir($target);
        $result = ['moved' => 0, 'remaining' => 0, 'failed' => 0];
        if (!is_dir($legacy)) {
            self::writeMarker($legacy, $target);
            return $result;
        }
        self::lockLegacyDir($legacy);

        $lock = @fopen($target . '/.migration.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) {
                fclose($lock);
            }
            $result['remaining'] = 1; // another run is moving them right now
            return $result;
        }

        try {
            $started = microtime(true);
            $copied = 0;
            foreach (new \DirectoryIterator($legacy) as $entry) {
                $name = $entry->getFilename();
                if ($entry->isDot() || $entry->isLink() || !$entry->isFile() || str_starts_with($name, '.') || $name === 'index.html') {
                    continue;
                }
                if ($result['moved'] >= $maxFiles || microtime(true) - $started > $maxSeconds) {
                    $result['remaining']++;
                    continue;
                }
                $outcome = self::moveOne($entry->getPathname(), $target . '/' . $name, $copyBudget - $copied);
                if (is_int($outcome)) {
                    $result['moved']++;
                    $copied += $outcome;
                } elseif ($outcome === 'deferred') {
                    $result['remaining']++;
                } else {
                    $result['failed']++;
                }
            }
            if ($result['remaining'] === 0 && $result['failed'] === 0) {
                self::writeMarker($legacy, $target);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        if ($result['moved'] > 0 || $result['failed'] > 0) {
            SecureLogger::info('[Digital Library] moved files to storage/digital', $result);
        }
        return $result;
    }

    /** @return int files in $dir that a reader could be served (dotfiles and index.html aside) */
    public static function countFiles(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $n = 0;
        foreach (new \DirectoryIterator($dir) as $entry) {
            if ($entry->isFile() && !str_starts_with($entry->getFilename(), '.') && $entry->getFilename() !== 'index.html') {
                $n++;
            }
        }
        return $n;
    }

    /**
     * One file. Returns the bytes copied (0 for a rename) when it was moved, 'deferred'
     * when a cross-disk copy does not fit the budget, 'failed' otherwise.
     *
     * @return int|'deferred'|'failed'
     */
    private static function moveOne(string $from, string $to, int $copyBudget): int|string
    {
        $size = @filesize($from);
        if ($size === false) {
            return 'failed';
        }
        if (is_file($to)) {
            if (@filesize($to) === $size && @hash_file('sha256', $to) === @hash_file('sha256', $from)) {
                return @unlink($from) ? 0 : 'failed';
            }
            SecureLogger::error('[Digital Library] storage/digital already holds a different file with this name; left in place', [
                'file' => basename($from),
            ]);
            return 'failed';
        }
        $renamed = self::$renameForTests !== null ? (self::$renameForTests)($from, $to) : @rename($from, $to);
        if ($renamed) {
            @chmod($to, 0644);
            return 0;
        }
        // Different disk (EXDEV, e.g. two Docker volumes): copy, verify, then swap in.
        if ($size > $copyBudget) {
            return 'deferred';
        }
        $partial = dirname($to) . '/' . self::PARTIAL_PREFIX . basename($to);
        @unlink($partial);
        if (!@copy($from, $partial)) {
            @unlink($partial);
            return 'failed';
        }
        if (@filesize($partial) !== $size || @hash_file('sha256', $partial) !== @hash_file('sha256', $from)) {
            @unlink($partial);
            SecureLogger::error('[Digital Library] copy to storage/digital did not match the original; left in place', [
                'file' => basename($from),
            ]);
            return 'failed';
        }
        @chmod($partial, 0644);
        if (!@rename($partial, $to)) {
            @unlink($partial);
            return 'failed';
        }
        // The copy is in place and verified: removing the original is the last step, and
        // if it fails the next run finds identical bytes on both sides and finishes it.
        @unlink($from);
        return $size;
    }
}
