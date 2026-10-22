<?php
declare(strict_types=1);
/** Run the real 0.8.9 migration against a scoped pre-upgrade loans table, twice. */
require dirname(__DIR__) . '/vendor/autoload.php';
$env = Dotenv\Dotenv::parse(file_get_contents(dirname(__DIR__) . '/.env'));
$db = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'], $env['DB_PASS'] ?? '', $env['DB_NAME'], (int) ($env['DB_PORT'] ?? 3306), getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null));
$loans = 'zz_mig089_' . bin2hex(random_bytes(4));
$source = file_get_contents(dirname(__DIR__) . '/installer/database/migrations/migrate_0.8.9.sql');
$source = preg_replace('/^--.*$/m', '', $source);
$source = preg_replace('/\bprestiti\b/', $loans, $source);
$run = static function () use ($db, $source): void {
    foreach (explode(';', $source) as $sql) { if (trim($sql) !== '') { $db->query($sql); } }
};
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    $checks++; echo "OK {$label}\n";
};
$column = static function () use ($db, $loans): ?array {
    $r = $db->query("SELECT DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$loans}' AND COLUMN_NAME = 'pickup_reminder_sent_for'");
    return $r->fetch_assoc();
};
try {
    // The 0.8.8 shape of the columns the reminder works with.
    $db->query("CREATE TABLE {$loans} (id INT NOT NULL PRIMARY KEY, stato VARCHAR(20) NOT NULL, pickup_deadline DATE DEFAULT NULL, pickup_notification_sent TINYINT(1) DEFAULT '0', last_recall_at DATETIME DEFAULT NULL) ENGINE=InnoDB");
    $db->query("INSERT INTO {$loans} (id, stato, pickup_deadline, pickup_notification_sent) VALUES (1,'da_ritirare','2026-11-02',1),(2,'in_corso',NULL,0)");
    $check($column() === null, 'the pre-upgrade table has no reminder column');
    $run();
    $col = $column();
    $check(is_array($col) && $col['DATA_TYPE'] === 'date' && $col['IS_NULLABLE'] === 'YES', 'the migration adds a nullable DATE column');
    // MariaDB reports a NULL default as the string 'NULL', MySQL as SQL NULL.
    $check($col['COLUMN_DEFAULT'] === null || $col['COLUMN_DEFAULT'] === 'NULL', 'it defaults to NULL: no existing loan counts as reminded');
    $check($db->query("SELECT GROUP_CONCAT(CONCAT(id,':',stato,':',IFNULL(pickup_deadline,'-'),':',IFNULL(pickup_reminder_sent_for,'-')) ORDER BY id) FROM {$loans}")->fetch_row()[0] === '1:da_ritirare:2026-11-02:-,2:in_corso:-:-', 'existing loans are preserved');
    $db->query("UPDATE {$loans} SET pickup_reminder_sent_for = pickup_deadline WHERE id = 1");
    $run();
    $check($db->query("SELECT pickup_reminder_sent_for FROM {$loans} WHERE id = 1")->fetch_row()[0] === '2026-11-02', 'a second run is a no-op that keeps the stored value');
    $check(str_contains(file_get_contents(dirname(__DIR__) . '/installer/database/schema.sql'), '`pickup_reminder_sent_for` date DEFAULT NULL'), 'fresh install includes the column');
    $version = json_decode(file_get_contents(dirname(__DIR__) . '/version.json'), true)['version'];
    $check(version_compare('0.8.9', $version, '<='), 'release includes the migration');
    echo "SUCCESS {$checks} migration checks\n";
} finally {
    $db->query("DROP TABLE IF EXISTS {$loans}");
}
