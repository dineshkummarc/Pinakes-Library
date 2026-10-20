<?php
declare(strict_types=1);

/**
 * Issue #480 — every public/.htaccess Pinakes ever shipped, healed on upgrade.
 *
 * tests/fixtures/htaccess-history/ holds each distinct public/.htaccess of the released
 * tags (v0.1.1 … v0.8.6-rc.1). An install keeps the one it was installed with, because the
 * updater preserves the file. For each of them PrivateUploadsRewriteHeal must leave the
 * private-uploads rule with [NC] ahead of every public /uploads/ rule, change nothing else
 * but the no-gzip list, and do nothing the second time. A rule the operator edited stays.
 *
 * Run:  php tests/htaccess-heal-history-480.unit.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\PrivateUploadsRewriteHeal as Heal;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};
/** Lines that are not rewrite rules, comments, blanks or the no-gzip list: they must not change. */
$rest = static fn (string $s): array => array_values(array_filter(preg_split('/\r?\n/', $s) ?: [],
    static fn (string $l): bool => trim($l) !== '' && !str_starts_with(ltrim($l), '#')
        && !preg_match('/^\s*Rewrite(Rule|Cond)\b/', $l) && !str_contains($l, 'no-gzip')));

$files = glob(__DIR__ . '/fixtures/htaccess-history/*.htaccess') ?: [];
natsort($files);
$check(count($files) >= 13, count($files) . ' shipped versions of public/.htaccess');
foreach ($files as $file) {
    $version = basename($file, '.htaccess');
    $old = (string) file_get_contents($file);
    $new = Heal::apply($old);
    $hasUploads = str_contains($old, 'uploads');
    if (!$hasUploads) {
        $check($new === $old, "{$version}: no /uploads/ rule at all, left as it is");
        continue;
    }
    $check(Heal::safe($new), "{$version}: private uploads go through index.php with [NC], before the public /uploads/ rule");
    $check(!preg_match('/^\s*RewriteCond %\{REQUEST_URI\} \^\/uploads\//m', $new), "{$version}: no anchored REQUEST_URI rule left (it never matches under a subfolder)");
    $check($rest($new) === $rest($old), "{$version}: every other directive is untouched");
    $check(Heal::apply($new) === $new, "{$version}: a second upgrade changes nothing");
    $check(str_contains($new, 'epub|pdf|mp4|webm|mp3|m4a|ogg|wav)$ no-gzip') || !str_contains($old, 'no-gzip'), "{$version}: PDF and WAV are never compressed");
}

// Saved on Windows: CRLF line ends are recognised, and kept in the lines the heal adds.
foreach ($files as $file) {
    $version = basename($file, '.htaccess');
    $crlf = str_replace("\n", "\r\n", str_replace("\r\n", "\n", (string) file_get_contents($file)));
    if (!str_contains($crlf, 'uploads')) {
        continue;
    }
    $healed = Heal::apply($crlf);
    $check(Heal::safe($healed) && preg_match('/(?<!\r)\n/', $healed) !== 1 && Heal::apply($healed) === $healed,
        "{$version} with CRLF line ends: healed, still CRLF throughout, idempotent");
    $check(!str_contains($crlf, 'no-gzip') || str_contains($healed, "epub|pdf|mp4|webm|mp3|m4a|ogg|wav)\$ no-gzip dont-vary\r\n"), "{$version} with CRLF: the no-gzip list is updated too");
}

// heal() itself: true once the file is safe, false (and so logged by the caller) when it declines.
$dir = sys_get_temp_dir() . '/htaccess-heal-' . bin2hex(random_bytes(4));
mkdir($dir);
$path = $dir . '/.htaccess';
file_put_contents($path, (string) file_get_contents(__DIR__ . '/fixtures/htaccess-history/0.7.71.htaccess'));
$check(Heal::heal($path, $dir . '/backups') && Heal::safe((string) file_get_contents($path)), 'heal() on a shipped 0.7.71 file: true, and the file is safe');
$check(Heal::heal($path, $dir . '/backups') && count(glob($dir . '/backups/htaccess.bak-480-*') ?: []) === 1, 'a second heal() is true and makes no new backup');

// A safe file written by hand in another spelling is safe: no false warning on every upgrade.
$healed = Heal::apply((string) file_get_contents(__DIR__ . '/fixtures/htaccess-history/0.7.71.htaccess'));
$respelled = str_replace('index.php [NC,QSA,L]', 'index.php  [L,QSA,nocase]', $healed);
$check($respelled !== $healed && Heal::safe($respelled), 'the long flag names (nocase, last) count as NC and L');
$reordered = str_replace('index.php [NC,QSA,L]', 'index.php   [QSA, L, NC, E=MINE:1]', $healed);
$check($reordered !== $healed && Heal::safe($reordered) && Heal::apply($reordered) === $reordered, 'the private rule with flags in another order and extra spaces is safe, and left alone');
$withoutNc = str_replace('index.php [NC,QSA,L]', 'index.php [QSA,L]', $healed);
$check(!Heal::safe($withoutNc), 'without NC it is not safe');

// A rule the operator wrote is theirs.
$a = (string) file_get_contents(__DIR__ . '/fixtures/htaccess-history/0.7.13.htaccess');
$edited = str_replace("RewriteCond %{REQUEST_URI} ^/uploads/ [NC]\n    RewriteRule ^ - [L]", "RewriteCond %{REQUEST_URI} ^/uploads/ [NC]\n    RewriteCond %{HTTP_HOST} ^cdn\\. [NC]\n    RewriteRule ^ - [L]", $a);
file_put_contents($path, $edited);
$check(!Heal::heal($path, $dir . '/backups') && file_get_contents($path) !== false && !Heal::safe((string) file_get_contents($path)),
    'heal() declining an edited rule returns false, so the upgrade logs that private uploads may still leak');
array_map('unlink', glob($dir . '/backups/*') ?: []);
@rmdir($dir . '/backups');
@unlink($path);
@rmdir($dir);
$check($edited !== $a && !Heal::safe(Heal::apply($edited)) && preg_replace('/^.*no-gzip.*$/m', '', Heal::apply($edited)) === preg_replace('/^.*no-gzip.*$/m', '', $edited),
    'an /uploads/ rule the operator edited is left alone');
$b = (string) file_get_contents(__DIR__ . '/fixtures/htaccess-history/0.7.21.htaccess');
$half = preg_replace('/^\s*RewriteCond %\{REQUEST_URI\} \^\/uploads\/\(digital\|archives\/documents\|storage\)\/ \[NC\]\r?\n\s*RewriteRule \^ index\.php \[QSA,L\]\r?\n/m', '', $b);
$check($half !== $b && preg_replace('/^.*no-gzip.*$/m', '', Heal::apply((string) $half)) === preg_replace('/^.*no-gzip.*$/m', '', (string) $half),
    'a 0.7.18-style file missing its private half is not rewritten into an unsafe order');

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS" : "{$fail} FAILED, {$pass} passed") . "\n";
exit($fail > 0 ? 1 : 0);
