<?php
declare(strict_types=1);

/**
 * #489 — a copy received through the desiderata plugin must carry the book's
 * own "Prefisso inventario copie" (libri.numero_inventario), exactly like a
 * copy created from the core book form, the copies form or the API.
 *
 * The plugin used to hard-code 'LIB-' . $bookId in all three of its receipt
 * paths, so a library with its own numbering scheme got LIB-<id>-C1 codes it
 * cannot correct (a copy's code is written once and is editable nowhere, by
 * design). This suite receives a book through each of those paths and reads
 * back the code that was actually written:
 *
 *   A. the book form   — request closed by saving the record with copies
 *                        (prepareBook() + bookSaved() → registerCopies())
 *   B. direct receipt  — "received at the desk", no proposal (receiveDirect())
 *   C. donation offer  — a proposal marked received (manage())
 *
 * Each path runs twice: once on a book WITH a prefix (codes must be
 * "<prefix>-C<n>") and once on a book WITHOUT one (codes must stay
 * "LIB-<id>-C<n>", the historical default). The Mobile API receive endpoints
 * delegate to B and C, so they are covered by the same assertions.
 *
 * Real database, real plugin methods, no stubs except the mailer. FAILS HARD
 * when the database is unreachable: a skip would be indistinguishable from a
 * pass. Fixtures are removed by id at the end.
 *
 * Run:  php tests/desiderata-inventory-prefix-489.unit.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/storage/plugins/desiderata/wrapper.php';

use App\Models\CopyRepository;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

/** .env is parsed by hand: this file must run as a bare `php tests/...` process. */
$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) ?: [] as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}

$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '/opt/homebrew/var/mysql/mysql.sock');
$dbUser = getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? '');
$dbPass = getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? ''));
$dbName = getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? '');
$db = $socket !== '' && file_exists($socket)
    ? new mysqli(null, $dbUser, $dbPass, $dbName, 0, $socket)
    : new mysqli($env['DB_HOST'] ?? '127.0.0.1', $dbUser, $dbPass, $dbName, (int) ($env['DB_PORT'] ?? 3306));
$db->set_charset('utf8mb4');

$plugin = new DesiderataPlugin($db, new App\Support\HookManager($db));
// Receipts notify the operators by e-mail: a test must never mail anyone.
$plugin->setEmailService(new class($db) extends App\Support\EmailService {
    public function sendEmail(string $to, string $subject, string $body, string $toName = '', ?string $locale = null, array $attachments = []): bool
    {
        return true;
    }
});

// A per-run tag keeps the prefixes globally unique: numero_inventario has a
// GLOBAL unique index, so a fixed "BIB-" would collide with a previous run or
// with a real library's codes and the allocator would (correctly) skip to -C2.
$tag = 'INV489' . bin2hex(random_bytes(4));
$bookIds = [];
$offerIds = [];
$fatalError = null;

$repo = new App\Models\BookRepository($db);
$factory = new Slim\Psr7\Factory\ServerRequestFactory();
$post = static fn (string $path, array $body) => $factory->createServerRequest('POST', $path)->withParsedBody($body);

/** A wanted book (desiderata), created through the plugin's own form filter. */
$createWanted = static function (string $suffix, ?string $prefix) use ($plugin, $repo, $tag, &$bookIds): int {
    $fields = ['titolo' => $tag . ' ' . $suffix, 'copie_totali' => 1, 'numero_inventario' => $prefix];
    $fields = $plugin->prepareBook($fields, ['desiderata_form' => '1', 'is_desiderata' => '1'], null);
    $id = $repo->createBasic($fields);
    $bookIds[] = $id;
    return $id;
};

/** @return list<string> the codes of the book's copies, oldest first */
$codesOf = static function (int $bookId) use ($db): array {
    $stmt = $db->prepare('SELECT numero_inventario FROM copie WHERE libro_id = ? ORDER BY id');
    $stmt->bind_param('i', $bookId);
    $stmt->execute();
    $codes = array_map(static fn (array $row): string => (string) $row[0], $stmt->get_result()->fetch_all(MYSQLI_NUM));
    $stmt->close();
    return $codes;
};

$show = static fn (array $codes): string => '[' . implode(', ', $codes) . ']';

