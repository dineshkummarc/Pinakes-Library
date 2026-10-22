<?php
declare(strict_types=1);

/**
 * Pure helpers behind the SEO audit fixes (the HTTP side is in
 * tests/seo-audit.spec.js):
 *   A. HtmlHelper::metaExcerpt() — biographies, page texts and descriptions
 *      become a one-line, tag-free meta description cut at a word.
 *   B. LanguageCodes::toBcp47Tags() — libri.lingua ("italiano, inglese")
 *      becomes the BCP 47 codes schema.org inLanguage expects.
 *   C. TrailingSlash::redirectTarget() — "/catalogo/" gets a 301 to
 *      "/catalogo" instead of a 404, without becoming an open redirect.
 *
 * Run:  php tests/seo-audit.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Support\HtmlHelper;
use App\Support\LanguageCodes;
use App\Support\TrailingSlash;
use Slim\Psr7\Factory\ServerRequestFactory;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

echo "A. metaExcerpt\n";
$check(HtmlHelper::metaExcerpt('') === '', 'empty text → empty excerpt');
$check(
    HtmlHelper::metaExcerpt("<p>Scrittrice e <strong>attivista</strong>,\r\nnata a Roma.</p><p>Vive a Milano.</p>") === 'Scrittrice e attivista, nata a Roma. Vive a Milano.',
    'tags stripped, line breaks collapsed, adjacent paragraphs keep a space'
);
$check(HtmlHelper::metaExcerpt('Caf&eacute; &amp; libri') === 'Café & libri', 'entities decoded (the layout re-escapes once)');
$long = str_repeat('parola ', 60);
$excerpt = HtmlHelper::metaExcerpt($long);
$check(mb_strlen($excerpt) <= 155, 'long text cut to at most 155 characters');
$check(str_ends_with($excerpt, 'parola…'), 'cut at a word boundary, with an ellipsis');
$check(mb_strlen(HtmlHelper::metaExcerpt($long, 500)) <= 500, 'custom limit honoured');
$check(!str_contains(HtmlHelper::metaExcerpt('<script>alert(1)</script>Testo'), '<'), 'no markup survives');
foreach (['道', 'œ', '😀'] as $character) {
    $unicodeExcerpt = HtmlHelper::metaExcerpt(str_repeat($character, 160));
    $check($unicodeExcerpt === str_repeat($character, 154) . '…'
        && json_encode(['description' => $unicodeExcerpt]) !== false,
        "{$character}: truncation preserves Unicode and can be encoded as JSON-LD");
}
$check(HtmlHelper::metaExcerpt(str_repeat('x', 150) . ' —. trailing words') === str_repeat('x', 150) . '…',
    'trailing punctuation is removed as whole Unicode characters');

echo "B. toBcp47Tags\n";
$check(LanguageCodes::toBcp47Tags('italiano') === ['it'], '"italiano" → it');
$check(LanguageCodes::toBcp47Tags('Inglese') === ['en'], '"Inglese" → en');
$check(LanguageCodes::toBcp47Tags('italiano, inglese') === ['it', 'en'], 'two languages → two tags');
$check(LanguageCodes::toBcp47Tags('Italiano e Francese') === ['it', 'fr'], '"X e Y" → two tags');
$check(LanguageCodes::toBcp47Tags('ita') === ['it'], 'ISO 639-2 code → 639-1');
$check(LanguageCodes::toBcp47Tags('Greco antico') === ['grc'], 'no 639-1 → the 639-2 code');
$check(LanguageCodes::toBcp47Tags('Klingon') === [], 'unknown name left out, not published as free text');
$check(LanguageCodes::toBcp47Tags('') === [], 'empty → none');

echo "C. TrailingSlash\n";
$factory = new ServerRequestFactory();
$target = static fn(string $method, string $uri): ?string => TrailingSlash::redirectTarget($factory->createServerRequest($method, $uri));
$check($target('GET', 'http://x.test/catalogo/') === '/catalogo', '/catalogo/ → /catalogo');
$check($target('GET', 'http://x.test/catalogo/?page=2&sort=a') === '/catalogo?page=2&sort=a', 'query string kept');
$check($target('HEAD', 'http://x.test/chi-siamo/') === '/chi-siamo', 'HEAD redirected like GET');
$check($target('GET', 'http://x.test/sub/editore/Einaudi/') === '/sub/editore/Einaudi', 'base path kept');
$check($target('POST', 'http://x.test/catalogo/') === null, 'POST never redirected (body would be lost)');
$check($target('GET', 'http://x.test/catalogo') === null, 'no trailing slash → nothing to do');
$check($target('GET', 'http://x.test/') === null, 'root is not redirected');
$check($target('GET', 'http://x.test//evil.example/') === '/evil.example', '//host/ stays on this site (no protocol-relative Location)');
$check($target('GET', 'http://x.test/%5Cevil.example/') !== '//evil.example', 'encoded backslash is not turned into //host');

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 ? 0 : 1);
