<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Where Digital Library files live, and the move of the old ones (issue #480).
 *
 * Files are kept in storage/digital/, outside the web root; their URL stays
 * /uploads/digital/<name> and ProtectedUploadController serves them after asking
 * DigitalAccess. Before 0.8.6 they were in public/uploads/digital/: this class moves
 * them across, safely and idempotently. That folder needs no rule of its own: public/.htaccess
 * sends every /uploads/digital/ request to Pinakes, whether or not the file is there.
 * Paths are parameters so the move can be tested on scratch folders.
 */
final class DigitalStorage
{
    /** Marker written once the old folder holds no file left to move (its content: that folder's mtime). */
    public const MIGRATED_MARKER = '.migrated-from-public';

    /** A copy on its way in: never served (the name starts with a dot) and finished atomically. */
    private const PARTIAL_PREFIX = '.partial-';

    /**
     * Files a run could not move (name => "size:mtime"). A page request skips them while
     * they are unchanged, so an old folder the web server cannot write to, or a clash,
     * costs one failed attempt instead of a re-hash on every page; the maintenance cron,
     * the activation of the plugin and "Sposta ora" try them again.
     */
    public const FAILED_REGISTRY = '.migration-failed.json';

    /** Seconds the cron and "Sposta ora" wait for a page request that holds the move lock. */
    private const LOCK_WAIT = 15.0;

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
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('cannot create ' . $dir);
            }
            // mkdir() applies the umask (0755 under the usual 022): set the mode the
            // installer and the updater give every writable folder.
            @chmod($dir, 0775);
        } else {
            // An upgrade through an older updater creates the folder by copying
            // storage/digital/.gitkeep from the package, with mkdir()'s 0755: add the
            // group bits the other writable folders have (ug+rwX, as setup-permissions.sh
            // does), never the "other" bits, so a stricter choice of the administrator stays.
            // Fresh mode, not the stat cache: PHP 8.2's chmod() does not clear it.
            clearstatcache(true, $dir);
            $perms = @fileperms($dir);
            if ($perms !== false && ($perms & 0770) !== 0770) {
                @chmod($dir, ($perms & 0777) | 0770);
            }
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

    /**
     * No deny rule in the old folder: Apache and LiteSpeed apply a folder's .htaccess to
     * every URL that maps into it BEFORE public/.htaccess rewrites the request to Pinakes,
     * so "Require all denied" there refused every Digital Library download, the allowed
     * ones too. Pre-release builds wrote exactly that file; it is removed, and an .htaccess
     * with any other content (the administrator's own) is left alone.
     */
    public static function releaseLegacyDir(string $legacy): void
    {
        $htaccess = $legacy . '/.htaccess';
        if (is_file($htaccess) && (string) @file_get_contents($htaccess) === self::denyAllHtaccess()) {
            @unlink($htaccess);
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
        if (self::sameFolder($legacy, $target)) {
            return true; // the old folder IS storage/digital (an alias): nothing to move
        }
        if (trim((string) @file_get_contents($marker)) === self::treeStamp($legacy)[0]) {
            return true;
        }
        // The folder changed (or the stamp was too fresh to trust): look for real. A file
        // remembered as failed, and unchanged since, is the cron's: it does not reopen the move.
        if (self::hasMovableFile($legacy, self::failedRegistry($target))) {
            return false;
        }
        self::writeMarker($legacy, $target);
        return true;
    }

    /**
     * The marker holds a stamp of the old folder AND its subfolders (a file added to an
     * existing subfolder changes that subfolder's mtime, not the top one), but only once
     * the newest of them is two seconds old: mtime has one-second resolution, so a file
     * arriving in the same second as the last change would otherwise leave it unchanged
     * and never be moved. A fresh folder gets "recheck", and the next request looks.
     */
    private static function writeMarker(string $legacy, string $target): void
    {
        if (!is_dir($legacy)) {
            $stamp = 'absent';
        } else {
            [$tree, $newest] = self::treeStamp($legacy);
            $stamp = time() - $newest >= 2 ? $tree : 'recheck';
        }
        self::writeAtomically($target . '/' . self::MIGRATED_MARKER, $stamp . "\n");
    }

    /**
     * Stamp of $dir and every subfolder (path:mtime, hashed) and the newest mtime among
     * them. Folders only, no files: a few stats per request while nothing is left to move.
     *
     * @return array{0:string, 1:int}
     */
    private static function treeStamp(string $dir): array
    {
        $parts = [];
        $newest = 0;
        $walk = static function (string $path, string $rel) use (&$walk, &$parts, &$newest): void {
            clearstatcache(true, $path);
            $mtime = (int) @filemtime($path);
            $parts[] = $rel . ':' . $mtime;
            $newest = max($newest, $mtime);
            foreach (self::entries($path) as $entry) {
                $name = $entry->getFilename();
                if ($entry->isDir() && !$entry->isLink() && !str_starts_with($name, '.')) {
                    $walk($entry->getPathname(), $rel . '/' . $name);
                }
            }
        };
        $walk($dir, '');
        return [sha1(implode("\n", $parts)), $newest];
    }

    /**
     * The entries of $dir, or none when it cannot be read: a subfolder restored from a
     * backup with another owner must not throw on every page. It is logged, and its files
     * stay where they are, unreachable to the web server as well.
     *
     * @return list<\SplFileInfo>
     */
    private static function entries(string $dir): array
    {
        try {
            $out = [];
            foreach (new \DirectoryIterator($dir) as $entry) {
                if (!$entry->isDot()) {
                    $out[] = new \SplFileInfo($entry->getPathname());
                }
            }
            return $out;
        } catch (\UnexpectedValueException $e) {
            static $logged = [];
            if (!isset($logged[$dir])) {
                $logged[$dir] = true;
                SecureLogger::error('[Digital Library] a folder in public/uploads/digital cannot be read: its files are not moved', ['folder' => basename($dir)]);
            }
            return [];
        }
    }

    /** Whether the old folder and storage/digital are the same place (a symlink or alias). */
    private static function sameFolder(string $legacy, string $target): bool
    {
        $a = realpath($legacy);
        $b = realpath($target);
        return $a !== false && $b !== false && $a === $b;
    }

    /**
     * Replace $file through a rename in its folder. The cron, the CLI and the web server
     * may run as different users: overwriting a file another user created needs that
     * user's permission, replacing it needs only the folder's (which they share).
     */
    private static function writeAtomically(string $file, string $content): void
    {
        $tmp = dirname($file) . '/.tmp-' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $content) === false) {
            SecureLogger::error('[Digital Library] cannot write in storage/digital: check its permissions', ['file' => basename($file)]);
            return;
        }
        @chmod($tmp, 0664);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            SecureLogger::error('[Digital Library] cannot replace a state file in storage/digital', ['file' => basename($file)]);
        }
    }

    /**
     * Files of the old folder that could be served, subfolders included (older versions
     * accepted names with a subfolder, and DigitalAccess still does): name relative to
     * $dir => entry. Dot-named files and folders, links and index.html are never files to move.
     *
     * @return \Generator<string, \SplFileInfo>
     */
    private static function legacyFiles(string $dir, string $prefix = ''): \Generator
    {
        foreach (self::entries($dir) as $entry) {
            $name = $entry->getFilename();
            if ($entry->isLink() || str_starts_with($name, '.')) {
                continue;
            }
            if ($entry->isDir()) {
                yield from self::legacyFiles($entry->getPathname(), $prefix . $name . '/');
            } elseif ($entry->isFile() && $name !== 'index.html') {
                yield $prefix . $name => $entry;
            }
        }
    }

    /** @param array<string, string> $knownFailures name => "size:mtime" */
    private static function hasMovableFile(string $dir, array $knownFailures = []): bool
    {
        foreach (self::legacyFiles($dir) as $name => $entry) {
            if (($knownFailures[$name] ?? null) !== $entry->getSize() . ':' . $entry->getMTime()) {
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
     *     costs the original; a target with DIFFERENT bytes is never overwritten: the old
     *     file stays in the old folder for the administrator, the URL keeps serving the
     *     one in storage/digital (which wins), and the conflict is logged;
     *   - files in subfolders move to the same subfolder of the target;
     *   - a copy larger than $copyBudget waits for a run with room for it (the cron);
     *     comparing an identical leftover reads both files, so it counts against it too;
     *   - a file that fails is remembered: with $retryFailed false (page requests) it is
     *     skipped while unchanged, and the marker no longer waits for it;
     *   - one run at a time (lock file), and the marker only once nothing is left.
     *
     * @return array{moved:int, remaining:int, failed:int}
     */
    public static function migrate(string $legacy, string $target, int $maxFiles, float $maxSeconds, int $copyBudget = PHP_INT_MAX, bool $retryFailed = true): array
    {
        self::ensureDir($target);
        $result = ['moved' => 0, 'remaining' => 0, 'failed' => 0];
        if (!is_dir($legacy) || self::sameFolder($legacy, $target)) {
            // An old folder that is storage/digital itself (an alias): moving a file onto
            // itself would find "the same bytes" and delete the only copy.
            self::writeMarker($legacy, $target);
            return $result;
        }
        self::releaseLegacyDir($legacy);

        // The lock may belong to another user (the web server created it, the cron runs as
        // someone else, or the reverse): flock() works on a read-only handle too. Only a lock
        // that cannot be opened at all is an error, and it is logged as one, never taken for
        // "another run is busy", which silently stopped every later move.
        $lockFile = $target . '/.migration.lock';
        $lock = @fopen($lockFile, 'c');
        if ($lock === false) {
            $lock = @fopen($lockFile, 'r');
        } else {
            @chmod($lockFile, 0664);
        }
        if ($lock === false) {
            SecureLogger::error('[Digital Library] cannot open storage/digital/.migration.lock: check the permissions of storage/digital');
            $result['remaining'] = 1;
            return $result;
        }
        // A page request gives up at once when another run holds the lock. The cron and
        // "Sposta ora" (retryFailed) wait up to LOCK_WAIT seconds for it: a page request
        // moving its 200 files must not turn the daily pass into a no-op.
        $deadline = microtime(true) + ($retryFailed ? self::LOCK_WAIT : 0.0);
        while (!flock($lock, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($lock);
                $result['remaining'] = 1; // another run is moving them right now
                return $result;
            }
            usleep(100000);
        }

        try {
            $started = microtime(true);
            $copied = 0;
            $known = self::failedRegistry($target);
            $registry = [];
            foreach (self::legacyFiles($legacy) as $name => $entry) {
                $stamp = $entry->getSize() . ':' . $entry->getMTime();
                if (!$retryFailed && ($known[$name] ?? null) === $stamp) {
                    $registry[$name] = $stamp; // still served, checked, from the old folder
                    continue;
                }
                if ($result['moved'] >= $maxFiles || microtime(true) - $started > $maxSeconds) {
                    $result['remaining']++;
                    if (isset($known[$name])) {
                        $registry[$name] = $known[$name];
                    }
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
                    clearstatcache(true, $entry->getPathname());
                    $registry[$name] = @filesize($entry->getPathname()) . ':' . @filemtime($entry->getPathname());
                }
            }
            if ($registry !== $known) {
                self::writeFailedRegistry($target, $registry);
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

    /**
     * Whether two files hold the same bytes, by SHA-256. A file that cannot be read is
     * never "the same": two failed reads both return false, and false === false once
     * let the move delete an original it had not compared.
     */
    private static function sameBytes(string $a, string $b): bool
    {
        $ha = @hash_file('sha256', $a);
        $hb = @hash_file('sha256', $b);
        return is_string($ha) && is_string($hb) && hash_equals($ha, $hb);
    }

    /**
     * The instant move: a hard link, then the original name removed. link() is the bare
     * system call: it fails across devices and mount points (EXDEV) instead of copying, and
     * never replaces an existing $to. Any failure, a host with link() disabled included,
     * undoes the link and leaves the move to the budgeted, verified copy.
     */
    private static function linkMove(string $from, string $to): bool
    {
        if (!function_exists('link') || !@link($from, $to)) {
            return false;
        }
        if (@unlink($from)) {
            return true;
        }
        @unlink($to); // the original cannot be removed: let the copy path report it
        return false;
    }

    /** How many files the last runs could not move (they wait for the cron or "Sposta ora"). */
    public static function knownFailures(string $target): int
    {
        return count(self::failedRegistry($target));
    }

    /** @return array<string, string> */
    private static function failedRegistry(string $target): array
    {
        $raw = @file_get_contents($target . '/' . self::FAILED_REGISTRY);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return [];
        }
        return array_filter($data, static fn ($v, $k): bool => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH);
    }

    /** @param array<string, string> $registry */
    private static function writeFailedRegistry(string $target, array $registry): void
    {
        $file = $target . '/' . self::FAILED_REGISTRY;
        if ($registry === []) {
            @unlink($file);
            return;
        }
        ksort($registry);
        self::writeAtomically($file, (string) json_encode($registry, JSON_UNESCAPED_SLASHES));
    }

    /** @return int files in $dir that a reader could be served (dotfiles and index.html aside) */
    public static function countFiles(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $n = 0;
        foreach (self::legacyFiles($dir) as $_) {
            $n++;
        }
        return $n;
    }

    /**
     * One file. Returns the bytes copied or compared (0 for a rename) when it was moved,
     * 'deferred' when a cross-disk copy or a comparison does not fit the budget, 'failed'
     * otherwise.
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
            if (@filesize($to) === $size) {
                // Comparing reads both files: a page request leaves a large one to the cron.
                if ($size > $copyBudget) {
                    return 'deferred';
                }
                if (self::sameBytes($to, $from)) {
                    if (@unlink($from)) {
                        return $size;
                    }
                    SecureLogger::error('[Digital Library] moved to storage/digital, but the original cannot be removed: check the permissions of public/uploads/digital', [
                        'file' => basename($from),
                    ]);
                    return 'failed';
                }
            }
            SecureLogger::error('[Digital Library] storage/digital already holds a different file with this name; left in place', [
                'file' => basename($from),
            ]);
            return 'failed';
        }
        if (!is_dir(dirname($to)) && !@mkdir(dirname($to), 0775, true) && !is_dir(dirname($to))) {
            return 'failed';
        }
        // Never rename(): across devices, and across two mount points of one filesystem (bind
        // mounts, Docker volumes: same st_dev), PHP's rename() does not fail but copies straight
        // onto $to and ignores whether the original could be removed, so the budget, the
        // verified partial copy and the check below would never run.
        $renamed = self::$renameForTests !== null
            ? (self::$renameForTests)($from, $to)
            : self::linkMove($from, $to);
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
        if (@filesize($partial) !== $size || !self::sameBytes($partial, $from)) {
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
        // The copy is in place and verified: removing the original is the last step. If it
        // fails (an old folder the web server cannot write to) this is a failure like any
        // other, remembered: the next run finds identical bytes on both sides and finishes it.
        if (@unlink($from)) {
            return $size;
        }
        SecureLogger::error('[Digital Library] copied to storage/digital, but the original cannot be removed: check the permissions of public/uploads/digital', [
            'file' => basename($from),
        ]);
        return 'failed';
    }
}
