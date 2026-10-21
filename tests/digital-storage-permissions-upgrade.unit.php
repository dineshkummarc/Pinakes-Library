<?php
declare(strict_types=1);
/**
 * storage/digital keeps its group write bits across an upgrade (#480).
 *
 * Up to 0.8.6 the updater's fixPermissions() reset every folder under storage/ to
 * 0755 at the end of an upgrade. storage/digital is created 0775 because the
 * maintenance cron can run as another user than the web server, and nothing put
 * the bits back once the old files had been moved, so an install upgraded from
 * 0.8.6 kept a storage/digital the cron could not write. The real fixPermissions()
 * runs here on a throwaway install tree; DigitalStorage::ensureDir() is the repair
 * both the updater and the first request after an upgrade use.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\DigitalStorage;
use App\Support\Updater;

$n = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$n): void {
    if (!$ok) {
        throw new RuntimeException("FAIL: {$label}" . ($detail !== '' ? " — {$detail}" : ''));
    }
    echo 'PASS ' . (++$n) . ' ' . $label . "\n";
};
$mode = static function (string $path): string {
    clearstatcache(true, $path);
    return sprintf('%04o', fileperms($path) & 0777);
};
$rmTree = static function (string $dir) use (&$rmTree): void {
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
        $path = $dir . '/' . $entry;
        is_dir($path) && !is_link($path) ? $rmTree($path) : @unlink($path);
    }
    @rmdir($dir);
};

$root = sys_get_temp_dir() . '/pinakes-perm-' . bin2hex(random_bytes(4));
$previousUmask = umask(0022);
try {
    foreach (['storage/digital', 'storage/uploads', 'storage/logs', 'public/uploads'] as $dir) {
        mkdir("{$root}/{$dir}", 0775, true);
        chmod("{$root}/{$dir}", 0775);
    }
    file_put_contents("{$root}/storage/digital/20261021_book.pdf", '%PDF-1.4');
    chmod("{$root}/storage/digital/20261021_book.pdf", 0664);

    $updater = (new ReflectionClass(Updater::class))->newInstanceWithoutConstructor();
    $property = new ReflectionProperty(Updater::class, 'rootPath');
    $property->setValue($updater, $root);
    (new ReflectionMethod(Updater::class, 'fixPermissions'))->invoke($updater);

    $check($mode("{$root}/storage/digital") === '0775', 'fixPermissions() leaves storage/digital with its group write bits', $mode("{$root}/storage/digital"));
    $check($mode("{$root}/storage/digital/20261021_book.pdf") === '0644', 'the files inside still get 0644 from fixPermissions()', $mode("{$root}/storage/digital/20261021_book.pdf"));
    $check($mode("{$root}/storage/uploads") === '0755', 'the other storage folders keep what fixPermissions() always gave them', $mode("{$root}/storage/uploads"));
    $check(is_file("{$root}/storage/digital/.htaccess") && str_contains((string) file_get_contents("{$root}/storage/digital/.htaccess"), 'Require all denied'),
        'the repair keeps storage/digital shut to direct requests');

    chmod("{$root}/storage/digital", 0755);
    DigitalStorage::ensureDir("{$root}/storage/digital");
    $check($mode("{$root}/storage/digital") === '0775', 'an existing 0755 storage/digital (an install upgraded from 0.8.6) gets 0775 back', $mode("{$root}/storage/digital"));

    chmod("{$root}/storage/digital", 0750);
    DigitalStorage::ensureDir("{$root}/storage/digital");
    $check($mode("{$root}/storage/digital") === '0770', 'a stricter 0750 chosen by the administrator gets group write, never "other" bits', $mode("{$root}/storage/digital"));

    $rmTree("{$root}/storage/digital");
    $updaterNoDigital = (new ReflectionClass(Updater::class))->newInstanceWithoutConstructor();
    $property->setValue($updaterNoDigital, $root);
    (new ReflectionMethod(Updater::class, 'fixPermissions'))->invoke($updaterNoDigital);
    $check(!is_dir("{$root}/storage/digital"), 'fixPermissions() does not create storage/digital where an install has none');
} finally {
    umask($previousUmask);
    if (is_dir($root)) {
        $rmTree($root);
    }
}

echo "\nALL {$n} PASS\n";
