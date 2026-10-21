<?php
declare(strict_types=1);
/**
 * The administrator is told when the server's PHP lacks the fixes for
 * CVE-2026-109618 and CVE-2026-109619 (PHP 8.5.12, 8.4.27, 8.3.36, 8.2.35).
 *
 * - Each patched branch is reported below its first fixed release, and not from it on.
 * - A branch older than every patched one has no fix from the PHP project and is
 *   reported against the oldest fixed release; a branch newer than all of them is not.
 * - The dashboard/updates partial renders only for an administrator, and only
 *   when the running PHP is affected.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\PhpSecurityAdvisory;

if (!function_exists('__')) {
    function __(string $s): string { return $s; }
}

$n = 0;
function check_advisory(bool $ok, string $label): void
{
    global $n;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    echo 'PASS ' . (++$n) . ' ' . $label . "\n";
}

$fixedFor = static fn (string $v): ?string => PhpSecurityAdvisory::check($v)['fixed'] ?? null;

foreach (PhpSecurityAdvisory::FIXED as $branch => $fixed) {
    [$maj, $min, $patch] = array_map('intval', explode('.', $fixed));
    $previous = "{$maj}.{$min}." . ($patch - 1);
    $next = "{$maj}.{$min}." . ($patch + 1);
    check_advisory($fixedFor($previous) === $fixed, "{$previous} is reported, to move to {$fixed}");
    check_advisory($fixedFor("{$maj}.{$min}.0") === $fixed, "{$maj}.{$min}.0 is reported");
    check_advisory($fixedFor($fixed) === null, "{$fixed} has the fixes");
    check_advisory($fixedFor($next) === null, "{$next} has the fixes");
}

check_advisory($fixedFor('8.4.26') === '8.4.27', 'the 0.8.6 Docker image base (8.4.26) is reported');
check_advisory($fixedFor('8.3.35') === '8.3.36', 'a cPanel host on 8.3.35 is reported');
check_advisory($fixedFor('8.4.10-1+0~20260101+debian12') === '8.4.27', 'a version with a distribution suffix is read by its release number');
check_advisory($fixedFor('8.1.31') === '8.2.35', 'a branch the PHP project no longer patches is reported against the oldest fix');
check_advisory($fixedFor('7.4.33') === '8.2.35', '7.4 is reported');
check_advisory($fixedFor('8.6.0') === null, 'a branch released after the fix is not reported');
check_advisory($fixedFor('8.6.0RC3') === null, 'a release candidate of a newer branch is not reported');
check_advisory($fixedFor('9.0.0') === null, 'a later major is not reported');
check_advisory($fixedFor('not a version') === null, 'an unreadable version is not reported');
check_advisory(PhpSecurityAdvisory::check(PHP_VERSION) === PhpSecurityAdvisory::check(), 'the default argument is the running PHP');
check_advisory(PhpSecurityAdvisory::CVES === ['CVE-2026-109618', 'CVE-2026-109619'], 'both CVE ids are named');

// ── The partial ─────────────────────────────────────────────────────────────
$render = static function (?string $role, string $class = 'mb-8'): string {
    $_SESSION = $role === null ? [] : ['user' => ['id' => 1, 'tipo_utente' => $role]];
    $phpAdvisoryClass = $class;
    ob_start();
    require dirname(__DIR__) . '/app/Views/partials/php-security-advisory.php';
    return (string) ob_get_clean();
};
$affected = PhpSecurityAdvisory::check() !== null;
$admin = $render('admin');
if ($affected) {
    $fixed = (string) PhpSecurityAdvisory::check()['fixed'];
    check_advisory(str_contains($admin, 'data-php-security-advisory') && str_contains($admin, 'role="alert"'), 'an administrator sees the notice on an affected PHP (' . PHP_VERSION . ')');
    check_advisory(str_contains($admin, PHP_VERSION) && str_contains($admin, $fixed), 'the notice names the running PHP and the release to move to');
    check_advisory(str_contains($admin, 'CVE-2026-109618, CVE-2026-109619'), 'the notice names both CVEs');
    check_advisory(str_contains($admin, 'href="https://www.php.net/"') && str_contains($admin, 'rel="noopener noreferrer"'), 'the advisory link opens safely');
    check_advisory(str_contains($admin, 'class="mb-8 p-4 bg-red-50'), 'the including page sets the outer spacing');
} else {
    check_advisory($admin === '', 'on a patched PHP (' . PHP_VERSION . ') even an administrator sees nothing');
}
check_advisory($render('staff') === '', 'staff see nothing: they cannot change the server');
check_advisory($render('standard') === '', 'a reader sees nothing');
check_advisory($render(null) === '', 'a visitor sees nothing');
check_advisory(!str_contains($render('admin', '"><script>'), '<script>'), 'the spacing class is escaped');

echo "\nALL {$n} PASS\n";
