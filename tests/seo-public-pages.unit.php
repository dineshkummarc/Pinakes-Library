<?php
declare(strict_types=1);
/**
 * Weight and crawl hygiene of the public pages.
 *
 * - The JavaScript translation table is a script of its own, versioned by the
 *   locale file, and carries only the strings that differ from their key: on an
 *   Italian install it used to inline about 620 KB of identity entries in every
 *   page.
 * - The sitemap lists the address a CMS page is served at, not the legacy slug
 *   that only answers with a 301 (about-us, privacy-policy on an Italian install).
 * - A name with a slash has no page that answers (Apache refuses %2F before PHP),
 *   so the sitemap leaves it out instead of reporting a 404.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\SitemapGenerator;
use App\Support\TranslationScript;

$n = 0;
function check_seo(bool $ok, string $label): void
{
    global $n;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    echo 'PASS ' . (++$n) . ' ' . $label . "\n";
}

$decode = static function (?string $body): array {
    $json = preg_replace('/^window\.i18nTranslations = (.*);\s*$/s', '$1', (string) $body);
    $data = json_decode((string) $json, true);
    return is_array($data) ? $data : [];
};

// ── Translation script ──────────────────────────────────────────────────────
$it = TranslationScript::body('it_IT');
check_seo($it !== null && str_starts_with($it, 'window.i18nTranslations = '), 'it_IT: the script assigns window.i18nTranslations');
$itTable = $decode($it);
$itSource = json_decode((string) file_get_contents(dirname(__DIR__) . '/locale/it_IT.json'), true);
check_seo(count($itSource) > 1000 && count($itTable) < 100, 'it_IT: the identity entries are gone (' . count($itTable) . ' of ' . count($itSource) . ' left)');
check_seo(strlen((string) $it) < 10000, 'it_IT: the script is a few KB, not the whole table');
$identity = array_filter($itTable, static fn ($v, $k): bool => $v === $k, ARRAY_FILTER_USE_BOTH);
check_seo($identity === [], 'it_IT: no entry maps a string to itself');

$en = $decode(TranslationScript::body('en_US'));
check_seo(($en['Annulla'] ?? null) === 'Cancel', 'en_US: a real translation is kept (Annulla → Cancel)');

check_seo(TranslationScript::body('xx_XX') === null, 'a locale with no file has no script');
foreach (['../version', 'it_IT/../../.env', 'it', 'IT_it', "it_IT\0"] as $bad) {
    check_seo(TranslationScript::body($bad) === null, 'no file is read for ' . json_encode($bad));
}

$url = TranslationScript::url('it_IT');
check_seo(preg_match('#/i18n/it_IT\?v=[0-9a-f]{12}$#', $url) === 1, 'the URL is versioned by the locale file: ' . $url);
// nginx's static location (~* \.(css|js|...)$ in .nginx.conf.example) would 404 it.
check_seo(preg_match('#\.(css|js|woff2?|ttf|otf|eot|ico)$#i', (string) parse_url($url, PHP_URL_PATH)) === 0, 'the script path has no static-asset extension nginx would serve from disk');
check_seo($url === TranslationScript::url('it_IT'), 'the version is stable while the file does not change');
check_seo(TranslationScript::url('en_US') !== $url, 'each locale has its own URL');
// Same size, same mtime, other content: the version must still change.
$probeLocale = 'zz_ZZ';
$probeFile = dirname(__DIR__) . '/locale/' . $probeLocale . '.json';
try {
    file_put_contents($probeFile, '{"Annulla":"Cancel"}');
    touch($probeFile, 1700000000);
    clearstatcache();
    $before = TranslationScript::url($probeLocale);
    file_put_contents($probeFile, '{"Annulla":"Abort!"}');
    touch($probeFile, 1700000000);
    clearstatcache();
    check_seo(TranslationScript::url($probeLocale) !== $before, 'the version follows the content, not the mtime and size');
} finally {
    @unlink($probeFile);
}

// ── Sitemap paths ───────────────────────────────────────────────────────────
$ref = new ReflectionClass(SitemapGenerator::class);
$generator = $ref->newInstanceWithoutConstructor();
$baseUrl = $ref->getProperty('baseUrl');
$baseUrl->setValue($generator, 'https://example.test');
$locale = $ref->getProperty('defaultLocale');
$cmsPath = $ref->getMethod('cmsPagePath');

$locale->setValue($generator, 'it_IT');
check_seo($cmsPath->invoke($generator, 'about-us') === '/chi-siamo', 'it_IT: about-us is listed at /chi-siamo');
check_seo($cmsPath->invoke($generator, 'privacy-policy') === '/privacy', 'it_IT: privacy-policy is listed at /privacy');
check_seo($cmsPath->invoke($generator, 'chi-siamo') === '/chi-siamo', 'it_IT: chi-siamo stays /chi-siamo');
check_seo($cmsPath->invoke($generator, 'orari-e-servizi') === '/orari-e-servizi', 'a page of its own keeps its slug');
check_seo($cmsPath->invoke($generator, 'pagina con spazi') === '/pagina%20con%20spazi', 'a page of its own is still encoded');

$locale->setValue($generator, 'en_US');
check_seo($cmsPath->invoke($generator, 'chi-siamo') === '/about-us', 'en_US: chi-siamo is listed at /about-us');
check_seo($cmsPath->invoke($generator, 'privacy-policy') === '/privacy-policy', 'en_US: privacy-policy stays where it is served');

$routable = $ref->getMethod('isRoutableName');
check_seo($routable->invoke(null, 'Carla Lonzi') === true, 'an ordinary name is listed');
check_seo($routable->invoke(null, 'Seminar "Intimate/Sexual Citizenship" (2005 Ljubljana') === false, 'a name with a slash is left out');
check_seo($routable->invoke(null, 'A\\B') === false, 'a name with a backslash is left out');

// The separate script must remain safe to embed, including unusual translated content.
$probeFile = dirname(__DIR__) . '/locale/zz_ZZ.json';
try {
    $dangerous = '</script><script>alert("x")</script>&';
    file_put_contents($probeFile, json_encode([$dangerous => $dangerous . ' traduit']));
    $script = TranslationScript::body('zz_ZZ');
    check_seo(!str_contains((string) $script, '</script>') && !str_contains((string) $script, '<script>')
        && ($decode($script)[$dangerous] ?? null) === $dangerous . ' traduit',
        'HTML in a key or translation cannot break out of a script; its original text is preserved');

    $unicode = "日本語 café 👩🏽‍💻\nseconda riga";
    file_put_contents($probeFile, json_encode(['Messaggio' => $unicode], JSON_UNESCAPED_UNICODE));
    check_seo(($decode(TranslationScript::body('zz_ZZ'))['Messaggio'] ?? null) === $unicode,
        'non-Latin text, combining emoji and line breaks survive the external script unchanged');

    clearstatcache();
    $beforeTouch = TranslationScript::url('zz_ZZ');
    touch($probeFile, 1700000000);
    clearstatcache();
    check_seo(TranslationScript::url('zz_ZZ') === $beforeTouch,
        'a deployment that only changes timestamps reuses the same translation cache URL');
} finally {
    @unlink($probeFile);
}

$locale->setValue($generator, 'fr_FR');
check_seo($cmsPath->invoke($generator, 'cookie-policy') === '/politique-cookies',
    'the legacy cookie page is listed at the French canonical route, avoiding its redirect');
check_seo($cmsPath->invoke($generator, 'café & letture') === '/caf%C3%A9%20%26%20letture',
    'an independent Unicode CMS slug stays its own correctly encoded URL');

echo "\nALL {$n} PASS\n";
