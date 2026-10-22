<?php
declare(strict_types=1);

/**
 * Guard for the "Search engine verification" field in Settings > Advanced.
 *
 * The admin pastes the <meta> tag a service hands out (Google Search Console,
 * Bing, Yandex, Pinterest, Facebook) and the public layout prints it in every
 * <head>. ContentSanitizer::parseVerificationMetaTags() keeps only the
 * name/content of a verification tag, so the field cannot become a way to
 * inject a script, an attribute, or a `robots`/`description` meta that would
 * deindex the site. This test proves:
 *   A. Every real-world snippet the services hand out is accepted as-is.
 *   B. Anything that is not a verification tag is dropped.
 *   C. The canonical form round-trips (save → render gives the same tags).
 *
 * Run:  php tests/site-verification-meta.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\ContentSanitizer;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

echo "A. snippets handed out by the services\n";
$snippets = [
    'google' => ['<meta name="google-site-verification" content="e2e-google-verification-code" />', 'google-site-verification', 'e2e-google-verification-code'],
    'bing' => ['<meta name="msvalidate.01" content="1A2B3C4D5E6F7A8B9C0D1E2F3A4B5C6D" />', 'msvalidate.01', '1A2B3C4D5E6F7A8B9C0D1E2F3A4B5C6D'],
    'yandex' => ["<meta name='yandex-verification' content='0123456789abcdef'>", 'yandex-verification', '0123456789abcdef'],
    'pinterest' => ['<meta name="p:domain_verify" content="abc123def456"/>', 'p:domain_verify', 'abc123def456'],
    'facebook' => ['<META CONTENT="xyz987" NAME="facebook-domain-verification">', 'facebook-domain-verification', 'xyz987'],
];
foreach ($snippets as $label => [$html, $name, $content]) {
    $tags = ContentSanitizer::parseVerificationMetaTags($html);
    $check($tags === [['name' => $name, 'content' => $content]], "{$label} snippet accepted");
}

$all = implode("\n", array_column($snippets, 0));
$check(count(ContentSanitizer::parseVerificationMetaTags($all)) === 5, 'five tags pasted one per line → five tags');
$check(
    count(ContentSanitizer::parseVerificationMetaTags($snippets['google'][0] . "\n" . $snippets['google'][0])) === 1,
    'the same tag pasted twice is kept once'
);

echo "B. everything else is dropped\n";
$rejected = [
    'robots meta' => '<meta name="robots" content="noindex">',
    'description meta' => '<meta name="description" content="spam">',
    'http-equiv refresh' => '<meta http-equiv="refresh" content="0;url=https://evil.example">',
    'script' => '<script>alert(1)</script>',
    'bare code' => 'e2e-google-verification-code',
    'quote breakout in content' => '<meta name="google-site-verification" content="a&quot; onload=&quot;x">',
    'space in content' => '<meta name="google-site-verification" content="a b">',
    'empty content' => '<meta name="google-site-verification" content="">',
    'markup in name' => '<meta name="google-site-verification><script>" content="abc">',
];
foreach ($rejected as $label => $html) {
    $check(ContentSanitizer::parseVerificationMetaTags($html) === [], "{$label} rejected");
}

$mixed = $snippets['google'][0] . "\n<script>alert(1)</script>\n" . $rejected['robots meta'];
$tags = ContentSanitizer::parseVerificationMetaTags($mixed);
$check(count($tags) === 1 && $tags[0]['name'] === 'google-site-verification', 'only the verification tag survives a mixed paste');
$check(ContentSanitizer::parseVerificationMetaTags('') === [], 'empty input → no tags');

echo "C. canonical form round-trips\n";
$canonical = ContentSanitizer::formatVerificationMetaTags(ContentSanitizer::parseVerificationMetaTags($all));
$check(substr_count($canonical, "\n") === 4 && !str_contains($canonical, '/>'), 'one rebuilt tag per line');
$check(ContentSanitizer::parseVerificationMetaTags($canonical) === ContentSanitizer::parseVerificationMetaTags($all), 'canonical form parses to the same tags');
$check(ContentSanitizer::formatVerificationMetaTags([]) === '', 'no tags → empty string');

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
