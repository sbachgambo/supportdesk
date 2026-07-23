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
use App\Services\Mailer;
use App\Services\PasswordReset;
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
        'require_admin_mfa', 'auto_close_days', 'sla_business_hours_only', 'slack_webhook_url',
    ];

    // ── users / agents CRUD ──────────────────────────────────────────────────
    public function listUsers(array $payload, Request $request): array
    {
        [$sysAdmin, $orgId] = $this->callerCtx();
        // Org admins see only their own organization's staff; system admins see all.
        return ['users' => $sysAdmin ? User::all() : User::allInOrg($orgId)];
    }

    public function createUser(array $payload, Request $request): array
    {
        [$sysAdmin, $callerOrg] = $this->callerCtx();

        $name = trim((string) ($payload['name'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $role = (string) ($payload['role'] ?? '');
        $password = (string) ($payload['password'] ?? '');
        $orgId = trim((string) ($payload['organization_id'] ?? ''));

        if ($name === '' || mb_strlen($name) > 120) {
            throw new ValidationException('Name is required (max 120 chars).');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            throw new ValidationException('A valid email is required.');
        }

        if ($sysAdmin) {
            if (!in_array($role, ['admin', 'org_admin', 'agent', 'customer'], true)) {
                throw new ValidationException('Invalid role.');
            }
            if ($orgId !== '' && Organization::find($orgId) === null) {
                throw new ValidationException('Selected organization does not exist.');
            }
        } else {
            // Org admins may only create AGENTS inside their OWN organization.
            if ($callerOrg === null) {
                throw new ValidationException('Your account is not linked to an organization.');
            }
            $role = 'agent';
            $orgId = $callerOrg;
        }

        if (User::findByEmail($email) !== null) {
            throw new ValidationException('A user with that email already exists.');
        }
        $policyError = PasswordPolicy::validate($password);
        if ($policyError !== null) {
            throw new ValidationException($policyError);
        }

        $id = User::create([
            'public_id'       => User::nextPublicId($role),
            'name'            => $name,
            'email'           => $email,
            'password_hash'   => PasswordPolicy::hash($password),
            'role'            => $role,
            'must_change_pw'  => true, // admin-set password → must change on first login
            'organization_id' => in_array($role, ['agent', 'org_admin'], true) && $orgId !== '' ? $orgId : null,
        ]);
        Audit::log((string) Session::email(), 'user_create', $email, "role={$role}");
        $this->sendWelcomeEmail($email, $name, $role);
        return ['id' => $id, 'public_id' => User::findById($id)['public_id']];
    }

    /**
     * Welcome email for a newly created staff member: a single-use "set your password"
     * link (the hardened reset-token flow — no plaintext password is ever emailed) plus
     * the sign-in URL. Mailer handles branding, suppression and pretend mode.
     */
    private function sendWelcomeEmail(string $email, string $name, string $role): void
    {
        $company = AppConfig::get('company_name', 'Support');
        $raw = PasswordReset::createToken($email, $name);
        $link = $this->h(\url('reset?token=' . $raw));
        $login = $this->h(\url('login'));
        $safeName = $this->h($name !== '' ? $name : 'there');
        $roleLabel = match ($role) {
            'admin'     => 'a System Administrator',
            'org_admin' => 'an Organization Administrator',
            'agent'     => 'an agent',
            default     => 'a user',
        };
        $body = "<p>Hi {$safeName},</p>"
            . '<p>An account has been created for you at <strong>' . $this->h($company) . '</strong> as '
            . $roleLabel . '. Your sign-in email is <strong>' . $this->h($email) . '</strong>.</p>'
            . '<p>Set your password to get started — this link is valid for 60 minutes and can be used once:</p>'
            . "<p><a href=\"{$link}\" style=\"display:inline-block;background:#4057F5;color:#fff;text-decoration:none;"
            . "padding:10px 18px;border-radius:8px;font-weight:700\">Set your password</a></p>"
            . "<p style=\"color:#6b7280;font-size:13px\">Then sign in at <a href=\"{$login}\">{$login}</a>. "
            . 'If the link expires, use “Forgot password?” on the sign-in page.</p>'
            . "<p style=\"color:#6b7280;font-size:12px;word-break:break-all\">{$link}</p>";
        Mailer::sendTemplate($email, 'Welcome to ' . $company, 'Your account is ready', $body);
    }

    private function h(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    public function updateUser(array $payload, Request $request): array
    {
        $target = $this->requireUser($payload);
        $this->requireManageable($target);
        [$sysAdmin] = $this->callerCtx();
        $fields = [];
        if (isset($payload['name'])) {
            $name = trim((string) $payload['name']);
            if ($name === '' || mb_strlen($name) > 120) {
                throw new ValidationException('Name must be 1–120 characters.');
            }
            $fields['name'] = $name;
        }
        if (isset($payload['email'])) {
            $email = strtolower(trim((string) $payload['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
                throw new ValidationException('A valid email is required.');
            }
            $existing = User::findByEmail($email);
            if ($existing !== null && (int) $existing['id'] !== (int) $target['id']) {
                throw new ValidationException('Another user already has that email.');
            }
            $fields['email'] = $email;
        }
        // Role + organization changes are system-admin only.
        if ($sysAdmin && isset($payload['role'])) {
            $role = (string) $payload['role'];
            if (!in_array($role, ['admin', 'org_admin', 'agent', 'customer'], true)) {
                throw new ValidationException('Invalid role.');
            }
            // Demoting the last active admin is a lockout — block it.
            if ((string) $target['role'] === 'admin' && $role !== 'admin' && $this->isLastActiveAdmin($target)) {
                throw new ValidationException('You cannot change the role of the last active admin.');
            }
            $fields['role'] = $role;
        }
        if ($sysAdmin && array_key_exists('organization_id', $payload)) {
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
        $this->requireManageable($target);
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
        $this->requireManageable($target);
        User::setActive((int) $target['id'], true);
        Audit::log((string) Session::email(), 'user_activate', (string) $target['email']);
        return ['ok' => true];
    }

    public function deleteUser(array $payload, Request $request): array
    {
        $target = $this->requireUser($payload);
        $this->requireManageable($target);
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
        $this->requireManageable($target);
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
            if ($key === 'require_admin_mfa' || $key === 'sla_business_hours_only') {
                $value = ($value !== '' && $value !== '0') ? '1' : '0'; // normalise to a strict flag
            }
            if ($key === 'auto_close_days') {
                if ($value !== '' && !preg_match('/^\d{1,3}$/', $value)) {
                    throw new ValidationException('Auto-close days must be a whole number (0 disables auto-close).');
                }
                $value = (string) (int) ($value === '' ? 7 : $value); // empty → default 7, never silently off
            }
            if (($key === 'business_hours_start' || $key === 'business_hours_end')
                && $value !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
                throw new ValidationException('Business hours must be HH:MM (24-hour), e.g. 08:00.');
            }
            if ($key === 'business_days' && $value !== '' && !preg_match('/^[1-7](,[1-7])*$/', $value)) {
                throw new ValidationException('Business days must be a comma list of 1–7 (Mon=1 … Sun=7).');
            }
            if ($key === 'slack_webhook_url' && $value !== ''
                && !(filter_var($value, FILTER_VALIDATE_URL) && stripos($value, 'https://') === 0)) {
                throw new ValidationException('Slack webhook must be an https:// URL (or leave it blank to disable).');
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

    // ── audit log viewer (§10.11) ────────────────────────────────────────────
    public function listAuditLog(array $payload, Request $request): array
    {
        $actor = mb_substr(trim((string) ($payload['actor'] ?? '')), 0, 254);
        $action = mb_substr(trim((string) ($payload['action'] ?? '')), 0, 60);
        $page = max(1, (int) ($payload['page'] ?? 1));
        $result = Audit::paged($actor, $action, $page, 25);
        $out = $result + ['page' => $page, 'per_page' => 25, 'actions' => Audit::actionNames()];
        // Chain verification is O(n) over the whole log — run only when asked.
        if (!empty($payload['verify'])) {
            $bad = Audit::verifyChain();
            $out['chain_ok'] = $bad === null;
            $out['chain_bad_id'] = $bad;
        }
        return $out;
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

    /** List backup files for the admin panel (download happens via /admin/backup/download). */
    public function listBackups(array $payload, Request $request): array
    {
        $files = [];
        foreach (glob(BackupService::backupDir() . '/backup_*') ?: [] as $path) {
            if (is_file($path)) {
                $files[] = [
                    'name'        => basename($path),
                    'size_bytes'  => (int) filesize($path),
                    'modified_at' => gmdate('Y-m-d H:i:s', (int) filemtime($path)),
                ];
            }
        }
        usort($files, static fn(array $a, array $b): int => strcmp($b['name'], $a['name'])); // newest first
        return ['backups' => $files];
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
    /** Caller context for user management: [bool $isSystemAdmin, ?string $orgId]. */
    private function callerCtx(): array
    {
        // Super admin manages everyone, exactly like a system admin.
        if (in_array((string) Session::role(), ['admin', 'super_admin'], true)) {
            return [true, null];
        }
        $me = User::findById((int) Session::userId());
        $org = (string) ($me['organization_id'] ?? '');
        return [false, $org === '' ? null : $org];
    }

    /**
     * Org-admin boundary: a system admin manages anyone; an org admin may only manage
     * AGENTS in their OWN organization (never other admins/org-admins or other orgs).
     */
    private function requireManageable(array $target): void
    {
        // The super admin is the protected owner: NOBODY can edit/deactivate/delete/
        // reset it through the app — not even a system admin (or another super admin).
        if ((string) $target['role'] === 'super_admin') {
            throw new ValidationException('This account is protected and cannot be managed.');
        }
        [$sysAdmin, $orgId] = $this->callerCtx();
        if ($sysAdmin) {
            return;
        }
        if ((string) $target['role'] !== 'agent'
            || (string) ($target['organization_id'] ?? '') !== (string) $orgId) {
            throw new ValidationException('You can only manage agents in your own organization.');
        }
    }

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
