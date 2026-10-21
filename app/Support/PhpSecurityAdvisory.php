<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Whether the PHP this installation runs on lacks a published security fix.
 *
 * A vulnerability in the PHP runtime is not something a Pinakes release can
 * correct: the fix ships with PHP itself, so it has to reach the server through
 * the hosting panel or the system packages. What Pinakes can do is tell the
 * administrator, who otherwise has no reason to look at the PHP version until
 * the server is already compromised.
 *
 * The table lists, for each branch the PHP project still patches, the first
 * release with the fixes. On 2026-10-21 PHP 8.5.12, 8.4.27, 8.3.36 and 8.2.35
 * fixed CVE-2026-109618 (memory corruption after an out-of-memory condition)
 * and CVE-2026-109619 (request state left behind for the next request on the
 * same worker), both unauthenticated remote code execution. A branch newer than
 * every listed one is not reported: it was released after the fix. Pinakes
 * itself needs 8.2 or later, so no older branch can run it.
 *
 * Distributions that backport fixes without changing the version (Debian and
 * Ubuntu in their own php packages, some hosting builds) are reported too: the
 * version string is the only thing PHP exposes, and the warning says so.
 */
final class PhpSecurityAdvisory
{
    /** @var array<string, string> branch => first release with the fixes */
    public const FIXED = [
        '8.2' => '8.2.35',
        '8.3' => '8.3.36',
        '8.4' => '8.4.27',
        '8.5' => '8.5.12',
    ];

    /** @var list<string> */
    public const CVES = ['CVE-2026-109618', 'CVE-2026-109619'];

    /**
     * The PHP 8 changelog. Unlike the php.net home page it does not move as news come
     * out, and each release has its own anchor, where the fix is listed by its GitHub
     * advisory ids (GHSA-w8hw-w9fx-j79w, GHSA-6wmf-4rv5-p3rp).
     */
    public const ADVISORY_URL = 'https://www.php.net/ChangeLog-8.php';

    /** The changelog entry of the release that fixes it, e.g. ChangeLog-8.php#8.4.27. */
    public static function changelogUrl(string $fixed): string
    {
        return self::ADVISORY_URL . '#' . rawurlencode($fixed);
    }

    /**
     * The release the server should move to, or null when $version has the fixes.
     *
     * @return array{current: string, fixed: string}|null
     */
    public static function check(string $version = PHP_VERSION): ?array
    {
        // A pre-release (8.4.27RC1, 8.4.27-dev) comes before its final and may lack the
        // fix, so its suffix takes part in the comparison; a distribution's own suffix
        // (8.4.10-1+0~deb12) does not, and the release number decides.
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)((?:-?(?:dev|alpha|beta|RC)\d*)?)/i', $version, $m) !== 1) {
            return null;
        }
        $branch = $m[1] . '.' . $m[2];
        $release = $branch . '.' . $m[3] . $m[4];
        $fixed = self::FIXED[$branch] ?? null;
        if ($fixed === null) {
            // Not a patched branch: either newer than all of them (fine) or older,
            // where the PHP project publishes no fix at all.
            $oldest = array_values(self::FIXED)[0];
            return version_compare($release, $oldest, '<') ? ['current' => $version, 'fixed' => $oldest] : null;
        }
        return version_compare($release, $fixed, '<') ? ['current' => $version, 'fixed' => $fixed] : null;
    }
}
