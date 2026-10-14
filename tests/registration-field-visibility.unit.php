<?php
declare(strict_types=1);

/**
 * DB-backed unit contract for the sesso / codice fiscale visibility switches
 * (Settings -> Registration): defaults keep both fields shown, each switch
 * hides exactly its own field, hiddenFields() lists what is off, and fields
 * that are not hideable are always reported visible.
 *
 * Run:  php tests/registration-field-visibility.unit.php   (exit 0 iff all pass)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
// ConfigStore opens its own connection from config/settings.php, which reads
// the .env values: load them as the web front controller does.
if (class_exists(\Dotenv\Dotenv::class) && is_file($root . '/.env')) {
    \Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

use App\Support\ConfigStore;
use App\Models\SettingsRepository;
use App\Support\RegistrationFields;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK  {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
};

$db = ConfigStore::sharedConnection();
if (!$db instanceof mysqli) {
    fwrite(STDERR, "No database connection (configure .env)\n");
    exit(1);
}
$repo = new SettingsRepository($db);
$keys = ['show_sesso', 'show_cod_fiscale'];

// Snapshot the stored rows (presence included) so the run leaves no trace.
$original = [];
$res = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE category='registration' AND setting_key IN ('show_sesso','show_cod_fiscale')");
while ($res instanceof mysqli_result && ($row = $res->fetch_assoc())) {
    $original[(string) $row['setting_key']] = (string) $row['setting_value'];
}

/** Store through the production writer, then read back through ConfigStore's DB mapping. */
$store = static function (string $key, ?string $value) use ($repo, $db): void {
    if ($value === null) {
        $stmt = $db->prepare("DELETE FROM system_settings WHERE category='registration' AND setting_key = ?");
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $stmt->close();
    } else {
        $repo->set('registration', $key, $value);
    }
    ConfigStore::clearCache();
};

try {
    $check(RegistrationFields::BUILTIN_VISIBILITY === ['sesso' => 'show_sesso', 'cod_fiscale' => 'show_cod_fiscale'],
        'the two hideable fields map to show_sesso / show_cod_fiscale');

    // No stored rows: code-side defaults keep both fields shown.
    $store('show_sesso', null);
    $store('show_cod_fiscale', null);
    $check(RegistrationFields::isVisible('sesso') && RegistrationFields::isVisible('cod_fiscale'), 'both shown by default (no stored rows)');
    $check(RegistrationFields::hiddenFields() === [], 'hiddenFields() empty by default');

    // A stored '0' must reach the reader: a key missing from ConfigStore's
    // registration mapping would be silently inert.
    $store('show_sesso', '0');
    $check(!RegistrationFields::isVisible('sesso'), "stored show_sesso='0' hides sesso");
    $check(RegistrationFields::isVisible('cod_fiscale'), 'cod_fiscale unaffected by show_sesso');
    $check(RegistrationFields::hiddenFields() === ['sesso'], 'hiddenFields() lists only sesso');

    $store('show_cod_fiscale', '0');
    $check(!RegistrationFields::isVisible('cod_fiscale'), "stored show_cod_fiscale='0' hides cod_fiscale");
    $check(RegistrationFields::hiddenFields() === ['sesso', 'cod_fiscale'], 'hiddenFields() lists both when both off');

    $store('show_sesso', '1');
    $store('show_cod_fiscale', '1');
    $check(RegistrationFields::hiddenFields() === [], "stored '1' shows both again");

    foreach (['nome', 'cognome', 'telefono', 'indirizzo', 'data_nascita', 'unknown'] as $field) {
        $check(RegistrationFields::isVisible($field), "{$field} is not hideable and always visible");
    }
    $check(!array_key_exists('sesso', RegistrationFields::BUILTIN_TOGGLES)
        && !array_key_exists('cod_fiscale', RegistrationFields::BUILTIN_TOGGLES),
        'sesso / cod_fiscale never become required toggles');
} finally {
    foreach ($keys as $key) {
        $store($key, $original[$key] ?? null);
    }
}

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS" : "{$fail} FAILED, {$pass} passed") . "\n";
exit($fail === 0 ? 0 : 1);
