<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

/**
 * BackupCode (Model) — single-use TOTP recovery codes, stored HASHED (§8, D8).
 */
final class BackupCode
{
    /** Replace a user's backup codes with hashes of the given plaintext codes. */
    public static function store(int $userId, array $codes): void
    {
        Db::delete('totp_backup_codes', 'user_id = :u', [':u' => $userId]);
        foreach ($codes as $code) {
            Db::insert('totp_backup_codes', [
                'user_id'   => $userId,
                'code_hash' => password_hash((string) $code, PASSWORD_BCRYPT),
            ]);
        }
    }

    /** Consume a backup code: mark it used if it matches an unused one. Returns true on success. */
    public static function consume(int $userId, string $code): bool
    {
        $rows = Db::queryAll(
            'SELECT id, code_hash FROM totp_backup_codes WHERE user_id = :u AND used_at IS NULL',
            [':u' => $userId]
        );
        foreach ($rows as $row) {
            if (password_verify($code, (string) $row['code_hash'])) {
                Db::update('totp_backup_codes', ['used_at' => gmdate('Y-m-d H:i:s')], 'id = :id', [':id' => (int) $row['id']]);
                return true;
            }
        }
        return false;
    }

    public static function unusedCount(int $userId): int
    {
        return (int) Db::scalar(
            'SELECT COUNT(*) FROM totp_backup_codes WHERE user_id = :u AND used_at IS NULL',
            [':u' => $userId]
        );
    }
}
