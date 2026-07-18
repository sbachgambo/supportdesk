<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

/**
 * User (Model) — thin repository over the users table. No business rules live here
 * (those belong in Services/Security); no SQL lives outside Models/ and Core\Db.
 */
final class User
{
    public static function findByEmail(string $email): ?array
    {
        return Db::queryOne(
            'SELECT * FROM users WHERE email = :e LIMIT 1',
            [':e' => $email]
        );
    }

    public static function findById(int $id): ?array
    {
        return Db::queryOne('SELECT * FROM users WHERE id = :id LIMIT 1', [':id' => $id]);
    }

    public static function findByPublicId(string $publicId): ?array
    {
        return Db::queryOne('SELECT * FROM users WHERE public_id = :p LIMIT 1', [':p' => $publicId]);
    }

    public static function updatePasswordHash(int $id, string $hash, bool $clearMustChange = true): void
    {
        $data = ['password_hash' => $hash];
        if ($clearMustChange) {
            $data['must_change_pw'] = 0;
        }
        Db::update('users', $data, 'id = :id', [':id' => $id]);
    }

    public static function touchLastLogin(int $id): void
    {
        Db::update('users', ['last_login_at' => gmdate('Y-m-d H:i:s')], 'id = :id', [':id' => $id]);
    }

    public static function isActive(array $user): bool
    {
        return (int) ($user['active'] ?? 0) === 1;
    }
}
