<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Brings public/.htaccess of an upgraded installation to the 0.8.6 private-uploads rules (#480).
 *
 * public/.htaccess is on the updater's preserved list, because it holds the operator's own
 * rules, so a change to the shipped file never reaches an upgraded install. Four shapes of
 * the /uploads/ rules were shipped over the years, and an install keeps whichever it was
 * installed with:
 *
 *  A. (≤ 0.7.17) no private rule at all: every existing file under /uploads/ is served as
 *     it is, Digital Library files and archive documents included;
 *  B. (0.7.18 – 0.7.70) private rules on an anchored %{REQUEST_URI}: correct at the domain
 *     root, but under a subfolder the anchor never matches and existing private files are
 *     served as they are;
 *  C. (0.7.71 – 0.8.5) the per-directory private rule without [NC]: on a case-insensitive
 *     disk /uploads/Digital/x skips it and reaches the public rule;
 *  D. (0.8.6) the private rule with [NC], before the public one.
 *
 * Each shape is replaced only where its lines are still exactly what was shipped: a rule
 * the operator wrote is theirs, and is left alone. The result must have the private rule
 * with [NC] before any public /uploads/ rule; otherwise the file is not touched at all.
 * PDF and WAV also join the no-gzip list, so a Range request gets the bytes on disk.
 */
final class PrivateUploadsRewriteHeal
{
    private const PRIVATE_RULE = 'RewriteRule ^uploads/(digital|archives/documents|storage)/ index.php [NC,QSA,L]';
    private const PUBLIC_RULE = 'RewriteRule ^uploads/ - [L]';

    /** C: the 0.7.71 – 0.8.5 private rule, without [NC]. */
    private const REWRITE_C = '/^([ \t]*)RewriteRule \^uploads\/\(digital\|archives\/documents\|storage\)\/ index\.php \[QSA,L\][ \t]*$/m';

    /** A: "RewriteCond ^/uploads/ + RewriteRule ^ - [L]" with nothing in between. */
    private const PASSTHROUGH_A = '/^([ \t]*)RewriteCond %\{REQUEST_URI\} \^\/uploads\/ \[NC\][ \t]*\r?\n[ \t]*RewriteRule \^ - \[L\][ \t]*(?=\r?\n|$)/m';

    /** B, first half: the public passthrough excluding the private folders. */
    private const PASSTHROUGH_B = '/^([ \t]*)RewriteCond %\{REQUEST_URI\} \^\/uploads\/ \[NC\][ \t]*\r?\n[ \t]*RewriteCond %\{REQUEST_URI\} !\^\/uploads\/\(digital\|archives\/documents\|storage\)\/ \[NC\][ \t]*\r?\n[ \t]*RewriteRule \^ - \[L\][ \t]*(?=\r?\n|$)/m';

    /** B, second half: the private rule on REQUEST_URI, with the comment that introduced it. */
    private const PRIVATE_B = '/^(?:[ \t]*# PRIVATE uploaded content[^\r\n]*\r?\n(?:[ \t]*#[^\r\n]*\r?\n)*)?[ \t]*RewriteCond %\{REQUEST_URI\} \^\/uploads\/\(digital\|archives\/documents\|storage\)\/ \[NC\][ \t]*\r?\n[ \t]*RewriteRule \^ index\.php \[QSA,L\][ \t]*\r?\n?/m';

    private const NOGZIP_OLD = '/^([ \t]*)SetEnvIfNoCase Request_URI \\\\\.\(\?:gif\|jpe\?g\|png\|webp\|ico\|woff2\?\|gz\|zip\|epub\|mp4\|webm\|mp3\|m4a\|ogg\)\$ no-gzip dont-vary[ \t]*$/m';
    private const NOGZIP_NEW = '$1SetEnvIfNoCase Request_URI \.(?:gif|jpe?g|png|webp|ico|woff2?|gz|zip|epub|pdf|mp4|webm|mp3|m4a|ogg|wav)$ no-gzip dont-vary';

    /** The content with the shipped rules brought to 0.8.6; unchanged when nothing applies or the result would not be safe. */
    public static function apply(string $content): string
    {
        $out = $content;
        if (preg_match(self::REWRITE_C, $out) === 1) {
            $out = (string) preg_replace(self::REWRITE_C, '$1' . self::PRIVATE_RULE, $out);
        } elseif (preg_match(self::PASSTHROUGH_B, $out) === 1 && preg_match(self::PRIVATE_B, $out) === 1) {
            // Both halves of B, exactly as shipped: drop the REQUEST_URI private rule and put
            // the per-directory pair where the public passthrough was, private rule first.
            $out = (string) preg_replace(self::PRIVATE_B, '', $out, 1);
            $out = (string) preg_replace_callback(self::PASSTHROUGH_B, static fn (array $m): string => self::block($m[1]), $out, 1);
        } elseif (preg_match(self::PASSTHROUGH_A, $out) === 1 && !self::hasPrivateRule($out)) {
            $out = (string) preg_replace_callback(self::PASSTHROUGH_A, static fn (array $m): string => self::block($m[1]), $out, 1);
        }
        $out = (string) preg_replace(self::NOGZIP_OLD, self::NOGZIP_NEW, $out);

        if ($out === $content) {
            return $content;
        }
        // Whatever changed in the rewrite rules must leave the private rule, with [NC], ahead
        // of every public /uploads/ rule; anything else is a file we do not understand.
        return self::rulesChanged($content, $out) && !self::safe($out) ? $content : $out;
    }

    /**
     * Heal the file in place, keeping a backup (in storage/backups) and the file's mode. True
     * when nothing needed doing or the rewrite succeeded; false when it was needed and failed.
     */
    public static function heal(?string $path = null, ?string $backupDir = null): bool
    {
        $path ??= dirname(__DIR__, 2) . '/public/.htaccess';
        // The copy goes outside the web root: public/.htaccess.bak-* would be served as a
        // plain file wherever no server rule hides dotfiles (nginx, some shared hosts).
        $backupDir ??= dirname(__DIR__, 2) . '/storage/backups';
        $content = @file_get_contents($path);
        if (!is_string($content)) {
            return !file_exists($path);
        }
        $healed = self::apply($content);
        if ($healed === $content) {
            return true;
        }
        $mode = @fileperms($path);
        if (!is_int($mode) || (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true))
            || !@copy($path, $backupDir . '/htaccess.bak-480-' . date('YmdHis'))) {
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

    /** Whether $content is in the safe 0.8.6 shape: the [NC] private rule before every public /uploads/ rule. */
    public static function safe(string $content): bool
    {
        $private = strpos($content, self::PRIVATE_RULE);
        if ($private === false) {
            return false;
        }
        foreach (['/^[ \t]*RewriteRule \^uploads\/ - \[L\]/m', '/^[ \t]*RewriteCond %\{REQUEST_URI\} \^\/uploads\/ \[NC\]/m'] as $public) {
            if (preg_match($public, $content, $m, PREG_OFFSET_CAPTURE) === 1 && $m[0][1] < $private) {
                return false;
            }
        }
        return true;
    }

    private static function hasPrivateRule(string $content): bool
    {
        return preg_match('/^[ \t]*Rewrite(?:Rule|Cond)[^\r\n]*uploads\/?\(digital\|archives\/documents\|storage\)/m', $content) === 1;
    }

    private static function rulesChanged(string $before, string $after): bool
    {
        $rules = static fn (string $s): string => implode("\n", preg_grep('/^\s*Rewrite/', preg_split('/\r?\n/', $s) ?: []) ?: []);
        return $rules($before) !== $rules($after);
    }

    /** The 0.8.6 pair, at the indentation of the lines it replaces. */
    private static function block(string $indent): string
    {
        return $indent . "# Private uploads (Digital Library files, archive documents, generic storage) go\n"
            . $indent . "# through index.php, which checks who may open them; [NC] keeps /uploads/Digital/\n"
            . $indent . "# from slipping past on a case-insensitive disk. Added by the 0.8.6 upgrade (#480).\n"
            . $indent . self::PRIVATE_RULE . "\n\n"
            . $indent . "# Allow direct access to the remaining PUBLIC static files in /uploads/.\n"
            . $indent . self::PUBLIC_RULE;
    }
}
