<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;
use App\Models\AppConfig;
use App\Models\Category;
use App\Models\User;
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
}
