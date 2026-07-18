<?php
declare(strict_types=1);

namespace App\Core;

use App\Security\Audit;
use App\Security\RateLimit;
use App\Security\Rbac;
use Throwable;

/**
 * Dispatch (§9) — THE action gateway. Every state-changing and data operation goes
 * through POST /api and this one method, so auth/CSRF/rate-limit/authz cannot be
 * forgotten. Five gates run in a FIXED order; no handler runs until all pass:
 *
 *   1. Action allowlist   — unknown action → generic error, logged. Never dynamic dispatch.
 *   2. Rate limit         — coarse per-IP cap for public actions (finer limits live in handlers).
 *   3. CSRF               — stateless HMAC for PUBLIC_ACTIONS; session token otherwise (D6).
 *   4. Authentication     — non-public requires a session; admin requires mfa_verified=1.
 *   5. Authorization      — the handler's REQUIRES entry is enforced HERE, from a static map.
 *
 * Handlers return an array; the gateway JSON-encodes it. A global try/catch logs the
 * exception with the request id and returns a generic error — never the message.
 *
 * Authorization is DATA, not discipline: a handler with no REQUIRES entry and not in
 * PUBLIC_ACTIONS is unreachable (default deny). The StaticSuite asserts no orphans.
 */
final class Dispatch
{
    /** Public actions: no session required; validated with the stateless HMAC CSRF token. */
    public const PUBLIC_ACTIONS = [
        'getPortalData',
        'requestPasswordReset',
    ];

    /** Authorization requirements for authenticated actions (§9). 'owner' is per-record (Phase 6). */
    public const REQUIRES = [
        'getMe'           => 'auth',
        'getSystemConfig' => 'admin',
    ];

    private const GENERIC_ERROR = 'The request could not be completed.';

    public static function handle(Request $request): Response
    {
        $body = $request->body();
        $action = is_string($body['action'] ?? null) ? $body['action'] : '';
        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
        $csrf = is_string($body['csrf'] ?? null) ? $body['csrf'] : '';

        $handlers = self::handlers();
        $isPublic = in_array($action, self::PUBLIC_ACTIONS, true);

        // ── Gate 1: allowlist ──
        if ($action === '' || !isset($handlers[$action])) {
            Logger::security('api_unknown_action', 'action=' . substr($action, 0, 60));
            return self::error('Unknown action.', 400);
        }

        // ── Gate 2: rate limit (coarse, per-IP, for public/unauthenticated calls) ──
        if ($isPublic || Session::current() === null) {
            $ipKey = 'api:' . $request->ip();
            if (RateLimit::recordHit('SUBMITGLOBAL', $ipKey, 60) > 300) {
                return self::error('Too many requests. Please slow down.', 429);
            }
        }

        // ── Gate 3: CSRF ──
        $csrfOk = $isPublic ? Csrf::validatePublic($csrf, $action) : Csrf::validate($csrf);
        if (!$csrfOk) {
            Logger::security('api_csrf_fail', "action={$action}");
            return self::error('Your session token was missing or invalid. Refresh and try again.', 419);
        }

        // ── Gate 4: authentication (+ MFA gate for admins) ──
        if (!$isPublic) {
            if (Session::current() === null) {
                return self::error('Authentication required.', 401);
            }
            if (Session::role() === 'admin' && !Session::isMfaVerified()) {
                return self::error('Multi-factor verification required.', 403, ['mfa_required' => true]);
            }
        }

        // ── Gate 5: authorization ──
        if (!$isPublic) {
            $requirement = self::REQUIRES[$action] ?? null;
            // 'owner' is enforced inside the handler against the specific record (Phase 6).
            if ($requirement === null || ($requirement !== 'owner' && !Rbac::satisfies($requirement))) {
                Logger::security('api_authz_denied', "action={$action} role=" . (Session::role() ?? '-'));
                Audit::log((string) (Session::email() ?? '-'), 'authz_denied', $action, '', $request->ip());
                return self::error('You are not allowed to do that.', 403);
            }
        }

        // ── Dispatch ──
        try {
            $result = $handlers[$action]($payload, $request);
            return Response::json(['ok' => true, 'data' => $result]);
        } catch (Throwable $e) {
            $rid = Logger::requestId();
            Logger::error('api_handler_exception', sprintf('%s: %s @ %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
            return self::error(self::GENERIC_ERROR, 500, ['request_id' => $rid]);
        }
    }

    /** Action names that have a handler (used by the StaticSuite orphan check). */
    public static function actionNames(): array
    {
        return array_keys(self::handlers());
    }

    /**
     * The handler registry: action => fn(array $payload, Request $request): array.
     * Later phases add their actions here (tickets, admin, KB, notifications, …),
     * each with a matching REQUIRES entry or PUBLIC_ACTIONS membership.
     *
     * @return array<string, callable>
     */
    private static function handlers(): array
    {
        $actions = new \App\Controllers\ApiActions();
        return [
            'getPortalData'        => [$actions, 'getPortalData'],
            'requestPasswordReset' => [$actions, 'requestPasswordReset'],
            'getMe'                => [$actions, 'getMe'],
            'getSystemConfig'      => [$actions, 'getSystemConfig'],
        ];
    }

    private static function error(string $message, int $status, array $extra = []): Response
    {
        return Response::json(['ok' => false, 'error' => $message] + $extra, $status);
    }
}