try {
    // Built, never skipped: the receipt paths need is_desiderata and the
    // offers table, and a missing one would make every path below a no-op.
    $plugin->ensureSchema();
    if (!App\Support\BookVisibility::hasDesiderata($db)) {
        throw new RuntimeException('libri.is_desiderata is absent after ensureSchema()');
    }

    // One prefix per book: codes are globally unique, so books sharing a
    // prefix continue one "-C<n>" family instead of each starting at -C1.
    foreach (['prefixed' => true, 'unprefixed' => false] as $case => $withPrefix) {
        $prefixFor = static fn (string $path): ?string => $withPrefix ? "BIB-{$tag}{$path}" : null;
        $expected = static fn (int $id, ?string $prefix, int $n): string => ($prefix ?? "LIB-{$id}") . "-C{$n}";

        echo "\nA. Book form receipt ({$case})\n";
        $_SESSION = [];
        $prefix = $prefixFor('A');
        $bookA = $createWanted("form {$case}", $prefix);
        // The edit that closes the request: flag unticked, two copies asked for.
        // prepareBook() is the book.form.save filter, bookSaved() the
        // book.save.after action — the same pair core fires on a real save.
        $plugin->prepareBook(['titolo' => $tag . " form {$case}"], ['desiderata_form' => '1', 'is_desiderata' => '0', 'desiderata_copies' => '2'], $bookA);
        $plugin->bookSaved($bookA);
        $codes = $codesOf($bookA);
        $want = [$expected($bookA, $prefix, 1), $expected($bookA, $prefix, 2)];
        $check($codes === $want, "two copies coded {$show($want)} (got {$show($codes)})");

        echo "\nB. Direct receipt at the desk ({$case})\n";
        $_SESSION = [];
        $prefix = $prefixFor('B');
        $bookB = $createWanted("direct {$case}", $prefix);
        $response = $plugin->receiveDirect($post('/admin/desiderata/books/' . $bookB . '/receive', []), new Slim\Psr7\Response(), $bookB);
        $codes = $codesOf($bookB);
        $check($response->getStatusCode() === 303, 'the receipt is accepted (got HTTP ' . $response->getStatusCode() . ')');
        $want = [$expected($bookB, $prefix, 1)];
        $check($codes === $want, "one copy coded {$show($want)} (got {$show($codes)})");

        echo "\nC. Donation offer marked received ({$case})\n";
        $_SESSION = [];
        $prefix = $prefixFor('C');
        $bookC = $createWanted("offer {$case}", $prefix);
        $title = $tag . " offer {$case}";
        $stmt = $db->prepare("INSERT INTO desiderata_offers (book_id, donor_name, donor_email, title, notes, status) VALUES (?, 'Test donor', 'donor@example.invalid', ?, '', 'accepted')");
        $stmt->bind_param('is', $bookC, $title);
        $stmt->execute();
        $offerId = (int) $db->insert_id;
        $stmt->close();
        $offerIds[] = $offerId;
        $response = $plugin->manage($post('/admin/desiderata/offers/' . $offerId, ['action' => 'received']), new Slim\Psr7\Response(), $offerId);
        $codes = $codesOf($bookC);
        $check($response->getStatusCode() === 303, 'the receipt is accepted (got HTTP ' . $response->getStatusCode() . ')');
        $want = [$expected($bookC, $prefix, 1)];
        $check($codes === $want, "one copy coded {$show($want)} (got {$show($codes)})");
    }

    echo "\nD. The rule itself\n";
    $check(CopyRepository::inventoryBase(7, 'BIB-') === 'BIB-', 'a prefix is used as the base');
    $check(CopyRepository::inventoryBase(7, '  BIB-  ') === 'BIB-', 'surrounding whitespace is not part of the base');
    $check(CopyRepository::inventoryBase(7, '0') === '0', 'a prefix of "0" is a prefix, not an empty value');
    foreach ([null, '', '   '] as $blank) {
        $check(CopyRepository::inventoryBase(7, $blank) === 'LIB-7', 'no prefix (' . var_export($blank, true) . ') falls back to LIB-{id}');
    }
} catch (Throwable $thrown) {
    $fatalError = $thrown;
} finally {
    $cleanupErrors = [];
    $run = static function (string $sql) use ($db, &$cleanupErrors): void {
        try {
            $db->query($sql);
        } catch (Throwable $e) {
            $cleanupErrors[] = $sql . ': ' . $e->getMessage();
        }
    };
    if ($offerIds !== []) {
        $run('DELETE FROM desiderata_offers WHERE id IN (' . implode(',', $offerIds) . ')');
    }
    if ($bookIds !== []) {
        $list = implode(',', $bookIds);
        // Paths A and B write their own audit row (status 'received') per book.
        $run('DELETE FROM desiderata_offers WHERE book_id IN (' . $list . ') OR received_book_id IN (' . $list . ')');
        $run('DELETE FROM copie WHERE libro_id IN (' . $list . ')');
        $run("DELETE FROM log_modifiche WHERE tabella = 'libri' AND record_id IN (" . $list . ')');
        $run('DELETE FROM libri WHERE id IN (' . $list . ')');
    }
    // notifyReceipt() rings the operators' bell with the book title.
    $run("DELETE FROM admin_notifications WHERE title LIKE '%" . $db->real_escape_string($tag) . "%' OR message LIKE '%" . $db->real_escape_string($tag) . "%'");
    try {
        App\Support\ContentCache::booksChanged();
    } catch (Throwable $e) {
        $cleanupErrors[] = 'ContentCache::booksChanged(): ' . $e->getMessage();
    }
}

foreach ($cleanupErrors as $message) {
    fwrite(STDERR, "CLEANUP FAILED: {$message}\n");
}
if ($fatalError !== null) {
    fwrite(STDERR, "\nFAIL: " . get_class($fatalError) . ': ' . $fatalError->getMessage() . "\n");
    exit(1);
}

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS\n" : "{$pass} PASS, {$fail} FAIL\n");
exit($fail === 0 && $cleanupErrors === [] ? 0 : 1);
