<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;
use App\Core\ValidationException;
use App\Models\AppConfig;
use App\Models\Organization;
use App\Models\User;
use App\Security\Audit;
use App\Security\PasswordPolicy;
use App\Services\BackupService;
use App\Services\ResetService;
use App\Services\SlaCalculator;

/**
 * AdminActions — admin-only: users CRUD (with lockout guards), SLA targets, the
 * config allowlist, backup, and ticket-data reset.
 *
 * Lockout guards (§3): you cannot deactivate/delete yourself, and you cannot
 * deactivate/delete the last active admin. Deactivation and admin password reset
 * terminate the affected user's sessions (§10.4).
 */
final class AdminActions
{
    private const CONFIG_ALLOWLIST = [
        'company_name', 'support_email', 'portal_title', 'portal_tagline',
        'brand_color', 'ticket_prefix', 'business_hours_start', 'business_hours_end', 'business_days',
        'require_admin_mfa',
    ];

    // ── users / agents CRUD ──────────────────────────────────────────────────
    public function listUsers(array $payload, Request $request): array
    {
        return ['users' => User::all()];
    }

    public function createUser(array $payload, Request $request): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $role = (string) ($payload['role'] ?? '');
        $password = (string) ($payload['password'] ?? '');

        if ($name === '' || mb_strlen($name) > 120) {
            throw new ValidationException('Name is required (max 120 chars).');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            throw new ValidationException('A valid email is required.');
        }
        if (!in_array($role, ['admin', 'agent', 'customer'], true)) {
            throw new ValidationException('Invalid role.');
        }
        if (User::findByEmail($email) !== null) {
            throw new ValidationException('A user with that email already exists.');
        }
        $policyError = PasswordPolicy::validate($password);
        if ($policyError !== null) {
            throw new ValidationException($policyError);
        }
        // Organization link (multi-tenancy): meaningful for agents; validated if given.
        $orgId = trim((string) ($payload['organization_id'] ?? ''));
        if ($orgId !== '' && Organization::find($orgId) === null) {
            throw new ValidationException('Selected organization does not exist.');
        }

