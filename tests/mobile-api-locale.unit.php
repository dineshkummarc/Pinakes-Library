<?php
declare(strict_types=1);

/**
 * Mobile API language negotiation: LocaleMiddleware::negotiate() picks the
 * installed locale that best matches the app's Accept-Language header.
 *
 * Run: php tests/mobile-api-locale.unit.php
 */
require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/storage/plugins/mobile-api/src/Support/LocaleMiddleware.php';

use App\Plugins\MobileApi\Support\LocaleMiddleware;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

$installed = ['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'];
$n = static fn (string $h, array $a = []) => LocaleMiddleware::negotiate($h, $a === [] ? $installed : $a);

$check($n('en-US') === 'en_US', 'exact region (en-US → en_US)');
$check($n('de') === 'de_DE', 'bare language (de → de_DE)');
$check($n('en-GB') === 'en_US', 'other region of an installed language falls back to it');
$check($n('es-ES, fr;q=0.8') === 'fr_FR', 'skips an uninstalled language and keeps the next preference');
$check($n('da;q=0.5, en;q=0.9') === 'en_US', 'higher q wins regardless of order');
$check($n('fr, de') === 'fr_FR', 'equal q keeps the order the client sent');
$check($n('es, ja') === null, 'no installed language: nothing changes');
$check($n('') === null, 'empty header: nothing changes');
$check($n('*') === null, 'wildcard alone: nothing changes');
$check($n('en;q=0') === null, 'q=0 means "not acceptable"');
$check(LocaleMiddleware::negotiate('it-IT', []) === null, 'no installed locales: nothing changes');

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS" : "{$fail} FAILED, {$pass} passed") . "\n";
exit($fail === 0 ? 0 : 1);
