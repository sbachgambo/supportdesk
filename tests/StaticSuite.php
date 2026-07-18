<?php
declare(strict_types=1);

/**
 * Static suite (§17.2) — runs WITHOUT a database, in ~1 second.
 * This is the fast feedback loop the watcher fires on every save.
 *
 * It encodes the §10 / §16 rules as machine checks so they cannot rot:
 *   - php -l on every PHP file (a single bad escape once broke a whole deploy)
 *   - no SQL string interpolation
 *   - no PDO outside Core\Db
 *   - no $_GET/$_POST/$_FILES/$_COOKIE outside Core\Request
 *   - no debug/danger constructs (var_dump, eval, @ suppression, unserialize)
 *   - .env is gitignored; no obvious secrets committed
 */

require __DIR__ . '/lib.php';

$root = dirname(__DIR__);
chdir($root);

T::suite('Static: php -l (syntax) on every PHP file');
$phpFiles = array_merge(
    t_files("$root/app", 'php'),
    t_files("$root/bin", 'php'),
    t_files("$root/public", 'php'),
    t_files("$root/tests", 'php'),
    t_files("$root/scripts", 'php')
);
if ($phpFiles === []) {
    T::note('no PHP files yet — nothing to lint (expected early in Phase 1)');
}
foreach ($phpFiles as $f) {
    $rel = ltrim(str_replace($root, '', $f), '/');
    $out = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $code);
    T::ok($code === 0, "lint {$rel}" . ($code === 0 ? '' : ': ' . implode(' ', $out)));
}

/**
 * Scan helper: read app/ PHP files (excluding a set of allowed paths) and flag
 * any line matching $pattern. `$allow` is a list of path substrings exempted.
 */
$scan = function (string $pattern, array $allow, string $label, string $why): void {
    global $root;
    $hits = [];
    foreach (t_files("$root/app", 'php') as $f) {
        $rel = ltrim(str_replace($root, '', $f), '/');
        foreach ($allow as $a) {
            if (str_contains($rel, $a)) {
                continue 2;
            }
        }
        // Scan comment-stripped source so keywords/annotations inside comments
        // (PHPDoc @param, "-- SELECT", …) never register as findings.
        $code = t_strip_comments((string) file_get_contents($f));
        foreach (explode("\n", $code) as $n => $line) {
            if (trim($line) !== '' && preg_match($pattern, $line)) {
                $hits[] = "{$rel}:" . ($n + 1) . '  ' . trim($line);
            }
        }
    }
    $clean = $hits === [];
    T::ok($clean, $label . ($clean ? '' : "\n      → {$why}\n      " . implode("\n      ", $hits)));
};

T::suite('Static: security invariants (§10, §16)');

// §10.1 — no PDO usage outside Core\Db
$scan('/\bPDO\b/', ['Core/Db.php'], 'no PDO outside Core/Db.php',
    'all DB access goes through Core\\Db (§10.1)');

// §16 — superglobals only inside Core\Request
$scan('/\$_(GET|POST|FILES|COOKIE|REQUEST)\b/', ['Core/Request.php'],
    'no $_GET/$_POST/$_FILES/$_COOKIE outside Core/Request.php',
    'raw input is read only in Core\\Request (§16)');

// §10.1 — no SQL string interpolation. Two shapes:
//   (a) a variable interpolated INSIDE a quoted string that contains a SQL keyword
//       e.g. "SELECT * FROM $table"  /  "... WHERE id = $id"
//   (b) a quoted SQL keyword string CONCATENATED with a variable
//       e.g. "SELECT ... FROM " . $table
$sqlKw = 'SELECT|INSERT|UPDATE|DELETE|FROM|WHERE|INTO|VALUES|JOIN';
$scan(
    '/"[^"\n]*\b(' . $sqlKw . ')\b[^"\n]*\$\w+' .          // (a) interpolation in string
    '|\b(' . $sqlKw . ')\b[^;\n]*["\']\s*\.\s*\$\w+/i',    // (b) concat with a var
    ['Core/Db.php'],
    'no SQL string interpolation',
    'every query is a prepared statement with bound params (§10.1)');

// §16 — no debug / danger constructs left in app code
$scan('/\b(var_dump|print_r|var_export)\s*\(/', ['tests/'], 'no var_dump/print_r/var_export in app/',
    'no debug output left behind (§16)');
$scan('/\beval\s*\(/', [], 'no eval() in app/', 'never eval (§16)');
$scan('/\bunserialize\s*\(/', [], 'no unserialize() in app/', 'JSON only; never unserialize untrusted data (§10, §16)');
// Error suppression is `@func(...)` or `@$var`. Exclude `@` inside identifiers
// (e.g. an email in a string literal: the char before @ is a word char there).
$scan('/(^|[^\w"\'\/@.])@([a-zA-Z_]\w*\s*\(|\$)/', [], 'no @ error suppression in app/',
    'never suppress errors with @ (§16)');

T::suite('Static: repo hygiene (§17.2)');

$gitignore = is_file("$root/.gitignore") ? file_get_contents("$root/.gitignore") : '';
T::ok(str_contains($gitignore, '.env'), '.env is present in .gitignore');

// .env must not be tracked by git (best-effort; skipped if git absent)
exec('git ls-files .env 2>&1', $tracked, $gitCode);
if ($gitCode === 0) {
    T::ok(trim(implode('', $tracked)) === '', '.env is not tracked by git');
} else {
    T::note('git not available — skipping tracked-file check');
}

// crude committed-secret scan: an APP_KEY/DB_PASS with a real value in .env.example
$example = is_file("$root/.env.example") ? file_get_contents("$root/.env.example") : '';
$leaks = preg_match('/^(APP_KEY|DB_PASS|MAIL_PASS|IMAP_PASS)=\S+/m', $example);
T::ok($leaks === 0, '.env.example carries no real secret values');

exit(T::summary());
