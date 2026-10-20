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

// A rule the operator wrote is theirs.
$a = (string) file_get_contents(__DIR__ . '/fixtures/htaccess-history/0.7.13.htaccess');
$edited = str_replace("RewriteCond %{REQUEST_URI} ^/uploads/ [NC]\n    RewriteRule ^ - [L]", "RewriteCond %{REQUEST_URI} ^/uploads/ [NC]\n    RewriteCond %{HTTP_HOST} ^cdn\\. [NC]\n    RewriteRule ^ - [L]", $a);
$check($edited !== $a && !Heal::safe(Heal::apply($edited)) && preg_replace('/^.*no-gzip.*$/m', '', Heal::apply($edited)) === preg_replace('/^.*no-gzip.*$/m', '', $edited),
    'an /uploads/ rule the operator edited is left alone');
$b = (string) file_get_contents(__DIR__ . '/fixtures/htaccess-history/0.7.21.htaccess');
$half = preg_replace('/^\s*RewriteCond %\{REQUEST_URI\} \^\/uploads\/\(digital\|archives\/documents\|storage\)\/ \[NC\]\r?\n\s*RewriteRule \^ index\.php \[QSA,L\]\r?\n/m', '', $b);
$check($half !== $b && preg_replace('/^.*no-gzip.*$/m', '', Heal::apply((string) $half)) === preg_replace('/^.*no-gzip.*$/m', '', (string) $half),
    'a 0.7.18-style file missing its private half is not rewritten into an unsafe order');

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS" : "{$fail} FAILED, {$pass} passed") . "\n";
exit($fail > 0 ? 1 : 0);
