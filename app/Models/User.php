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
     * The least-busy active agent's email for auto-assign (§3), scoped to an
     * organization (multi-tenancy): fewest open/pending assigned tickets wins, ties
     * broken by email. $orgId null → the general pool (agents with no organization).
     * `<=>` is the NULL-safe equality so NULL matches NULL. Null if none qualify.
     */
    public static function leastBusyAgentEmail(?string $orgId = null): ?string
    {
        $row = Db::queryOne(
            "SELECT u.email, COUNT(t.id) AS load_count
             FROM users u
             LEFT JOIN tickets t ON t.assigned_to = u.email AND t.status IN ('open','pending')
             WHERE u.role = 'agent' AND u.active = 1 AND u.organization_id <=> :org
             GROUP BY u.email
             ORDER BY load_count ASC, u.email ASC
             LIMIT 1",
            [':org' => $orgId]
        );
        return $row === null ? null : (string) $row['email'];
    }

    /** Clear the organization link on all users in a deleted organization. */
    public static function nullifyOrganization(string $orgId): void
    {
        Db::update('users', ['organization_id' => null], 'organization_id = :o', [':o' => $orgId]);
    }

    /** Active staff (agents, org admins, admins) for assignment dropdowns. */
    public static function activeAgents(): array
    {
        return Db::queryAll(
            "SELECT name, email, organization_id FROM users WHERE active = 1 AND role IN ('agent','org_admin','admin') ORDER BY name"
        );
    }

    public static function findActiveAgent(string $email): ?array
    {
        return Db::queryOne(
            "SELECT * FROM users WHERE email = :e AND active = 1 AND role IN ('agent','org_admin','admin') LIMIT 1",
            [':e' => $email]
        );
    }

    // ── admin management (Phase 7) ───────────────────────────────────────────
    /**
     * Every user (except the hidden super admin), without password hashes, for the admin
     * list. The super_admin owner account is NEVER surfaced in the UI (§ protected owner).
     */
    public static function all(): array
    {
        return Db::queryAll(
            "SELECT id, public_id, name, email, role, active, totp_enabled, must_change_pw, organization_id, last_login_at, created_at
             FROM users WHERE role <> 'super_admin' ORDER BY role, name"
        );
    }

    /** Staff in one organization (for org-admin management). NULL-safe org match; super admin hidden. */
    public static function allInOrg(?string $orgId): array
    {
        return Db::queryAll(
            "SELECT id, public_id, name, email, role, active, totp_enabled, must_change_pw, organization_id, last_login_at, created_at
             FROM users WHERE organization_id <=> :o AND role <> 'super_admin' ORDER BY role, name",
            [':o' => $orgId]
        );
    }

    public static function create(array $data): int
    {
        return (int) Db::insert('users', [
            'public_id'       => $data['public_id'],
            'name'            => $data['name'],
            'email'           => strtolower(trim((string) $data['email'])),
            'password_hash'   => $data['password_hash'],
            'role'            => $data['role'],
            'active'          => 1,
            'must_change_pw'  => !empty($data['must_change_pw']) ? 1 : 0,
            'organization_id' => ($data['organization_id'] ?? '') !== '' ? $data['organization_id'] : null,
            'created_at'      => gmdate('Y-m-d H:i:s'),
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

    // ── TOTP (D8) ────────────────────────────────────────────────────────────
    /** Store the encrypted secret (pending enrolment; not enabled yet). */
    public static function setTotpSecret(int $id, string $encryptedBlob): void
    {
        Db::update('users', ['totp_secret' => $encryptedBlob, 'totp_enabled' => 0, 'totp_last_step' => null], 'id = :id', [':id' => $id]);
    }

    public static function enableTotp(int $id): void
    {
        Db::update('users', ['totp_enabled' => 1], 'id = :id', [':id' => $id]);
    }

    public static function disableTotp(int $id): void
    {
        Db::update('users', ['totp_secret' => null, 'totp_enabled' => 0, 'totp_last_step' => null], 'id = :id', [':id' => $id]);
    }

    public static function setTotpLastStep(int $id, int $step): void
    {
        Db::update('users', ['totp_last_step' => $step], 'id = :id', [':id' => $id]);
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
            'super_admin' => 'SA',
            'admin'       => 'AD',
            'org_admin'   => 'OA',
            'agent'       => 'AG',
            default       => 'CU',
        };
        $max = Db::scalar(
            "SELECT MAX(CAST(SUBSTRING_INDEX(public_id, :dash, -1) AS UNSIGNED))
             FROM users WHERE public_id LIKE :like",
            [':dash' => '-', ':like' => $prefix . '-%']
        );
        return sprintf('%s-%04d', $prefix, ((int) $max) + 1);
    }
}
