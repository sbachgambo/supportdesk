<?php
declare(strict_types=1);

/**
 * Phase 1 test (§17.1) — Foundation.
 * Exit criteria (§15): schema.sql creates 15 tables and seeds; 4 users
 * (1 admin, 2 agents, 1 customer).
 *
 * Loads fresh schema + seed into a SEPARATE test database (.env.testing) so the
 * real database is never touched. If .env.testing is absent the DB-dependent
 * checks are SKIPPED (loudly) rather than failed, so the fast loop stays usable
 * before a test DB exists — but Phase 1 is not "done" until they pass.
 *
 * Tests are exempt from the "no PDO outside Core\Db" rule (§17.2 scans app/ only):
 * a phase test verifies the DB from the outside, before Core\Db exists (Phase 2).
 */

require __DIR__ . '/../lib.php';

$root = dirname(__DIR__, 2);

T::suite('Phase 1: Foundation — files present');
foreach ([
    'composer.json', '.env.example', '.htaccess', 'public/.htaccess',
    'database/schema.sql', 'database/seed.sql',
    'bin/check_env.php', 'bin/generate_key.php', 'bin/install.php',
] as $f) {
    T::ok(is_file("$root/$f"), "exists: {$f}");
}

// ── DB-backed checks ────────────────────────────────────────────────────────
$envPath = "$root/.env.testing";
if (!is_file($envPath)) {
    T::note('.env.testing not found — SKIPPING database checks.');
    T::note('To enable: copy .env.example to .env.testing and set DB_NAME to a scratch DB,');
    T::note('e.g.  DB_NAME=p3a_test  DB_USER=root  DB_PASS=  DB_HOST=127.0.0.1');
    exit(T::summary());
}

$env = t_load_env($envPath);
$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    $env['DB_HOST'] ?? '127.0.0.1',
    $env['DB_NAME'] ?? 'p3a_test',
    $env['DB_CHARSET'] ?? 'utf8mb4'
);

try {
    $pdo = new PDO($dsn, $env['DB_USER'] ?? 'root', $env['DB_PASS'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    T::ok(false, 'connect to test DB: ' . $e->getMessage());
    exit(T::summary());
}

T::suite('Phase 1: load fresh schema + seed');
$run_sql = function (string $file) use ($pdo, $root): bool {
    $sql = file_get_contents("$root/$file");
    if ($sql === false) {
        return false;
    }
    $pdo->exec($sql);
    return true;
};
try {
    // Drop everything first so the load is genuinely fresh and idempotent.
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $pdo->exec('DROP TABLE IF EXISTS `' . $t . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    T::ok($run_sql('database/schema.sql'), 'schema.sql executes without error');
    T::ok($run_sql('database/seed.sql'), 'seed.sql executes without error');
} catch (Throwable $e) {
    T::ok(false, 'schema/seed load: ' . $e->getMessage());
    exit(T::summary());
}

T::suite('Phase 1: exit criteria');
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
// NB: brief §7 header says "15 tables" but defines 16 — the count omits
// totp_backup_codes (added by D8 MFA). 16 is correct. See DEV_NOTES 2026-07-18.
T::eq(16, count($tables), '16 tables created (brief §7 defines 16; "15" is a stale count)');

$expected = [
    'attachments', 'audit_log', 'canned_responses', 'categories', 'config',
    'knowledge_base', 'mail_log', 'messages', 'notifications', 'password_resets',
    'rate_limits', 'routing_rules', 'sessions', 'tickets', 'totp_backup_codes', 'users',
];
sort($tables);
T::ok($tables === $expected, 'table names match the §7 schema exactly');

$userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
T::eq(4, $userCount, 'SELECT COUNT(*) FROM users = 4');

$roles = $pdo->query('SELECT role, COUNT(*) c FROM users GROUP BY role')
             ->fetchAll(PDO::FETCH_KEY_PAIR);
T::eq(1, (int) ($roles['admin'] ?? 0), '1 admin seeded');
T::eq(2, (int) ($roles['agent'] ?? 0), '2 agents seeded');
T::eq(1, (int) ($roles['customer'] ?? 0), '1 customer seeded');

$cats = (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
T::ok($cats > 0, "categories seeded ({$cats})");
$cfg = (int) $pdo->query('SELECT COUNT(*) FROM config')->fetchColumn();
T::ok($cfg > 0, "config seeded ({$cfg} keys)");

// Password hashes must be real hashes, never plaintext.
$hashes = $pdo->query('SELECT password_hash FROM users')->fetchAll(PDO::FETCH_COLUMN);
$allHashed = true;
foreach ($hashes as $h) {
    if (!preg_match('/^\$(argon2|2y)/', (string) $h)) {
        $allHashed = false;
    }
}
T::ok($allHashed, 'seeded passwords are hashed (argon2id/bcrypt), never plaintext');

exit(T::summary());
