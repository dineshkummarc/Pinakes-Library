<?php
declare(strict_types=1);

/**
 * Issue #480 — every public/.htaccess Pinakes ever shipped, healed on upgrade.
 *
 * tests/fixtures/htaccess-history/ holds each distinct public/.htaccess of the released
 * tags (v0.1.1 … v0.8.6-rc.1). An install keeps the one it was installed with, because the
 * updater preserves the file. For each of them PrivateUploadsRewriteHeal must leave the
 * private-uploads rule with [NC] ahead of every public /uploads/ rule, change nothing else
 * but the no-gzip list, and do nothing the second time. A rule the operator edited stays.
 *
 * Run:  php tests/htaccess-heal-history-480.unit.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\PrivateUploadsRewriteHeal as Heal;

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};
/** Lines that are not rewrite rules, comments, blanks or the no-gzip list: they must not change. */
$rest = static fn (string $s): array => array_values(array_filter(preg_split('/\r?\n/', $s) ?: [],
    static fn (string $l): bool => trim($l) !== '' && !str_starts_with(ltrim($l), '#')
        && !preg_match('/^\s*Rewrite(Rule|Cond)\b/', $l) && !str_contains($l, 'no-gzip')));

$files = glob(__DIR__ . '/fixtures/htaccess-history/*.htaccess') ?: [];
natsort($files);
$check(count($files) >= 13, count($files) . ' shipped versions of public/.htaccess');
foreach ($files as $file) {
    $version = basename($file, '.htaccess');
    $old = (string) file_get_contents($file);
    $new = Heal::apply($old);
    $hasUploads = str_contains($old, 'uploads');
    if (!$hasUploads) {
        $check($new === $old, "{$version}: no /uploads/ rule at all, left as it is");
        continue;
    }
    $check(Heal::safe($new), "{$version}: private uploads go through index.php with [NC], before the public /uploads/ rule");
    $check(!preg_match('/^\s*RewriteCond %\{REQUEST_URI\} \^\/uploads\//m', $new), "{$version}: no anchored REQUEST_URI rule left (it never matches under a subfolder)");
    $check($rest($new) === $rest($old), "{$version}: every other directive is untouched");
    $check(Heal::apply($new) === $new, "{$version}: a second upgrade changes nothing");
    $check(str_contains($new, 'epub|pdf|mp4|webm|mp3|m4a|ogg|wav)$ no-gzip') || !str_contains($old, 'no-gzip'), "{$version}: PDF and WAV are never compressed");
}

// Saved on Windows: CRLF line ends are recognised, and kept in the lines the heal adds.
foreach ($files as $file) {
    $version = basename($file, '.htaccess');
    $crlf = str_replace("\n", "\r\n", str_replace("\r\n", "\n", (string) file_get_contents($file)));
    if (!str_contains($crlf, 'uploads')) {
        continue;
    }
    $healed = Heal::apply($crlf);
    $check(Heal::safe($healed) && preg_match('/(?<!\r)\n/', $healed) !== 1 && Heal::apply($healed) === $healed,
        "{$version} with CRLF line ends: healed, still CRLF throughout, idempotent");
    $check(!str_contains($crlf, 'no-gzip') || str_contains($healed, "epub|pdf|mp4|webm|mp3|m4a|ogg|wav)\$ no-gzip dont-vary\r\n"), "{$version} with CRLF: the no-gzip list is updated too");
}

// heal() itself: true once the file is safe, false (and so logged by the caller) when it declines.
$dir = sys_get_temp_dir() . '/htaccess-heal-' . bin2hex(random_bytes(4));
mkdir($dir);
$path = $dir . '/.htaccess';
file_put_contents($path, (string) file_get_contents(__DIR__ . '/fixtures/htaccess-history/0.7.71.htaccess'));
$check(Heal::heal($path, $dir . '/backups') && Heal::safe((string) file_get_contents($path)), 'heal() on a shipped 0.7.71 file: true, and the file is safe');
$check(Heal::heal($path, $dir . '/backups') && count(glob($dir . '/backups/htaccess.bak-480-*') ?: []) === 1, 'a second heal() is true and makes no new backup');

