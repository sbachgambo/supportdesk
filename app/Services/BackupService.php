<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use PDO;
use RuntimeException;

/**
 * BackupService (§14) — a DB dump via mysqldump.
 *
 * Uses a temporary --defaults-extra-file (chmod 600) so the DB password never
 * appears in the process list / argv. Dumps to storage/backups/ (OUTSIDE the
 * webroot). Phase 10/14 layer gzip + AES-256 encryption + retention + the cron on
 * top of this; the reset flow (§18 "back up before you delete") uses create() now.
 *
 * NOTE: dumping inherently needs dynamic table names, which SQL prepared statements
 * cannot express — so this uses the mysqldump binary rather than PHP SQL, keeping
 * §10.1 (no interpolated identifiers in application SQL) absolute.
 */
final class BackupService
{
    public static function backupDir(): string
    {
        return (defined('P3A_ROOT') ? P3A_ROOT : dirname(__DIR__, 2)) . '/storage/backups';
    }

    private static function mysqldumpBin(): string
    {
        return Config::has('MYSQLDUMP_BIN') ? Config::string('MYSQLDUMP_BIN') : 'mysqldump';
    }

    /** Create a plain .sql dump and return its path. Throws on failure. */
    public static function create(): string
    {
        $dir = self::backupDir();
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new RuntimeException('Backup directory is not writable.');
        }

        $stamp = gmdate('Ymd_His');
        $outFile = $dir . '/backup_' . $stamp . '_' . bin2hex(random_bytes(3)) . '.sql';
        $errFile = $outFile . '.err';

        // Credentials via a temp defaults file (not argv). Password never in process list.
        $defaults = tempnam(sys_get_temp_dir(), 'p3adump');
        $ini = "[client]\n"
             . 'host=' . Config::string('DB_HOST', 'localhost') . "\n"
             . 'port=' . Config::int('DB_PORT', 3306) . "\n"
             . 'user=' . Config::string('DB_USER') . "\n"
             . 'password="' . str_replace('"', '\"', Config::string('DB_PASS', '')) . "\"\n";
        file_put_contents($defaults, $ini);
        chmod($defaults, 0600);

        $cmd = sprintf(
            '%s --defaults-extra-file=%s --single-transaction --quick --skip-lock-tables --no-tablespaces %s > %s 2> %s',
            escapeshellarg(self::mysqldumpBin()),
            escapeshellarg($defaults),
            escapeshellarg(Config::string('DB_NAME')),
            escapeshellarg($outFile),
            escapeshellarg($errFile)
        );

        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        $err = is_file($errFile) ? trim((string) file_get_contents($errFile)) : '';
        // mysqldump emits a benign password-on-CLI note to stderr sometimes; ignore empties.
        if (is_file($defaults)) {
            unlink($defaults);
        }
        if (is_file($errFile)) {
            unlink($errFile);
        }

        if ($code !== 0 || !is_file($outFile) || filesize($outFile) === 0) {
            if (is_file($outFile)) {
                unlink($outFile);
            }
            throw new RuntimeException('mysqldump failed: ' . ($err !== '' ? $err : "exit {$code}"));
        }

        chmod($outFile, 0600);
        return $outFile;
    }

    /**
     * The cron path (§14): dump → gzip → AES-256-CBC encrypt (key from APP_KEY) →
     * remove the plaintext dump → prune old backups. Returns the final file path.
     * With BACKUP_ENCRYPT=0 it stops at gzip. The plaintext .sql never lingers.
     */
    public static function createBackup(): string
    {
        $sqlPath = self::create();
        $encrypt = Config::bool('BACKUP_ENCRYPT', true);
        $finalPath = $sqlPath . ($encrypt ? '.gz.enc' : '.gz');

        $plain = (string) file_get_contents($sqlPath);
        $gz = gzencode($plain, 9);
        if ($gz === false) {
            unlink($sqlPath);
            throw new RuntimeException('gzip failed');
        }

        if ($encrypt) {
            $iv = random_bytes(16);
            $cipher = openssl_encrypt($gz, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv);
            if ($cipher === false) {
                unlink($sqlPath);
                throw new RuntimeException('encryption failed');
            }
            file_put_contents($finalPath, $iv . $cipher);
        } else {
            file_put_contents($finalPath, $gz);
        }
        chmod($finalPath, 0600);
        unlink($sqlPath); // no plaintext dump left behind

        self::prune();
        return $finalPath;
    }

    /** Decrypt (if needed) + gunzip a backup file back to raw SQL. */
    public static function decrypt(string $path): string
    {
        $raw = (string) file_get_contents($path);
        if (str_ends_with($path, '.gz.enc')) {
            $iv = substr($raw, 0, 16);
            $cipher = substr($raw, 16);
            $gz = openssl_decrypt($cipher, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv);
            if ($gz === false) {
                throw new RuntimeException('decryption failed (wrong APP_KEY?)');
            }
            $sql = gzdecode($gz);
        } elseif (str_ends_with($path, '.gz')) {
            $sql = gzdecode($raw);
        } else {
            $sql = $raw;
        }
        if ($sql === false) {
            throw new RuntimeException('gunzip failed');
        }
        return $sql;
    }

    /** Restore a backup file into the given PDO connection (a scratch DB for drills). */
    public static function restoreInto(string $path, PDO $pdo): void
    {
        $sql = self::decrypt($path);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->exec($sql);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    /** Delete backups older than BACKUP_RETAIN_DAYS. */
    public static function prune(): int
    {
        $cutoff = time() - Config::int('BACKUP_RETAIN_DAYS', 14) * 86400;
        $removed = 0;
        foreach (glob(self::backupDir() . '/backup_*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                if (unlink($file)) {
                    $removed++;
                }
            }
        }
        return $removed;
    }

    /** 32-byte AES key derived from APP_KEY. */
    private static function key(): string
    {
        return hash('sha256', Config::appKey(), true);
    }
}
