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
        'submitTicket',
        'checkTicketStatus',
        'getPublicKb',
    ];

    /** Authorization requirements for authenticated actions (§9). 'owner' is per-record (Phase 6). */
    public const REQUIRES = [
        'getMe'           => 'auth',
        'getSystemConfig' => 'admin',
        // MFA (Phase 12) — all authed; pre-verify ones are also in MFA_PREVERIFY_ACTIONS.
        'getMfaStatus' => 'auth',
        'enrollTotp'   => 'auth',
        'confirmTotp'  => 'auth',
        'verifyMfa'    => 'auth',
        'disableTotp'  => 'auth',
        // Tickets (Phase 5) — staff only. Customer/owner read paths arrive in Phase 6.
        'getDashboardData'    => 'agent',
        'createTicket'        => 'agent',
        'getTickets'          => 'agent',
        'getTicket'           => 'agent',
        'sendReply'           => 'agent',
        'addInternalNote'     => 'agent',
        'changeStatus'        => 'agent',
        'changePriority'      => 'agent',
        'assignTicket'        => 'agent',
        'resolveTicket'       => 'agent',
        'reopenTicket'        => 'agent',
        'getCannedResponses'  => 'agent',
        'applyCannedResponse' => 'agent',
        // Reports (Phase 8) — staff.
        'getReports'          => 'agent',
        'getAgentPerformance' => 'agent',
        // Customer portal (Phase 6) — ownership-scoped.
        'getMyTickets'    => 'customer',
        'getMyTicket'     => 'owner',
        'replyToMyTicket' => 'owner',
        'submitCsat'      => 'owner',
        // Category admin (Phase 6).
        'listCategories'  => 'admin',
        'createCategory'  => 'admin',
        'updateCategory'  => 'admin',
        'deleteCategory'  => 'admin',
        // Admin panel (Phase 7).
        'listUsers'          => 'admin',
        'createUser'         => 'admin',
        'updateUser'         => 'admin',
        'deactivateUser'     => 'admin',
        'activateUser'       => 'admin',
        'deleteUser'         => 'admin',
        'adminResetPassword' => 'admin',
        'updateSlaTargets'   => 'admin',
        'updateConfig'       => 'admin',
        'runBackup'          => 'admin',
        'resetTicketData'    => 'admin',
        'listRules'          => 'admin',
        'createRule'         => 'admin',
        'updateRule'         => 'admin',
        'toggleRule'         => 'admin',
        'deleteRule'         => 'admin',
        // Modules (Phase 11).
        'getKbArticles'          => 'agent',
        'getKbArticle'           => 'agent',
        'publishKbArticle'       => 'agent',
        'editKbArticle'          => 'agent',
        'deleteKbArticle'        => 'admin',   // KB delete is admin-only (§3)
        'getNotifications'       => 'agent',
        'getUnreadCount'         => 'agent',
        'markNotificationRead'   => 'agent',
        'markAllNotificationsRead' => 'agent',
        'manageCannedResponses'  => 'agent',
        'createCannedResponse'   => 'agent',
        'updateCannedResponse'   => 'agent',
        'deleteCannedResponse'   => 'agent',
    ];

    /** Actions reachable by an authenticated-but-not-yet-MFA-verified session (D8). */
    private const MFA_PREVERIFY_ACTIONS = ['getMfaStatus', 'enrollTotp', 'confirmTotp', 'verifyMfa', 'getMe'];

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

        // ── Gate 4: authentication (+ MFA gate) ──
        if (!$isPublic) {
            if (Session::current() === null) {
                return self::error('Authentication required.', 401);
            }
            // A session created for an MFA-requiring user starts unverified: it can do
            // NOTHING except the MFA enrol/verify actions until it is verified (D8).
            if (!Session::isMfaVerified() && !in_array($action, self::MFA_PREVERIFY_ACTIONS, true)) {
                return self::error('Multi-factor verification required.', 403, ['mfa_required' => true]);
            }
        }

        // ── Gate 5: authorization ──
        if (!$isPublic) {
            $requirement = self::REQUIRES[$action] ?? null;
            $allowed = false;
            if ($requirement === 'owner') {
                // Record-scoped (D2): staff bypass by role; a customer must own the
                // ticket named in the payload. Enforced HERE, not left to the handler.
                $ticketId = is_string($payload['ticket_id'] ?? null) ? $payload['ticket_id'] : '';
                $allowed = Rbac::ownsTicket($ticketId);
            } elseif ($requirement !== null) {
                $allowed = Rbac::satisfies($requirement);
            }
            if (!$allowed) {
                Logger::security('api_authz_denied', "action={$action} role=" . (Session::role() ?? '-'));
                Audit::log((string) (Session::email() ?? '-'), 'authz_denied', $action, '', $request->ip());
                return self::error('You are not allowed to do that.', 403);
            }
        }

        // ── Dispatch ──
        try {
            $result = $handlers[$action]($payload, $request);
            return Response::json(['ok' => true, 'data' => $result]);
        } catch (ValidationException $e) {
            // Safe, user-facing business error (not an unexpected failure).
            return self::error($e->getMessage(), 422);
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
        $tickets = new \App\Controllers\TicketActions();
        $customer = new \App\Controllers\CustomerActions();
        $categories = new \App\Controllers\CategoryActions();
        $admin = new \App\Controllers\AdminActions();
        $rules = new \App\Controllers\RuleActions();
        $reports = new \App\Controllers\ReportActions();
        $public = new \App\Controllers\PublicActions();
        $kb = new \App\Controllers\KbActions();
        $notifs = new \App\Controllers\NotificationActions();
        $canned = new \App\Controllers\CannedResponseActions();
        $mfa = new \App\Controllers\MfaActions();
        return [
            'getPortalData'        => [$actions, 'getPortalData'],
            'requestPasswordReset' => [$actions, 'requestPasswordReset'],
            'getMfaStatus'         => [$mfa, 'getMfaStatus'],
            'enrollTotp'           => [$mfa, 'enrollTotp'],
            'confirmTotp'          => [$mfa, 'confirmTotp'],
            'verifyMfa'            => [$mfa, 'verifyMfa'],
            'disableTotp'          => [$mfa, 'disableTotp'],
            'submitTicket'         => [$public, 'submitTicket'],
            'checkTicketStatus'    => [$public, 'checkTicketStatus'],
            'getPublicKb'          => [$kb, 'getPublicKb'],
            'getMe'                => [$actions, 'getMe'],
            'getSystemConfig'      => [$actions, 'getSystemConfig'],
            // Tickets (Phase 5)
            'getDashboardData'     => [$tickets, 'getDashboardData'],
            'createTicket'         => [$tickets, 'createTicket'],
            'getTickets'           => [$tickets, 'getTickets'],
            'getTicket'            => [$tickets, 'getTicket'],
            'sendReply'            => [$tickets, 'sendReply'],
            'addInternalNote'      => [$tickets, 'addInternalNote'],
            'changeStatus'         => [$tickets, 'changeStatus'],
            'changePriority'       => [$tickets, 'changePriority'],
            'assignTicket'         => [$tickets, 'assignTicket'],
            'resolveTicket'        => [$tickets, 'resolveTicket'],
            'reopenTicket'         => [$tickets, 'reopenTicket'],
            'getCannedResponses'   => [$tickets, 'getCannedResponses'],
            'applyCannedResponse'  => [$tickets, 'applyCannedResponse'],
            // Reports (Phase 8)
            'getReports'           => [$reports, 'getReports'],
            'getAgentPerformance'  => [$reports, 'getAgentPerformance'],
            // Customer portal (Phase 6)
            'getMyTickets'         => [$customer, 'getMyTickets'],
            'getMyTicket'          => [$customer, 'getMyTicket'],
            'replyToMyTicket'      => [$customer, 'replyToMyTicket'],
            'submitCsat'           => [$customer, 'submitCsat'],
            // Category admin (Phase 6)
            'listCategories'       => [$categories, 'listCategories'],
            'createCategory'       => [$categories, 'createCategory'],
            'updateCategory'       => [$categories, 'updateCategory'],
            'deleteCategory'       => [$categories, 'deleteCategory'],
            // Admin panel (Phase 7)
            'listUsers'            => [$admin, 'listUsers'],
            'createUser'           => [$admin, 'createUser'],
            'updateUser'           => [$admin, 'updateUser'],
            'deactivateUser'       => [$admin, 'deactivateUser'],
            'activateUser'         => [$admin, 'activateUser'],
            'deleteUser'           => [$admin, 'deleteUser'],
            'adminResetPassword'   => [$admin, 'adminResetPassword'],
            'updateSlaTargets'     => [$admin, 'updateSlaTargets'],
            'updateConfig'         => [$admin, 'updateConfig'],
            'runBackup'            => [$admin, 'runBackup'],
            'resetTicketData'      => [$admin, 'resetTicketData'],
            'listRules'            => [$rules, 'listRules'],
            'createRule'           => [$rules, 'createRule'],
            'updateRule'           => [$rules, 'updateRule'],
            'toggleRule'           => [$rules, 'toggleRule'],
            'deleteRule'           => [$rules, 'deleteRule'],
            // Modules (Phase 11)
            'getKbArticles'        => [$kb, 'getKbArticles'],
            'getKbArticle'         => [$kb, 'getKbArticle'],
            'publishKbArticle'     => [$kb, 'publishKbArticle'],
            'editKbArticle'        => [$kb, 'editKbArticle'],
            'deleteKbArticle'      => [$kb, 'deleteKbArticle'],
            'getNotifications'         => [$notifs, 'getNotifications'],
            'getUnreadCount'           => [$notifs, 'getUnreadCount'],
            'markNotificationRead'     => [$notifs, 'markNotificationRead'],
            'markAllNotificationsRead' => [$notifs, 'markAllNotificationsRead'],
            'manageCannedResponses' => [$canned, 'manageCannedResponses'],
            'createCannedResponse'  => [$canned, 'createCannedResponse'],
            'updateCannedResponse'  => [$canned, 'updateCannedResponse'],
            'deleteCannedResponse'  => [$canned, 'deleteCannedResponse'],
        ];
    }

    private static function error(string $message, int $status, array $extra = []): Response
    {
        return Response::json(['ok' => false, 'error' => $message] + $extra, $status);
    }
}