// A safe file written by hand in another spelling is safe: no false warning on every upgrade.
$healed = Heal::apply((string) file_get_contents(__DIR__ . '/fixtures/htaccess-history/0.7.71.htaccess'));
$respelled = str_replace('index.php [NC,QSA,L]', 'index.php  [last,QSA,nocase]', $healed);
$check($respelled !== $healed && Heal::safe($respelled), 'the long flag names (nocase, last) count as NC and L');
$reordered = str_replace('index.php [NC,QSA,L]', 'index.php   [QSA, L, NC, E=MINE:1]', $healed);
$check($reordered !== $healed && Heal::safe($reordered) && Heal::apply($reordered) === $reordered, 'the private rule with flags in another order and extra spaces is safe, and left alone');
$withoutNc = str_replace('index.php [NC,QSA,L]', 'index.php [QSA,L]', $healed);
$check(!Heal::safe($withoutNc), 'without NC it is not safe');
$withoutL = str_replace('index.php [NC,QSA,L]', 'index.php [NC,QSA]', $healed);
$check(!Heal::safe($withoutL), 'without L (or last) it is not safe: the public rule below would still apply');

// A public /uploads/ rule ABOVE the private one serves private files from disk in any spelling.
$privateLine = '    RewriteRule ^uploads/(digital|archives/documents|storage)/ index.php [NC,QSA,L]';
$publicAbove = static fn (string $public): string => str_replace($privateLine, "    {$public}\n{$privateLine}", $healed);
$check(str_contains($healed, $privateLine) && Heal::safe($healed), 'the healed 0.7.71 file has the private line the next checks put rules above');
foreach (['RewriteRule ^uploads/ - [L,NC]', 'RewriteRule ^uploads - [L]', 'RewriteRule   ^uploads/   -   [L]', 'rewriterule ^uploads/ - [l]',
          'RewriteRule ^/?uploads/ - [last]', 'RewriteRule ^uploads/.*$ - [END]', 'RewriteRule uploads/ - [QSA,L]', 'RewriteRule ^Uploads/ - [NC,L]',
          'RewriteRule "^uploads/" - [L]', 'RewriteRule ^uploads/(?!digital/) - [L]', 'RewriteRule ^uploads/ - [S=1]',
          "RewriteCond %{REQUEST_URI} ^/uploads [NC]\n    RewriteRule ^ - [L]", "RewriteCond %{HTTP_HOST} ^cdn\\. [NC]\n    RewriteRule .* - [L]",
          // Patterns that never name /uploads/ still reach every private file with that ending or shape.
          'RewriteRule \.epub$ - [L]', 'RewriteRule \.(pdf|mp3)$ - [L,NC]', 'RewriteRule ^(.*)$ - [L]', 'RewriteRule ^.*/a\.pdf$ - [END]',
          'RewriteRule \.(epub|mp3|m4a|ogg|wav)$ - [L]', 'RewriteRule \.(jpe?g|png|webp)$ - [NC,L]', 'RewriteRule ^uploads/digital/.+\.epub$ - [L]',
          // A literal start that leads into a private folder, or one cut short by a quantifier.
          'RewriteRule ^uploads/digital - [L]', 'RewriteRule ^uploads/storage/public/ - [L]', 'RewriteRule ^uploadss?/ - [L]', 'RewriteRule ^UPLOADS/Digital/ - [L]',
          // A safe-looking start with an unanchored branch after "|", at any depth.
          'RewriteRule ^assets/|uploads/ - [L]', 'RewriteRule ^thumbs/|\.pdf$ - [NC,L]', 'RewriteRule ^(assets|uploads)/ - [L]',
          // Without NC, /uploads/Digital/ skips a lowercase exclusion on a case-insensitive disk.
          'RewriteRule !^uploads/ - [L]',
          // Unprovable without reasoning about the regex, so reported: a lookahead is no proof.
          'RewriteRule ^uploads/(?!digital/|archives/documents/|storage/) - [NC,L]'] as $public) {
    $check(!Heal::safe($publicAbove($public)), 'a public rule above the private one is not safe: ' . str_replace("\n    ", ' + ', $public));
}
foreach (['RewriteRule ^uploads/ - [NC]' => 'a passthrough without L goes on to the private rule below',
          'RewriteRule \.epub$ - [NC]' => 'a passthrough on an ending without L',
          'RewriteRule ^uploads/thumbs/ - [L]' => 'a passthrough on a public subfolder only',
          'RewriteRule ^uploads/digitalx/ - [L]' => 'a folder that only starts like a private one',
          'RewriteRule ^uploadsarchive/ - [L]' => 'a folder whose name only starts with "uploads"',
          'RewriteRule ^assets/.*\.css$ - [L]' => 'a passthrough outside /uploads/',
          'RewriteRule .* - [E=MINE:1]' => 'an E= rule like the shipped ones'] as $public => $why) {
    $check(Heal::safe($publicAbove($public)), "{$why} is safe: {$public}");
}

