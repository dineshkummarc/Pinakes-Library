<?php
declare(strict_types=1);

/**
 * Updater custom-locale preservation — Nikola #238.
 *
 * A user-installed translation (e.g. nb_NO, not one of the four bundled
 * locales) vanished after an update: the release ZIP doesn't contain it, so the
 * orphan-cleanup pass deleted it. The fix classifies such files via
 * Updater::isCustomLocalePath() and skips them in orphan cleanup + the two copy
 * passes. This exercises the REAL method (via reflection) across the cases that
 * matter — not a str_contains check that would pass even if the regex were
 * wrong.
 */

require __DIR__ . '/../vendor/autoload.php';

$TESTNO = 0;
$failed = 0;
function check(bool $cond, string $desc): void
{
    global $TESTNO, $failed;
    $TESTNO++;
    if ($cond) {
        printf("[%02d] PASS: %s\n", $TESTNO, $desc);
    } else {
        printf("[%02d] FAIL: %s\n", $TESTNO, $desc);
        $failed++;
    }
}

// isCustomLocalePath() is pure (no DB use), so build the object without running
// the constructor and reach the private method via reflection.
$ref = new \ReflectionClass(\App\Support\Updater::class);
$updater = $ref->newInstanceWithoutConstructor();
$method = $ref->getMethod('isCustomLocalePath');
$isCustom = static fn(string $path): bool => (bool) $method->invoke($updater, $path);

// --- Bundled locales must NOT be treated as custom (they keep updating). ---
foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR'] as $bundled) {
    check($isCustom("locale/{$bundled}.json") === false, "bundled locale/{$bundled}.json is NOT custom (updates normally)");
    check($isCustom("locale/routes_{$bundled}.json") === false, "bundled locale/routes_{$bundled}.json is NOT custom");
}

// --- User-installed locales MUST be custom (preserved across updates). ---
check($isCustom('locale/nb_NO.json') === true, 'user locale/nb_NO.json IS custom (preserved — the Nikola case)');
check($isCustom('locale/routes_nb_NO.json') === true, 'user locale/routes_nb_NO.json IS custom');
check($isCustom('locale/es_ES.json') === true, 'any other locale/es_ES.json IS custom');
check($isCustom('locale/pt_BR.json') === true, 'locale/pt_BR.json IS custom');

// --- Path normalisation: leading slash + Windows separators. ---
check($isCustom('/locale/nb_NO.json') === true, 'leading-slash path is normalised and still custom');
check($isCustom('locale\\nb_NO.json') === true, 'backslash (Windows) path is normalised to forward slashes and still custom');

// --- Non-locale files must never be classified as custom locales. ---
check($isCustom('locale/README.md') === false, 'locale/README.md is not a custom locale');
check($isCustom('locale/nb_NO.txt') === false, 'locale/nb_NO.txt (wrong extension) is not a custom locale');
check($isCustom('app/Support/Updater.php') === false, 'a source file is not a custom locale');
check($isCustom('storage/uploads/nb_NO.json') === false, 'a json outside locale/ is not a custom locale');
check($isCustom('locale/nb.json') === false, 'a malformed locale code (nb.json) is not matched');

echo "\n" . ($failed === 0 ? "ALL {$TESTNO} PASS\n" : "{$failed}/{$TESTNO} FAILED\n");
exit($failed > 0 ? 1 : 0);
