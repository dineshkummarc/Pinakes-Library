<?php
declare(strict_types=1);

/** Preset labels/descriptions must work in all shipped languages without renaming stored roles. */
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\I18n;
use App\Support\RolePresets;

$pass = 0;
$check = static function (bool $ok, string $label) use (&$pass): void {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    $pass++; echo "OK {$label}\n";
};
$savedLocale = I18n::getLocale();
try {
    foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'] as $locale) {
        $check(I18n::setLocale($locale), $locale . ': language supported');
        $catalogue = json_decode(file_get_contents(dirname(__DIR__) . '/locale/' . $locale . '.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['Catalogatore', 'Addetto prestiti', 'Archivista'] as $stored) {
            $display = RolePresets::displayName($stored);
            $description = RolePresets::description($stored);
            $check($display === $catalogue[$stored] && $display !== '', $locale . ': translated ' . $stored);
            $check($description !== '' && ($locale === 'it_IT' || !str_starts_with($description, 'Profilo iniziale')), $locale . ': translated purpose of ' . $stored);
            $check(RolePresets::storedName($display, $stored) === $stored, $locale . ': unchanged localized name preserves ' . $stored);
            $check(RolePresets::storedName('My renamed role', $stored) === 'My renamed role', $locale . ': deliberate rename preserved');
        }
        $check(RolePresets::displayName('Maestro personalizzato') === 'Maestro personalizzato', $locale . ': custom names are never translated');
        $check(RolePresets::description('Maestro personalizzato') === '', $locale . ': custom roles receive no misleading preset description');
    }
    echo "SUCCESS {$pass} preset checks\n";
} finally {
    I18n::setLocale($savedLocale);
}