// A rule the operator wrote is theirs.
$a = (string) file_get_contents(__DIR__ . '/fixtures/htaccess-history/0.7.13.htaccess');
$edited = str_replace("RewriteCond %{REQUEST_URI} ^/uploads/ [NC]\n    RewriteRule ^ - [L]", "RewriteCond %{REQUEST_URI} ^/uploads/ [NC]\n    RewriteCond %{HTTP_HOST} ^cdn\\. [NC]\n    RewriteRule ^ - [L]", $a);
file_put_contents($path, $edited);
$check(!Heal::heal($path, $dir . '/backups') && file_get_contents($path) !== false && !Heal::safe((string) file_get_contents($path)),
    'heal() declining an edited rule returns false, so the upgrade logs that private uploads may still leak');
array_map('unlink', glob($dir . '/backups/*') ?: []);
@rmdir($dir . '/backups');
@unlink($path);
@rmdir($dir);
$check($edited !== $a && !Heal::safe(Heal::apply($edited)) && preg_replace('/^.*no-gzip.*$/m', '', Heal::apply($edited)) === preg_replace('/^.*no-gzip.*$/m', '', $edited),
    'an /uploads/ rule the operator edited is left alone');
$b = (string) file_get_contents(__DIR__ . '/fixtures/htaccess-history/0.7.21.htaccess');
$half = preg_replace('/^\s*RewriteCond %\{REQUEST_URI\} \^\/uploads\/\(digital\|archives\/documents\|storage\)\/ \[NC\]\r?\n\s*RewriteRule \^ index\.php \[QSA,L\]\r?\n/m', '', $b);
$check($half !== $b && preg_replace('/^.*no-gzip.*$/m', '', Heal::apply((string) $half)) === preg_replace('/^.*no-gzip.*$/m', '', (string) $half),
    'a 0.7.18-style file missing its private half is not rewritten into an unsafe order');

// An operator RewriteCond ABOVE a shipped block binds to the first RewriteRule below it, across
// comments, blank lines and any other directive (only a RewriteRule takes the pending
// conditions). Splicing the private rule there would make it conditional (and on
// every other host the public rule serves private files from disk), so the block stays as it
// is and the file is reported as not safe.
$rules = static fn (string $s): array => array_values(preg_grep('/^\s*Rewrite(Rule|Cond)\b/', preg_split('/\r?\n/', $s) ?: []) ?: []);
$condAbove = static fn (string $s, string $anchor, string $between): string
    => (string) preg_replace('/' . preg_quote($anchor, '/') . '/', "    RewriteCond %{HTTP_HOST} ^cdn\\\\. [NC]\n" . $between . $anchor, $s, 1);
