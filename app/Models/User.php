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

    /**
     * The least-busy active agent's email for auto-assign (§3): fewest open/pending
     * assigned tickets wins, ties broken by email. Null if there are no active agents.
     */
    public static function leastBusyAgentEmail(): ?string
    {
        $row = Db::queryOne(
            "SELECT u.email, COUNT(t.id) AS load_count
             FROM users u
             LEFT JOIN tickets t ON t.assigned_to = u.email AND t.status IN ('open','pending')
             WHERE u.role = 'agent' AND u.active = 1
             GROUP BY u.email
             ORDER BY load_count ASC, u.email ASC
             LIMIT 1"
        );
        return $row === null ? null : (string) $row['email'];
    }

    public static function findActiveAgent(string $email): ?array
    {
        return Db::queryOne(
            "SELECT * FROM users WHERE email = :e AND active = 1 AND role IN ('agent','admin') LIMIT 1",
            [':e' => $email]
        );
    }

    // ── admin management (Phase 7) ───────────────────────────────────────────
    /** Every user, without password hashes, for the admin list. */
    public static function all(): array
    {
        return Db::queryAll(
            'SELECT id, public_id, name, email, role, active, totp_enabled, must_change_pw, last_login_at, created_at
             FROM users ORDER BY role, name'
        );
    }

    public static function create(array $data): int
    {
        return (int) Db::insert('users', [
            'public_id'      => $data['public_id'],
            'name'           => $data['name'],
            'email'          => strtolower(trim((string) $data['email'])),
            'password_hash'  => $data['password_hash'],
            'role'           => $data['role'],
            'active'         => 1,
            'must_change_pw' => !empty($data['must_change_pw']) ? 1 : 0,
            'created_at'     => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<string,mixed> $fields (allowlisted by the caller) */
    public static function update(int $id, array $fields): void
    {
        Db::update('users', $fields, 'id = :id', [':id' => $id]);
    }

    public static function setActive(int $id, bool $active): void
    {
        Db::update('users', ['active' => $active ? 1 : 0], 'id = :id', [':id' => $id]);
    }

    public static function setPasswordByAdmin(int $id, string $hash): void
    {
        Db::update('users', ['password_hash' => $hash, 'must_change_pw' => 1], 'id = :id', [':id' => $id]);
    }

    public static function delete(int $id): void
    {
        Db::delete('users', 'id = :id', [':id' => $id]);
    }

    /** Number of currently-active admins (last-admin guard). */
    public static function activeAdminCount(): int
    {
        return (int) Db::scalar("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1");
    }

    /** Next public id for a role: AD-/AG-/CU- prefix with a zero-padded sequence. */
    public static function nextPublicId(string $role): string
    {
        $prefix = match ($role) {
            'admin'    => 'AD',
            'agent'    => 'AG',
            default    => 'CU',
        };
        $max = Db::scalar(
            "SELECT MAX(CAST(SUBSTRING_INDEX(public_id, :dash, -1) AS UNSIGNED))
             FROM users WHERE public_id LIKE :like",
            [':dash' => '-', ':like' => $prefix . '-%']
        );
        return sprintf('%s-%04d', $prefix, ((int) $max) + 1);
    }
}
