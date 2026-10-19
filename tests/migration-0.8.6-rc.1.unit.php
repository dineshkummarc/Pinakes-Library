<?php
declare(strict_types=1);
/** Run the real role migration against a scoped pre-upgrade user table, twice. */
require dirname(__DIR__) . '/vendor/autoload.php';
$env = Dotenv\Dotenv::parse(file_get_contents(dirname(__DIR__) . '/.env'));
$db = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'], $env['DB_PASS'] ?? '', $env['DB_NAME'], (int) ($env['DB_PORT'] ?? 3306), getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null));
$tag = 'zz_roles086_' . bin2hex(random_bytes(4));
$users = $tag . '_users'; $roles = $tag . '_roles';
$source = file_get_contents(dirname(__DIR__) . '/installer/database/migrations/migrate_0.8.6-rc.1.sql');
$source = preg_replace('/^--.*$/m', '', $source);
$source = str_replace(['fk_utenti_ruolo', 'idx_utenti_ruolo'], [$tag . '_fk', $tag . '_idx'], $source);
$source = preg_replace('/\butenti\b/', $users, $source);
$source = preg_replace('/\bruoli\b/', $roles, $source);
$run = static function () use ($db, $source): void {
    foreach (explode(';', $source) as $sql) { if (trim($sql) !== '') { $db->query($sql); } }
};
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
    $checks++; echo "OK {$label}\n";
};
try {
    $db->query("CREATE TABLE {$users} (id INT NOT NULL PRIMARY KEY, tipo_utente ENUM('standard','premium','staff','admin') DEFAULT 'standard') ENGINE=InnoDB");
    $db->query("INSERT INTO {$users} VALUES (1,'standard'),(2,'premium'),(3,'staff'),(4,'admin')");
    $run(); $run();
    $check($db->query("SELECT COUNT(*) FROM {$roles}")->fetch_row()[0] === '3', 'three example roles seeded once');
    $check($db->query("SELECT GROUP_CONCAT(tipo_utente ORDER BY id) FROM {$users}")->fetch_row()[0] === 'standard,premium,staff,admin', 'existing account types preserved');
    $check((int) $db->query("SELECT COUNT(*) FROM {$users} WHERE ruolo_id IS NULL AND accesso_digitale=0")->fetch_row()[0] === 4, 'upgrade grants no new privileges');
    $id = (int) $db->query("SELECT id FROM {$roles} WHERE nome='Catalogatore'")->fetch_row()[0];
    $db->query("UPDATE {$roles} SET permessi='[]' WHERE id={$id}"); $run();
    $check($db->query("SELECT permessi FROM {$roles} WHERE id={$id}")->fetch_row()[0] === '[]', 'rerun preserves configured role permissions');
    $db->query("UPDATE {$users} SET ruolo_id={$id},accesso_digitale=1 WHERE id=1"); $run();
    $check((int) $db->query("SELECT ruolo_id FROM {$users} WHERE id=1")->fetch_row()[0] === $id, 'rerun preserves assignments');
    try { $db->query("DELETE FROM {$roles} WHERE id={$id}"); $check(false, 'in-use deletion denied'); }
    catch (mysqli_sql_exception $e) { $check($e->getCode() === 1451, 'foreign key prevents deletion of an assigned role'); }
    $db->query("UPDATE {$users} SET ruolo_id=NULL WHERE id=1");
    $db->query("DELETE FROM {$roles} WHERE id={$id}");
    $check($db->affected_rows === 1, 'unused roles may be deleted');
    $schema = file_get_contents(dirname(__DIR__) . '/installer/database/schema.sql');
    foreach (['CREATE TABLE IF NOT EXISTS `ruoli`', '`ruolo_id` int DEFAULT NULL', '`accesso_digitale` tinyint(1) NOT NULL DEFAULT 0', 'CONSTRAINT `fk_utenti_ruolo`'] as $definition) {
        $check(str_contains($schema, $definition), 'fresh install includes ' . $definition);
    }
    $version = json_decode(file_get_contents(dirname(__DIR__) . '/version.json'), true)['version'];
    $check(version_compare('0.8.6-rc.1', $version, '<='), 'release includes the migration');
    echo "SUCCESS {$checks} migration checks\n";
} finally {
    $db->query("DROP TABLE IF EXISTS {$users}"); $db->query("DROP TABLE IF EXISTS {$roles}");
}
