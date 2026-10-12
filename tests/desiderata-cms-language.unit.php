<?php
declare(strict_types=1);

// Editing a single site language must not erase translations absent from POST.
require dirname(__DIR__) . '/vendor/autoload.php';
if (!function_exists('__')) {
    function __(string $text, mixed ...$args): string { return $args ? vsprintf($text, $args) : $text; }
}
require_once dirname(__DIR__) . '/storage/plugins/desiderata/DesiderataPlugin.php';

use App\Support\I18n;

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) { $failures++; }
};
$merge = new ReflectionMethod(DesiderataPlugin::class, 'mergeCmsTexts');
$stored = ['it_IT' => ['title' => 'Prima'], 'en_US' => ['title' => 'Archived English'], 'da_DK' => ['title' => 'Arkiveret dansk']];
$result = $merge->invoke(null, $stored, ['it_IT' => ['title' => '  <b>Dopo</b>  ']], ['it_IT', 'en_US']);
$check($result['it_IT'] === ['title' => 'Dopo'], 'the submitted language is sanitized and replaced');
$check($result['en_US'] === $stored['en_US'], 'an available but unsubmitted language stays intact');
$check($result['da_DK'] === $stored['da_DK'], 'an inactive archived language stays intact');
$cleared = $merge->invoke(null, $stored, ['it_IT' => ['title' => '']], ['it_IT']);
$check(!isset($cleared['it_IT']) && $cleared['en_US'] === $stored['en_US'], 'blank fields restore defaults only for the edited language');
$check($merge->invoke(null, $stored, [], ['it_IT']) === $stored, 'visibility-only submissions leave every translation intact');
$check($merge->invoke(null, $stored, ['xx_YY' => ['title' => 'Invalid']], ['it_IT']) === $stored, 'unavailable submitted locales cannot modify the stored map');
try {
    $merge->invoke(null, $stored, ['it_IT' => ['title' => str_repeat('a', 161)]], ['it_IT']);
    $check(false, 'oversize text is rejected before persistence');
} catch (LengthException $e) {
    $check($e->getMessage() === 'title', 'oversize text is rejected before persistence');
}

// The operator's UI language must not select the website content language.
(new ReflectionProperty(I18n::class, 'languagesCache'))->setValue(null, ['it_IT' => 'Italiano', 'da_DK' => 'Dansk']);
(new ReflectionProperty(I18n::class, 'languagesLoadedFromDb'))->setValue(null, true);
(new ReflectionProperty(I18n::class, 'installationLocale'))->setValue(null, 'it_IT');
(new ReflectionProperty(I18n::class, 'locale'))->setValue(null, 'da_DK');
$check(I18n::getContentLocales() === ['it_IT' => 'Italiano'], 'content editing follows the site language, not the operator language');
(new ReflectionProperty(I18n::class, 'installationLocale'))->setValue(null, 'da_DK');
$check(I18n::getContentLocales() === ['da_DK' => 'Dansk'], 'a site language change selects the new language');
exit($failures ? 1 : 0);
