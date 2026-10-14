<?php
declare(strict_types=1);

/**
 * Mobile API 1.7.0 — ArchiveValidator runs the Archives plugin's OWN form
 * helpers (no copied rules), so the staff API answers 422 with the same
 * field and message the admin form would show.
 *
 *   A. The private helpers it calls still exist with the expected shape
 *      (a refactor of the Archives plugin fails here, not in production).
 *   B. Unit rules through the validator: reference code, level, title,
 *      date order, length caps; defaults the core applies (institution,
 *      language, material).
 *   C. Authority rules: type, authorised form, dates length.
 *   D. The JSON keys of the API are exactly the form keys the core reads.
 *
 * No database: the checks exercised never reach a query.
 *
 * Run: php tests/mobile-api-archives-validator.unit.php
 */

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
require_once $root . '/storage/plugins/archives/ArchivesPlugin.php';
require_once $root . '/storage/plugins/mobile-api/src/Support/ArchiveValidator.php';
require_once $root . '/storage/plugins/mobile-api/src/Support/ResponseEnvelope.php';
require_once $root . '/storage/plugins/mobile-api/src/Controllers/StaffArchivesController.php';

use App\Plugins\Archives\ArchivesPlugin;
use App\Plugins\MobileApi\Controllers\StaffArchivesController;
use App\Plugins\MobileApi\Support\ArchiveValidator;

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    echo ($condition ? '  OK  ' : '  FAIL ') . $label . "\n";
    $condition ? $passed++ : $failed++;
};

// ── A. Pinned signatures ─────────────────────────────────────────────────────
echo "A. The Archives helpers the validator calls\n";
$sig = static function (string $name) {
    return new ReflectionMethod(ArchivesPlugin::class, $name);
};
$m = $sig('validateArchivalUnit');
$check($m->getNumberOfParameters() === 2 && $m->getParameters()[0]->isPassedByReference() && $m->getParameters()[1]->allowsNull(), 'validateArchivalUnit(array &$values, ?int $excludeId)');
$check((string) $m->getReturnType() === 'array', 'validateArchivalUnit returns field => message');
$check($sig('extractArchivalUnitPayload')->getNumberOfParameters() === 1, 'extractArchivalUnitPayload(ServerRequestInterface)');
$check($sig('parentWouldCreateCycle')->getNumberOfParameters() === 2, 'parentWouldCreateCycle(int, int)');
$check($sig('extractAuthorityPayload')->getNumberOfParameters() === 1, 'extractAuthorityPayload(ServerRequestInterface)');
$check($sig('validateAuthority')->getNumberOfParameters() === 1, 'validateAuthority(array)');
$src = (string) file_get_contents($root . '/storage/plugins/archives/ArchivesPlugin.php');
$check(str_contains($src, '$errors = $this->validateArchivalUnit($values);') && str_contains($src, '$errors = $this->validateArchivalUnit($values, $id);'), 'storeAction/updateAction still validate with the same helper');
$check(str_contains($src, '$errors = $this->validateAuthority($values);'), 'the authority actions still validate with validateAuthority()');

$plugin = new ArchivesPlugin(mysqli_init(), new \App\Support\HookManager(mysqli_init()));
$v = new ArchiveValidator($plugin);

// ── B. Units ─────────────────────────────────────────────────────────────────
echo "\nB. Archival-unit rules\n";
$base = ['reference_code' => 'REF-1', 'level' => 'fonds', 'constructed_title' => 'Fondo'];
$check($v->unit($base, null) === [], 'a minimal valid unit passes');
$cases = [
    'reference_code' => ['reference_code' => ''],
    'level' => ['level' => 'shelf'],
    'constructed_title' => ['constructed_title' => ''],
    'date_end' => ['date_start' => '1900', 'date_end' => '1800'],
];
foreach ($cases as $field => $override) {
    $errors = $v->unit($override + $base, null);
    $check(array_key_first($errors) === $field && is_string($errors[$field]) && $errors[$field] !== '', "{$field} → " . ($errors[$field] ?? 'none'));
}
$check(isset($v->unit(['reference_code' => str_repeat('x', 65)] + $base, null)['reference_code']), 'reference code over 64 characters refused');
$check(isset($v->unit(['constructed_title' => str_repeat('x', 501)] + $base, null)['constructed_title']), 'title over 500 characters refused');
$values = $v->unitValues($base);
$check($values['institution_code'] === 'PINAKES' && $values['language_codes'] === 'ita' && $values['specific_material'] === 'text', 'core defaults applied (PINAKES, ita, text)');
$check($v->unitValues($base + ['published' => '0'])['published'] === 0 && $v->unitValues($base)['published'] === null, 'published: "0" → 0, absent → null (create publishes)');

// ── C. Authorities ───────────────────────────────────────────────────────────
echo "\nC. Authority rules\n";
$check($v->authority(['type' => 'person', 'authorised_form' => 'Rossi, Maria']) === [], 'a valid authority passes');
$check(array_keys($v->authority(['type' => 'robot', 'authorised_form' => 'X'])) === ['type'], 'type outside person/corporate/family refused');
$check(array_keys($v->authority(['type' => 'family', 'authorised_form' => ''])) === ['authorised_form'], 'authorised form required');
$check(array_keys($v->authority(['type' => 'family', 'authorised_form' => 'X', 'dates_of_existence' => str_repeat('1', 256)])) === ['dates_of_existence'], 'dates over 255 characters refused');

// ── D. Keys ──────────────────────────────────────────────────────────────────
echo "\nD. API keys = form keys\n";
$extract = $sig('extractArchivalUnitPayload');
$payload = (array) $extract->invoke($plugin, (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/')->withParsedBody([]));
$check(array_keys($payload) === StaffArchivesController::UNIT_KEYS || array_diff(array_keys($payload), StaffArchivesController::UNIT_KEYS) === [] && array_diff(StaffArchivesController::UNIT_KEYS, array_keys($payload)) === [], 'UNIT_KEYS = extractArchivalUnitPayload() keys (' . count($payload) . ')');
$authority = (array) $sig('extractAuthorityPayload')->invoke($plugin, (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/')->withParsedBody([]));
$check(array_keys($authority) === StaffArchivesController::AUTHORITY_KEYS, 'AUTHORITY_KEYS = extractAuthorityPayload() keys');

echo "\n" . ($failed === 0 ? "ALL {$passed} PASS\n" : "{$passed} PASS, {$failed} FAIL\n");
exit($failed === 0 ? 0 : 1);
