<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;
use App\Core\ValidationException;
use App\Models\AppConfig;
use App\Models\Category;
use App\Models\User;
use App\Security\Audit;
use App\Security\PasswordPolicy;
use App\Services\PasswordReset;

/**
 * ApiActions — Phase 4 handlers reachable through the Dispatch gateway (§9).
 *
 * Each returns a plain array; the gateway wraps it as {ok, data} and JSON-encodes.
 * Handlers receive already-validated context: the gateway has run allowlist, rate
 * limit, CSRF, authentication (+ MFA), and authorization before any of these run.
 * Later phases add their own action controllers alongside this one.
 */
final class ApiActions
{
    /** Public: branding + active categories for the portal/submit shells. */
    public function getPortalData(array $payload, Request $request): array
    {
        $cfg = AppConfig::some([
            'company_name', 'portal_title', 'portal_tagline', 'brand_color', 'support_email',
        ]);
        return [
            'branding'   => $cfg,
            'categories' => array_map(static fn(array $c): array => [
                'id'       => $c['category_id'],
                'name'     => $c['name'],
                'color'    => $c['color'],
                'parent'   => $c['parent_id'],
            ], Category::allActive()),
        ];
    }

    /** Public: request a password-reset link. Enumeration-safe; always the same result. */
    public function requestPasswordReset(array $payload, Request $request): array
    {
        $email = is_string($payload['email'] ?? null) ? $payload['email'] : '';
        PasswordReset::request($email, $request->ip());
        return ['message' => 'If that email belongs to an active account, a reset link is on its way.'];
    }

    /** Auth: the current signed-in user's own profile. */
    public function getMe(array $payload, Request $request): array
    {
        $user = User::findById((int) Session::userId());
        if ($user === null) {
            return ['authenticated' => false];
        }
        return [
            'authenticated' => true,
            'public_id'     => $user['public_id'],
            'name'          => $user['name'],
            'email'         => $user['email'],
            'role'          => $user['role'],
            'must_change_pw' => (int) $user['must_change_pw'] === 1,
        ];
    }

    /** Admin: the config allowlist values (branding + operational settings). */
    public function getSystemConfig(array $payload, Request $request): array
    {
        return ['config' => AppConfig::all()];
    }

    /**
     * Auth: self-service password change (§10.3, §10.4). Requires the current password,
     * enforces the full policy on the new one, forbids reuse of the current password,
     * clears must_change_pw, and signs out all OTHER sessions (this one is kept).
     */
    public function changeMyPassword(array $payload, Request $request): array
    {
        $user = User::findById((int) Session::userId());
        if ($user === null) {
            throw new ValidationException('Your session could not be found. Please sign in again.');
        }
        $current = (string) ($payload['current_password'] ?? '');
        $new = (string) ($payload['new_password'] ?? '');

        if (!PasswordPolicy::verify($current, (string) $user['password_hash'])) {
            Audit::log((string) $user['email'], 'password_change_fail', '', 'wrong current password', $request->ip());
            throw new ValidationException('Your current password is incorrect.');
        }
        $policyError = PasswordPolicy::validate($new);
        if ($policyError !== null) {
            throw new ValidationException($policyError);
        }
        if (PasswordPolicy::verify($new, (string) $user['password_hash'])) {
            throw new ValidationException('Please choose a password different from your current one.');
        }

        User::updatePasswordHash((int) $user['id'], PasswordPolicy::hash($new), true);
        // Keep THIS session; terminate every other session for this user (§10.4).
        $keep = Session::currentTokenHash();
        if ($keep !== null) {
            Session::terminateOthersForUser((int) $user['id'], $keep);
        }
        Audit::log((string) $user['email'], 'password_change', '', 'self-service', $request->ip());
        return ['ok' => true];
    }
}