$healReports = static function (string $content): bool {
    $d = sys_get_temp_dir() . '/htaccess-heal-' . bin2hex(random_bytes(4));
    mkdir($d);
    file_put_contents($d . '/.htaccess', $content);
    $ok = Heal::heal($d . '/.htaccess', $d . '/backups');
    array_map('unlink', glob($d . '/backups/*') ?: []);
    @rmdir($d . '/backups');
    @unlink($d . '/.htaccess');
    @rmdir($d);
    return $ok;
};
$fixture = static fn (string $v): string => (string) file_get_contents(__DIR__ . "/fixtures/htaccess-history/{$v}.htaccess");
$cases = [
    '0.7.13 (A)' => [$fixture('0.7.13'), "    RewriteCond %{REQUEST_URI} ^/uploads/ [NC]\n    RewriteRule ^ - [L]"],
    '0.7.13 (A), above the comment' => [$fixture('0.7.13'), '    # Allow direct access to static files in /uploads/ directory'],
    '0.7.21 (B passthrough)' => [$fixture('0.7.21'), "    RewriteCond %{REQUEST_URI} ^/uploads/ [NC]\n    RewriteCond %{REQUEST_URI} !^"],
    '0.7.21 (B passthrough), above the comment' => [$fixture('0.7.21'), '    # Allow direct access to PUBLIC static files in /uploads/'],
    '0.7.21 (B private pair)' => [$fixture('0.7.21'), '    RewriteCond %{REQUEST_URI} ^/uploads/(digital'],
    '0.7.21 (B private pair), above the comment' => [$fixture('0.7.21'), '    # PRIVATE uploaded content'],
    '0.7.71 (C)' => [$fixture('0.7.71'), '    RewriteRule ^uploads/(digital|archives/documents|storage)/ index.php [QSA,L]'],
];
$betweens = [
    'directly above' => '',
    'with a comment between' => "    # operator note\n",
    'with a blank line between' => "\n",
    'with a SetEnvIf between' => "    SetEnvIf Request_URI x foo=1\n",
    'with Options and RewriteBase between' => "    Options -Indexes\n    RewriteBase /\n",
];
foreach ($cases as $label => [$base, $anchor]) {
    foreach ($betweens as $how => $between) {
        $edited = $condAbove($base, $anchor, $between);
        $healed = Heal::apply($edited);
        $check($edited !== $base && $rules($healed) === $rules($edited) && !Heal::safe($healed) && !$healReports($edited),
            "{$label}: a RewriteCond {$how} is not moved onto the private rule, and heal() reports the file");
    }
}
// A heal whose rewritten rules would still not be safe (an operator passthrough above the
// private rule) keeps every rewrite rule as it was, but the no-gzip list does not depend on
// them: PDF and WAV still join it, and the second pass changes nothing.
$publicAbove = [
    '0.7.71 (C) with "^uploads/ - [L,NC]" above the private rule' => ['0.7.71', '    RewriteRule ^uploads/(digital|archives/documents|storage)/ index.php [QSA,L]', "    RewriteRule ^uploads/ - [L,NC]\n"],
    '0.7.71 (C) with "\.pdf$ - [L]" above the private rule' => ['0.7.71', '    RewriteRule ^uploads/(digital|archives/documents|storage)/ index.php [QSA,L]', "    RewriteRule \\.pdf$ - [L]\n"],
    '0.7.13 (A) with "^uploads/ - [L]" above the block' => ['0.7.13', '    # Allow direct access to static files in /uploads/ directory', "    RewriteRule ^uploads/ - [L]\n"],
];
foreach ($publicAbove as $label => [$v, $anchor, $insert]) {
    $edited = (string) preg_replace('/' . preg_quote($anchor, '/') . '/', $insert . $anchor, $fixture($v), 1);
    $healed = Heal::apply($edited);
    $check($edited !== $fixture($v) && $rules($healed) === $rules($edited) && !Heal::safe($healed)
        && str_contains($healed, 'epub|pdf|mp4|webm|mp3|m4a|ogg|wav)$ no-gzip') && Heal::apply($healed) === $healed,
        "{$label}: the rewrite rules stay, PDF and WAV still join the no-gzip list");
}
// On disk: that heal writes only the no-gzip line, keeps a backup, and still returns false so
// the caller logs the file; the next run finds nothing to do and writes nothing.
$d = sys_get_temp_dir() . '/htaccess-heal-' . bin2hex(random_bytes(4));
mkdir($d);
[$v, $anchor, $insert] = reset($publicAbove);
$declined = (string) preg_replace('/' . preg_quote($anchor, '/') . '/', $insert . $anchor, $fixture($v), 1);
file_put_contents($d . '/.htaccess', $declined);
$first = Heal::heal($d . '/.htaccess', $d . '/backups');
$written = (string) file_get_contents($d . '/.htaccess');
$backups = count(glob($d . '/backups/*') ?: []);
$second = Heal::heal($d . '/.htaccess', $d . '/backups');
$check(!$first && !$second && $rules($written) === $rules($declined)
    && str_contains($written, 'epub|pdf|mp4|webm|mp3|m4a|ogg|wav)$ no-gzip') && !str_contains($declined, '|pdf|')
    && $backups === 1 && count(glob($d . '/backups/*') ?: []) === 1 && file_get_contents($d . '/.htaccess') === $written,
    'a declined heal writes only the no-gzip line, still returns false, and the second run writes nothing');
array_map('unlink', glob($d . '/backups/*') ?: []);
@rmdir($d . '/backups');
@unlink($d . '/.htaccess');
@rmdir($d);
$healed86 = Heal::apply($fixture('0.7.71'));
foreach ($betweens as $how => $between) {
    $edited = $condAbove($healed86, '    RewriteRule ^uploads/(digital|archives/documents|storage)/ index.php [NC,QSA,L]', $between);
    $check($edited !== $healed86 && !Heal::safe($edited) && !$healReports($edited) && $rules(Heal::apply($edited)) === $rules($edited),
        "a healed 0.8.6 file with a RewriteCond {$how} the [NC] private rule is not safe");
}
$check(Heal::safe($healed86) && Heal::safe($fixture('0.8.6-rc.1')), 'the E=HTTP_AUTHORIZATION RewriteRule or a comment above the private rule is no pending condition');
// The other side of the same rule: a directive with no RewriteCond waiting behind it, or an
// operator condition already taken by its own RewriteRule, leaves nothing pending, so the
// shipped block still heals.
$directive = "    SetEnvIf Request_URI x foo=1\n";
$bound = "    RewriteCond %{HTTP_HOST} ^cdn\\. [NC]\n    RewriteRule ^ - [E=CDN:1]\n    SetEnvIf Request_URI x foo=1\n";
foreach (['0.7.13 (A)' => ['0.7.13', '    # Allow direct access to static files in /uploads/ directory'],
          '0.7.21 (B)' => ['0.7.21', '    # Allow direct access to PUBLIC static files in /uploads/'],
          '0.7.71 (C)' => ['0.7.71', '    RewriteRule ^uploads/(digital|archives/documents|storage)/ index.php [QSA,L]']] as $label => [$v, $anchor]) {
    foreach (['a directive alone' => $directive, 'a condition bound to its own rule' => $bound] as $how => $insert) {
        $edited = (string) preg_replace('/' . preg_quote($anchor, '/') . '/', $insert . $anchor, $fixture($v), 1);
        $healed = Heal::apply($edited);
        $check($edited !== $fixture($v) && $healed !== $edited && Heal::safe($healed) && $healReports($edited),
            "{$label}: {$how} above the block leaves nothing pending, and the file heals");
    }
}