        $id = User::create([
            'public_id'       => User::nextPublicId($role),
            'name'            => $name,
            'email'           => $email,
            'password_hash'   => PasswordPolicy::hash($password),
            'role'            => $role,
            'must_change_pw'  => true, // admin-set password → must change on first login
            'organization_id' => $role === 'agent' && $orgId !== '' ? $orgId : null,
        ]);
        Audit::log((string) Session::email(), 'user_create', $email, "role={$role}");
        return ['id' => $id, 'public_id' => User::findById($id)['public_id']];
    }

    public function updateUser(array $payload, Request $request): array
    {
        $target = $this->requireUser($payload);
        $fields = [];
        if (isset($payload['name'])) {
            $name = trim((string) $payload['name']);
            if ($name === '' || mb_strlen($name) > 120) {
                throw new ValidationException('Name must be 1–120 characters.');
            }
            $fields['name'] = $name;
        }
        if (isset($payload['role'])) {
            $role = (string) $payload['role'];
            if (!in_array($role, ['admin', 'agent', 'customer'], true)) {
                throw new ValidationException('Invalid role.');
            }
            // Demoting the last active admin is a lockout — block it.
            if ((string) $target['role'] === 'admin' && $role !== 'admin' && $this->isLastActiveAdmin($target)) {
                throw new ValidationException('You cannot change the role of the last active admin.');
            }
            $fields['role'] = $role;
        }
        if (array_key_exists('organization_id', $payload)) {
            $orgId = trim((string) $payload['organization_id']);
            if ($orgId !== '' && Organization::find($orgId) === null) {
                throw new ValidationException('Selected organization does not exist.');
            }
            $fields['organization_id'] = $orgId === '' ? null : $orgId;
        }
        if ($fields !== []) {
            User::update((int) $target['id'], $fields);
        }
        Audit::log((string) Session::email(), 'user_update', (string) $target['email']);
        return ['ok' => true];
    }

    public function deactivateUser(array $payload, Request $request): array
    {
        $target = $this->requireUser($payload);
        $this->guardSelf($target, 'deactivate');
        if ((string) $target['role'] === 'admin' && $this->isLastActiveAdmin($target)) {
            throw new ValidationException('You cannot deactivate the last active admin.');
        }
        User::setActive((int) $target['id'], false);
        Session::terminateAllForUser((int) $target['id']); // §10.4
        Audit::log((string) Session::email(), 'user_deactivate', (string) $target['email']);
        return ['ok' => true];
    }

    public function activateUser(array $payload, Request $request): array
    {
        $target = $this->requireUser($payload);
        User::setActive((int) $target['id'], true);
        Audit::log((string) Session::email(), 'user_activate', (string) $target['email']);
        return ['ok' => true];
    }

    public function deleteUser(array $payload, Request $request): array
    {
        $target = $this->requireUser($payload);
        $this->guardSelf($target, 'delete');
        if ((string) $target['role'] === 'admin' && $this->isLastActiveAdmin($target)) {
            throw new ValidationException('You cannot delete the last active admin.');
        }
        Session::terminateAllForUser((int) $target['id']);
        User::delete((int) $target['id']);
        Audit::log((string) Session::email(), 'user_delete', (string) $target['email']);
        return ['ok' => true];
    }

    public function adminResetPassword(array $payload, Request $request): array
    {
        $target = $this->requireUser($payload);
        $password = (string) ($payload['password'] ?? '');
        $policyError = PasswordPolicy::validate($password);
        if ($policyError !== null) {
            throw new ValidationException($policyError);
        }
        User::setPasswordByAdmin((int) $target['id'], PasswordPolicy::hash($password));
        Session::terminateAllForUser((int) $target['id']); // §10.4
        Audit::log((string) Session::email(), 'user_pw_reset', (string) $target['email']);
        return ['ok' => true];
    }

    // ── SLA targets ──────────────────────────────────────────────────────────
    public function updateSlaTargets(array $payload, Request $request): array
    {
        foreach (['urgent', 'high', 'normal', 'low'] as $tier) {
            $resp = (int) ($payload["sla_response_{$tier}"] ?? 0);
            $reso = (int) ($payload["sla_resolution_{$tier}"] ?? 0);
            if ($resp <= 0 || $reso <= 0) {
                throw new ValidationException("SLA minutes for '{$tier}' must be positive.");
            }
            if ($reso < $resp) {
                throw new ValidationException("Resolution time for '{$tier}' must be ≥ response time.");
            }
        }
        foreach (['urgent', 'high', 'normal', 'low'] as $tier) {
            AppConfig::set("sla_response_{$tier}", (string) (int) $payload["sla_response_{$tier}"]);
            AppConfig::set("sla_resolution_{$tier}", (string) (int) $payload["sla_resolution_{$tier}"]);
        }
        Audit::log((string) Session::email(), 'sla_update');
        // sanity: prove the calculator can read the new tiers
        SlaCalculator::deadlines('urgent', gmdate('Y-m-d H:i:s'));
        return ['ok' => true];
    }

    // ── config allowlist ─────────────────────────────────────────────────────
    public function updateConfig(array $payload, Request $request): array
    {
        $updates = [];
        foreach (self::CONFIG_ALLOWLIST as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $value = (string) $payload[$key];
            if ($key === 'brand_color' && !preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                throw new ValidationException('Brand colour must be a hex value like #4057F5.');
            }
            if ($key === 'support_email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new ValidationException('Support email must be a valid email address.');
            }
            if ($key === 'ticket_prefix' && !preg_match('/^[A-Z]{2,6}$/', $value)) {
                throw new ValidationException('Ticket prefix must be 2–6 uppercase letters.');
            }
            if ($key === 'require_admin_mfa') {
                $value = ($value !== '' && $value !== '0') ? '1' : '0'; // normalise to a strict flag
            }
            $updates[$key] = mb_substr($value, 0, 255);
        }
        // Keys NOT in the allowlist are silently ignored — never mass-assigned (§10.5).
        foreach ($updates as $k => $v) {
            AppConfig::set($k, $v);
        }
        Audit::log((string) Session::email(), 'config_update', '', implode(',', array_keys($updates)));
        return ['ok' => true, 'updated' => array_keys($updates)];
    }

    // ── backup + reset ───────────────────────────────────────────────────────
    public function runBackup(array $payload, Request $request): array
    {
        try {
            $path = BackupService::create();
        } catch (\Throwable $e) {
            throw new ValidationException('Backup failed: ' . $e->getMessage());
        }
        Audit::log((string) Session::email(), 'backup_run', '', basename($path));
        return ['ok' => true, 'file' => basename($path)];
    }

    public function resetTicketData(array $payload, Request $request): array
    {
        $result = ResetService::reset(
            (string) ($payload['confirm'] ?? ''),
            (string) Session::email(),
            $request->ip()
        );
        if (($result['ok'] ?? false) !== true) {
            throw new ValidationException((string) ($result['error'] ?? 'Reset failed.'));
        }
        return ['ok' => true, 'backup' => $result['backup'], 'deleted' => $result['deleted']];
    }

    // ── helpers ──────────────────────────────────────────────────────────────
    private function requireUser(array $payload): array
    {
        $id = (int) ($payload['id'] ?? 0);
        $user = $id > 0 ? User::findById($id) : null;
        if ($user === null) {
            throw new ValidationException('User not found.');
        }
        return $user;
    }

    private function guardSelf(array $target, string $verb): void
    {
        if ((int) $target['id'] === (int) Session::userId()) {
            throw new ValidationException("You cannot {$verb} your own account.");
        }
    }

    private function isLastActiveAdmin(array $target): bool
    {
        return (string) $target['role'] === 'admin'
            && (int) $target['active'] === 1
            && User::activeAdminCount() <= 1;
    }
}
