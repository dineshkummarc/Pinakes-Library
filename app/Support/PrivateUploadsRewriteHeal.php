<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Brings the two 0.8.6 lines of public/.htaccess to installs that predate them (#480).
 *
 * public/.htaccess is on the updater's preserved list, because it holds the operator's
 * own rules, so a change to the shipped file never reaches an upgraded install:
 *
 *  - the private-uploads rewrite gained [NC]. On a case-insensitive disk (macOS, some
 *    shared hosts) /uploads/Digital/x is the same file as /uploads/digital/x; without the
 *    flag that spelling skipped the rewrite to Pinakes and the public rule below served
 *    a Digital Library file still waiting in the old folder without any check;
 *  - PDF and WAV joined the no-gzip list, so a Range request gets the bytes on disk.
 *
 * Each line is replaced only when it is still exactly the line an older version
 * shipped: a line the operator edited is theirs, and is left alone.
 */
final class PrivateUploadsRewriteHeal
{
    private const REWRITE_OLD = '/^([ \t]*)RewriteRule \^uploads\/\(digital\|archives\/documents\|storage\)\/ index\.php \[QSA,L\][ \t]*$/m';
    private const REWRITE_NEW = '$1RewriteRule ^uploads/(digital|archives/documents|storage)/ index.php [NC,QSA,L]';

    private const NOGZIP_OLD = '/^([ \t]*)SetEnvIfNoCase Request_URI \\\\\.\(\?:gif\|jpe\?g\|png\|webp\|ico\|woff2\?\|gz\|zip\|epub\|mp4\|webm\|mp3\|m4a\|ogg\)\$ no-gzip dont-vary[ \t]*$/m';
    private const NOGZIP_NEW = '$1SetEnvIfNoCase Request_URI \.(?:gif|jpe?g|png|webp|ico|woff2?|gz|zip|epub|pdf|mp4|webm|mp3|m4a|ogg|wav)$ no-gzip dont-vary';

    /** The content with the old shipped lines replaced; unchanged when there is nothing to do. */
    public static function apply(string $content): string
    {
        $out = preg_replace(self::REWRITE_OLD, self::REWRITE_NEW, $content);
        $out = is_string($out) ? preg_replace(self::NOGZIP_OLD, self::NOGZIP_NEW, $out) : null;
        return is_string($out) ? $out : $content;
    }

    /**
     * Heal the file in place, keeping a backup and the file's mode. True when nothing
     * needed doing or the rewrite succeeded; false when it was needed and failed.
     */
    public static function heal(?string $path = null): bool
    {
        $path ??= dirname(__DIR__, 2) . '/public/.htaccess';
        $content = @file_get_contents($path);
        if (!is_string($content)) {
            return !file_exists($path);
        }
        $healed = self::apply($content);
        if ($healed === $content) {
            return true;
        }
        $mode = @fileperms($path);
        if (!is_int($mode) || !@copy($path, $path . '.bak-480-' . date('YmdHis'))) {
            return false;
        }
        $temporary = @tempnam(dirname($path), '.pinakes-480-');
        if (!is_string($temporary)) {
            return false;
        }
        try {
            return @file_put_contents($temporary, $healed, LOCK_EX) !== false
                && @chmod($temporary, $mode & 07777)
                && @rename($temporary, $path);
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