// A rule chained into the private one ([C]) makes it run only when that rule matched, so
// whatever the chained pattern leaves out skips it: any substitution, any pattern.
$base71 = Heal::apply($fixture('0.7.71'));
$chainAbove = static fn (string $rule): string => str_replace($privateLine, "    {$rule}\n{$privateLine}", $base71);
$check(substr_count($base71, $privateLine) === 1 && Heal::safe($base71), 'the healed 0.7.71 file has one private line to chain into, and is safe');
foreach (['RewriteRule ^uploads/thumbs/ - [C]', 'RewriteRule ^x$ index.php [chain]', 'RewriteRule ^assets/ - [QSA, c]'] as $chained) {
    $check(!Heal::safe($chainAbove($chained)), "a rule chained into the private one is not safe: {$chained}");
}
$c71 = $fixture('0.7.71');
$chainedC = str_replace('    RewriteRule ^uploads/(digital|archives/documents|storage)/ index.php [QSA,L]', "    RewriteRule ^assets/ - [C]\n    RewriteRule ^uploads/(digital|archives/documents|storage)/ index.php [QSA,L]", $c71);
$check($chainedC !== $c71 && $rules(Heal::apply($chainedC)) === $rules($chainedC) && !Heal::safe(Heal::apply($chainedC)),
    '0.7.71 (C) with a chained rule right above: the private rule is not rewritten into the chain, and the file is reported');
$check(Heal::safe($chainAbove('RewriteRule ^x$ - [CO=seen:1:example.org]')), 'a cookie flag (CO=) is no chain: safe');

// END stops rewriting like L, so a private rule ending in END protects as well: a custom
// but safe file must not be reported on every maintenance window.
$endPrivate = str_replace($privateLine, str_replace('[NC,QSA,L]', '[NC,QSA,END]', $privateLine), $base71);
$check($endPrivate !== $base71 && Heal::safe($endPrivate), 'a private rule ending in END instead of L is safe');

// When PCRE gives up (backtrack or JIT limits), preg_* return null. Cast to a string, that
// null became an empty public/.htaccess, which heal() wrote and called acceptable.
$pcreLimits = [ini_get('pcre.backtrack_limit'), ini_get('pcre.jit')];
$tmpDir = sys_get_temp_dir() . '/htaccess-heal-pcre-' . getmypid();
@mkdir($tmpDir . '/backups', 0775, true);
$tmpFile = $tmpDir . '/.htaccess';
file_put_contents($tmpFile, $fixture('0.7.71'));
try {
    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '1');
    $check(Heal::apply($fixture('0.7.71')) === $fixture('0.7.71'), 'when PCRE gives up, apply() keeps the file as it is, never an empty one');
    Heal::heal($tmpFile, $tmpDir . '/backups');
    clearstatcache();
    $check((string) file_get_contents($tmpFile) === $fixture('0.7.71'), 'and heal() leaves public/.htaccess untouched on disk');
} finally {
    ini_set('pcre.backtrack_limit', (string) $pcreLimits[0]);
    ini_set('pcre.jit', (string) $pcreLimits[1]);
    array_map('unlink', glob($tmpDir . '/backups/*') ?: []);
    @rmdir($tmpDir . '/backups');
    @unlink($tmpFile);
    @rmdir($tmpDir);
}

echo "\n" . ($fail === 0 ? "ALL {$pass} PASS" : "{$fail} FAILED, {$pass} passed") . "\n";
exit($fail > 0 ? 1 : 0);
