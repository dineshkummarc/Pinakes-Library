<?php
declare(strict_types=1);

/**
 * Plugin schema self-heal for COLUMNS and FOREIGN KEYS (0.7.67).
 *
 * Sibling to plugin-schema-selfheal.unit.php, which covers missing TABLES.
 *
 * THE BUG this pins down: an admin-UI upgrade runs
 * `public/index.php → loadActivePlugins()`, loading the ACTIVE plugin's OLD
 * class into memory BEFORE the Updater overwrites the files. PHP will not
 * reload a class already in memory, so a new FK/column added to
 * onActivate/ensureSchema in that release never runs at upgrade time — yet
 * `plugins.version` is bumped to the new value. The same-version self-heal on
 * the next boot is the only path that can apply it, and before 0.7.67 it only
 * probed expectedTables(): a new FK on an existing table (ncip_transactions'
 * FKs, bibliodoc 2026-08-24) or a new column (digital-library's file_url/
 * audio_url on `libri`) was healed by neither path and stayed missing forever.
 *
 * These reproduce the "version already == disk, hooks present, FK/column
 * missing" state against the REAL PluginManager and REAL plugins, then assert
 * the schema self-heals. On the pre-fix code these FAIL by design.
 *
 * Each scenario SKIPs cleanly if its plugin is not present. The FK drop loses
 * no row data; the column scenario snapshots the affected values and restores
 * them.
 */

require __DIR__ . '/../vendor/autoload.php';

/* ----------------------------- DB connect ----------------------------- */
function sfc_env(string $path): array
{
    $env = [];
    foreach (@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $env[$k] = $v;
    }
    return $env;
}

$env    = sfc_env(__DIR__ . '/../.env');
$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$user   = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$pass   = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$name   = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');

mysqli_report(MYSQLI_REPORT_OFF);
try {
    $db = (is_string($socket) && $socket !== '' && file_exists($socket))
        ? @new mysqli(null, $user, $pass, $name, 0, $socket)
        : @new mysqli($env['DB_HOST'] ?? '127.0.0.1', $user, $pass, $name, (int) ($env['DB_PORT'] ?? 3306));
} catch (\Throwable $e) {
    echo "SKIP: database not reachable (" . $e->getMessage() . ")\n";
    exit(0);
}
if (!isset($db) || $db->connect_errno !== 0) {
    $error = isset($db) ? $db->connect_error : 'connection failed';
    echo "SKIP: database not reachable ({$error})\n";
    exit(0);
}
$db->set_charset('utf8mb4');

/* ----------------------------- harness ----------------------------- */
$TESTNO = 0;
function pass(string $desc): void
{
    global $TESTNO;
    $TESTNO++;
    printf("[%02d] PASS: %s\n", $TESTNO, $desc);
}
function check(bool $cond, string $desc): void
{
    if (!$cond) {
        throw new \RuntimeException("assertion failed: {$desc}");
    }
    pass($desc);
}

$hm = new \App\Support\HookManager($db);
$runSync = function () use ($db, $hm): void {
    (new \App\Support\PluginManager($db, $hm))->autoRegisterBundledPlugins();
};
$fkExists = function (string $table, string $column, string $refTable) use ($db): bool {
    return (bool) $db->query(
        "SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA=DATABASE()
           AND TABLE_NAME='" . $db->real_escape_string($table) . "'
           AND COLUMN_NAME='" . $db->real_escape_string($column) . "'
           AND REFERENCED_TABLE_NAME='" . $db->real_escape_string($refTable) . "'"
    )->num_rows;
};
$columnExists = function (string $t, string $c) use ($db): bool {
    return (bool) $db->query(
        "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $db->real_escape_string($t) . "' AND COLUMN_NAME='" . $db->real_escape_string($c) . "'"
    )->num_rows;
};
$hookCount = function (int $pluginId) use ($db): int {
    return (int) $db->query("SELECT COUNT(*) FROM plugin_hooks WHERE plugin_id={$pluginId}")->fetch_row()[0];
};
// Drop prenotazione_id with whatever foreign key holds it, under its actual name: an
// install whose FK is not called ncip_transactions_ibfk_3 would keep both otherwise.
$dropPrenotazione = static function (mysqli $db): void {
    $res = $db->query("SELECT DISTINCT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ncip_transactions'
          AND COLUMN_NAME='prenotazione_id' AND REFERENCED_TABLE_NAME IS NOT NULL");
    foreach ($res ? $res->fetch_all() : [] as [$name]) {
        $db->query('ALTER TABLE ncip_transactions DROP FOREIGN KEY `' . str_replace('`', '``', (string) $name) . '`');
    }
    if ($db->query("SHOW COLUMNS FROM ncip_transactions LIKE 'prenotazione_id'")->num_rows > 0) {
        $db->query('ALTER TABLE ncip_transactions DROP COLUMN prenotazione_id');
    }
};
$diskVersion = function (string $plugin): string {
    $meta = json_decode((string) @file_get_contents(__DIR__ . "/../storage/plugins/{$plugin}/plugin.json"), true);
    return (string) ($meta['version'] ?? '1.0.0');
};

/* Track what we changed so cleanup restores the plugins as we found them. */
$restores = [];
$cleanup = function () use ($db, &$restores): void {
    foreach (array_reverse($restores) as $r) {
        try { $r($db); } catch (\Throwable $ignored) {}
    }
};
set_exception_handler(function (\Throwable $e) use ($cleanup): void {
    try { $cleanup(); } catch (\Throwable $ignored) {}
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    exit(1);
});

/* ================= Scenario A — ncip-server foreign keys ================= */
$ncip = $db->query("SELECT id, is_active, version FROM plugins WHERE name='ncip-server'")->fetch_assoc();
if (!$ncip) {
    // ncip-server is a bundled plugin: a missing row means this environment
    // never registered it, which would let the gate pass WITHOUT testing FK
    // self-heal at all. Fail loudly instead of silently skipping.
    if (is_dir(__DIR__ . '/../storage/plugins/ncip-server')) {
        throw new \RuntimeException('ncip-server is bundled on disk but not registered in `plugins` — cannot verify FK self-heal');
    }
} else {
    $ncipId       = (int) $ncip['id'];
    $ncipActive0  = (int) $ncip['is_active'];
    $ncipVersion0 = (string) $ncip['version'];
    $ncipDisk     = $diskVersion('ncip-server');

    // Restore exactly as found: re-add the FKs we drop below (so a mid-scenario
    // failure never leaves ncip_transactions without its referential
    // integrity), original active flag + version, and — if it was inactive —
    // the hooks our activation registered.
    $restores[] = function (mysqli $db) use ($ncipId, $ncipActive0, $ncipVersion0): void {
        if (!$db->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ncip_transactions' AND COLUMN_NAME='prenotazione_id'")->num_rows) {
            @$db->query('ALTER TABLE ncip_transactions ADD COLUMN prenotazione_id INT NULL AFTER prestito_id');
        }
        if (!$db->query("SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ncip_transactions' AND COLUMN_NAME='partner_id' AND REFERENCED_TABLE_NAME='ncip_partners'")->num_rows) {
            @$db->query("ALTER TABLE ncip_transactions ADD CONSTRAINT ncip_transactions_ibfk_1 FOREIGN KEY (partner_id) REFERENCES ncip_partners (id) ON DELETE SET NULL");
        }
        if (!$db->query("SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ncip_transactions' AND COLUMN_NAME='prestito_id' AND REFERENCED_TABLE_NAME='prestiti'")->num_rows) {
            @$db->query("ALTER TABLE ncip_transactions ADD CONSTRAINT ncip_transactions_ibfk_2 FOREIGN KEY (prestito_id) REFERENCES prestiti (id) ON DELETE SET NULL");
        }
        if (!$db->query("SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ncip_transactions' AND COLUMN_NAME='prenotazione_id' AND REFERENCED_TABLE_NAME='prenotazioni'")->num_rows) {
            @$db->query("ALTER TABLE ncip_transactions ADD CONSTRAINT ncip_transactions_ibfk_3 FOREIGN KEY (prenotazione_id) REFERENCES prenotazioni (id) ON DELETE SET NULL");
        }
        if ($ncipActive0 === 0) {
            $db->query("DELETE FROM plugin_hooks WHERE plugin_id={$ncipId}");
        }
        $v = $db->real_escape_string($ncipVersion0);
        $db->query("UPDATE plugins SET is_active={$ncipActive0}, version='{$v}' WHERE id={$ncipId}");
    };

    // Baseline: activate + let the real sync build the ncip schema (tables + FKs).
    $db->query("UPDATE plugins SET is_active=1 WHERE id={$ncipId}");
    $runSync();
    check($fkExists('ncip_transactions', 'partner_id', 'ncip_partners'), 'A01 baseline: ncip FK partner_id present');
    check($fkExists('ncip_transactions', 'prestito_id', 'prestiti'), 'A02 baseline: ncip FK prestito_id present');
    check($columnExists('ncip_transactions', 'prenotazione_id'), 'A03 baseline: ncip reservation audit column present');
    check($fkExists('ncip_transactions', 'prenotazione_id', 'prenotazioni'), 'A04 baseline: ncip FK prenotazione_id present');

    // Break: drop all FKs, pin version == disk (the stale-class upgrade state),
    // keep active + hooks. Pre-fix, the same-version branch skips ensureSchema.
    @$db->query("ALTER TABLE ncip_transactions DROP FOREIGN KEY ncip_transactions_ibfk_1");
    @$db->query("ALTER TABLE ncip_transactions DROP FOREIGN KEY ncip_transactions_ibfk_2");
    @$db->query("ALTER TABLE ncip_transactions DROP FOREIGN KEY ncip_transactions_ibfk_3");
    $v = $db->real_escape_string($ncipDisk);
    $db->query("UPDATE plugins SET version='{$v}', is_active=1 WHERE id={$ncipId}");
    check(!$fkExists('ncip_transactions', 'partner_id', 'ncip_partners'), 'A05 setup: FK partner_id dropped, version already == disk');
    check(!$fkExists('ncip_transactions', 'prestito_id', 'prestiti'), 'A06 setup: FK prestito_id dropped');
    check(!$fkExists('ncip_transactions', 'prenotazione_id', 'prenotazioni'), 'A07 setup: FK prenotazione_id dropped');
    check($hookCount($ncipId) > 0, 'A08 setup: ncip active with hooks present (stale-class scenario)');

    $runSync();
    check($fkExists('ncip_transactions', 'partner_id', 'ncip_partners'), 'A09 same-version sync SELF-HEALS ncip FK partner_id (the bibliodoc bug)');
    check($fkExists('ncip_transactions', 'prestito_id', 'prestiti'), 'A10 same-version sync SELF-HEALS ncip FK prestito_id');
    check($fkExists('ncip_transactions', 'prenotazione_id', 'prenotazioni'), 'A11 same-version sync SELF-HEALS ncip FK prenotazione_id');

    // Idempotent: a second sync on a healthy schema is a no-op.
    $runSync();
    check(
        $fkExists('ncip_transactions', 'partner_id', 'ncip_partners')
            && $fkExists('ncip_transactions', 'prestito_id', 'prestiti')
            && $fkExists('ncip_transactions', 'prenotazione_id', 'prenotazioni'),
        'A12 idempotent: all FKs intact after a second sync'
    );
}

/* ================= Scenario B — digital-library columns ================= */
$dl = $db->query("SELECT id, is_active, version FROM plugins WHERE name='digital-library'")->fetch_assoc();
if (!$dl) {
    // Same contract as ncip above: a bundled-but-unregistered plugin must fail
    // the gate rather than let it pass without exercising the column self-heal.
    if (is_dir(__DIR__ . '/../storage/plugins/digital-library')) {
        throw new \RuntimeException('digital-library is bundled on disk but not registered in `plugins` — cannot verify column self-heal');
    }
} else {
    $dlId       = (int) $dl['id'];
    $dlActive0  = (int) $dl['is_active'];
    $dlVersion0 = (string) $dl['version'];
    $dlDisk     = $diskVersion('digital-library');

    // Snapshot audio_url values so dropping the column loses no real data.
    $db->query("DROP TABLE IF EXISTS zz_sfc_dl_bak");
    $db->query("CREATE TABLE zz_sfc_dl_bak AS SELECT id, audio_url FROM libri WHERE audio_url IS NOT NULL AND audio_url <> ''");

    $restores[] = function (mysqli $db) use ($dlId, $dlActive0, $dlVersion0): void {
        // Ensure the column exists again before restoring its values.
        if (!$db->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='libri' AND COLUMN_NAME='audio_url'")->num_rows) {
            @$db->query("ALTER TABLE libri ADD COLUMN audio_url VARCHAR(255) DEFAULT NULL COMMENT 'Audiobook file URL' AFTER file_url");
        }
        if ($db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='zz_sfc_dl_bak'")->num_rows) {
            @$db->query("UPDATE libri l JOIN zz_sfc_dl_bak b ON l.id=b.id SET l.audio_url=b.audio_url");
            $db->query("DROP TABLE IF EXISTS zz_sfc_dl_bak");
        }
        $v = $db->real_escape_string($dlVersion0);
        $db->query("UPDATE plugins SET is_active={$dlActive0}, version='{$v}' WHERE id={$dlId}");
    };

    // Baseline: active + real sync ensures the columns exist.
    $db->query("UPDATE plugins SET is_active=1 WHERE id={$dlId}");
    $runSync();
    check($columnExists('libri', 'file_url'), 'B01 baseline: libri.file_url present');
    check($columnExists('libri', 'audio_url'), 'B02 baseline: libri.audio_url present');

    // Break: drop audio_url, pin version == disk, keep active. Pre-fix the
    // same-version branch (expectedTables only) never re-adds it.
    @$db->query("ALTER TABLE libri DROP COLUMN audio_url");
    $v = $db->real_escape_string($dlDisk);
    $db->query("UPDATE plugins SET version='{$v}', is_active=1 WHERE id={$dlId}");
    check(!$columnExists('libri', 'audio_url'), 'B03 setup: libri.audio_url dropped, version already == disk');
    check($hookCount($dlId) > 0, 'B04 setup: digital-library active with hooks present');

    $runSync();
    check($columnExists('libri', 'audio_url'), 'B05 same-version sync SELF-HEALS libri.audio_url');

    // Idempotent.
    $runSync();
    check($columnExists('libri', 'audio_url'), 'B06 idempotent: column intact after a second sync');
}

/* ===== Scenario C — an INACTIVE plugin's existing tables follow its version ===== */
// An install upgraded from 0.7.x keeps ncip-server registered but switched off, with
// the ncip_transactions of that time: no prenotazione_id. The version bump used to sync
// the schema of active plugins only, so the column stayed missing until someone
// switched NCIP on. The bump now brings the tables an inactive plugin already has to
// its version, without registering any hook or switching it on.
$ncipC = $db->query("SELECT id, is_active, version FROM plugins WHERE name='ncip-server'")->fetch_assoc();
if ($ncipC) {
    $cId = (int) $ncipC['id'];
    $cActive0 = (int) $ncipC['is_active'];
    $cHooks0 = $hookCount($cId);
    $cVersion0 = (string) $ncipC['version'];
    // A failure halfway must not leave NCIP switched off without its hooks: put the
    // activation state, the version and (for an active plugin) the hooks back.
    $restores[] = function (mysqli $db) use ($cId, $cActive0, $cVersion0, $runSync): void {
        $v = $db->real_escape_string($cVersion0);
        $db->query("UPDATE plugins SET is_active={$cActive0}, version='{$v}' WHERE id={$cId}");
        if ($cActive0 === 1) {
            $runSync();
        }
    };
    $db->query("UPDATE plugins SET is_active=0 WHERE id={$cId}");
    $db->query("DELETE FROM plugin_hooks WHERE plugin_id={$cId}");
    $dropPrenotazione($db);
    $db->query("UPDATE plugins SET version='0.0.1' WHERE id={$cId}");
    check(!$columnExists('ncip_transactions', 'prenotazione_id'), 'C01 setup: inactive ncip-server at an older version, ncip_transactions without prenotazione_id');

    $runSync();
    check($columnExists('ncip_transactions', 'prenotazione_id'), 'C02 the version bump adds prenotazione_id to the inactive plugin\'s table');
    check($fkExists('ncip_transactions', 'prenotazione_id', 'prenotazioni'), 'C03 ...with its foreign key');
    $after = $db->query("SELECT is_active, version FROM plugins WHERE id={$cId}")->fetch_assoc();
    check((int) $after['is_active'] === 0 && $hookCount($cId) === 0, 'C04 the plugin stays switched off, with no hook registered');
    check((string) $after['version'] === $diskVersion('ncip-server'), 'C05 its version is the one on disk');

    // The bump can be gone before this code ever runs: the previous release's Updater
    // syncs the plugins with its own PluginManager still in memory, so an install
    // upgraded from 0.7.16 arrives with NCIP already at the disk version and the
    // column still missing. The sync has to fix that too.
    $dropPrenotazione($db);
    check(!$columnExists('ncip_transactions', 'prenotazione_id'), 'C07 setup: inactive ncip-server already at the disk version, prenotazione_id missing');
    $runSync();
    check($columnExists('ncip_transactions', 'prenotazione_id'), 'C08 the sync adds prenotazione_id with no version bump');
    check($fkExists('ncip_transactions', 'prenotazione_id', 'prenotazioni'), 'C09 ...with its foreign key');
    check((int) $db->query("SELECT is_active FROM plugins WHERE id={$cId}")->fetch_row()[0] === 0 && $hookCount($cId) === 0, 'C10 the plugin is still off, with no hook');

    // Put the activation state back; an active plugin gets its hooks again from the sync.
    $db->query("UPDATE plugins SET is_active={$cActive0} WHERE id={$cId}");
    if ($cActive0 === 1) {
        $runSync();
        check($hookCount($cId) >= $cHooks0, 'C06 restored: ncip-server active again with its hooks');
    }
}

/* ===== Scenario D — an inactive plugin that never created its tables is left alone ===== */
$arch = $db->query("SELECT id, is_active, version FROM plugins WHERE name='archives'")->fetch_assoc();
$archTable = static fn (): bool => (bool) $db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='archival_units'")->num_rows;
if ($arch && (int) $arch['is_active'] === 0 && !$archTable()) {
    $dId = (int) $arch['id'];
    $dVersion0 = $db->real_escape_string((string) $arch['version']);
    $db->query("UPDATE plugins SET version='0.0.1' WHERE id={$dId}");
    $runSync();
    check(!$archTable(), 'D01 the version bump of an inactive, never-installed Archives creates no table');
    $db->query("UPDATE plugins SET version='{$dVersion0}' WHERE id={$dId}");
}

/* ===== Scenario E — the inactive sync never registers hooks or moves files ===== */
// digital-library registers its hooks in setPluginId() whenever it has none (and moves
// its files there too). The schema-only instance of a switched-off plugin must skip
// that call, or a plugin the admin turned off gets hook rows behind their back.
if (isset($dlId)) {
    $eHooks0 = $hookCount($dlId);
    $restores[] = function (mysqli $db) use ($dlId, $dlActive0, $runSync): void {
        $db->query("UPDATE plugins SET is_active={$dlActive0} WHERE id={$dlId}");
        if ($dlActive0 === 1) {
            $runSync();
        }
    };
    $db->query("UPDATE plugins SET is_active=0, version='0.0.1' WHERE id={$dlId}");
    $db->query("DELETE FROM plugin_hooks WHERE plugin_id={$dlId}");
    @$db->query("ALTER TABLE libri DROP COLUMN audio_url");
    check(!$columnExists('libri', 'audio_url') && $hookCount($dlId) === 0, 'E01 setup: inactive digital-library at an older version, no hooks, libri.audio_url missing');
    $runSync();
    check($columnExists('libri', 'audio_url'), 'E02 the version bump still syncs the inactive plugin\'s column');
    check($hookCount($dlId) === 0, 'E03 ...without registering any hook for the switched-off plugin');
    check((int) $db->query("SELECT is_active FROM plugins WHERE id={$dlId}")->fetch_row()[0] === 0, 'E04 ...and it stays switched off');
    $db->query("UPDATE plugins SET is_active={$dlActive0} WHERE id={$dlId}");
    if ($dlActive0 === 1) {
        $runSync();
        check($hookCount($dlId) >= $eHooks0, 'E05 restored: digital-library active again with its hooks');
    }
}

// Belt and suspenders: if neither scenario ran a single assertion, the gate
// would report success without verifying anything. Refuse to pass empty.
if ($TESTNO === 0) {
    throw new \RuntimeException('no self-heal scenario executed — neither ncip-server nor digital-library was verifiable');
}

$cleanup();
$db->close();
printf("\nALL %d PASS\n", $TESTNO);
