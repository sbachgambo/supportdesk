<?php
declare(strict_types=1);

namespace App\Security;

use App\Core\Session;
use App\Models\Ticket;
use App\Models\User;

/**
 * Rbac (§10.5) — access-control primitives. DEFAULT DENY.
 *
 * The gateway (Phase 4) enforces each action's requirement from a static map, so a
 * handler cannot forget. These predicates are that enforcement's building blocks.
 * UI hiding is UX, never security — every action is re-authorized server-side.
 *
 * requireOwnership (customer scope over a specific ticket) is completed in Phase 6
 * when tickets exist; the role predicates below are final.
 */
final class Rbac
{
    public static function isAuthenticated(): bool
    {
        return Session::current() !== null;
    }

    public static function role(): ?string
    {
        return Session::role();
    }

    /** System Super Admin — the protected, hidden owner. Above System Admin in every check. */
    public static function isSuperAdmin(): bool
    {
        return Session::role() === 'super_admin';
    }

    /**
     * System Admin tier — a full, cross-organization administrator. The Super Admin
     * satisfies this everywhere (it is strictly above System Admin), so all admin-gated
     * authz, org scope, and MFA logic apply to it too.
     */
    public static function isAdmin(): bool
    {
        return in_array(Session::role(), ['admin', 'super_admin'], true);
    }

    /** Organization Admin — scoped to their own organization's data + agents. */
    public static function isOrgAdmin(): bool
    {
        return Session::role() === 'org_admin';
    }

    /** org_admin and admin (+ super admin) — the "manage users" tier. */
    public static function isAtLeastOrgAdmin(): bool
    {
        return in_array(Session::role(), ['org_admin', 'admin', 'super_admin'], true);
    }

    /** Staff — agent, org_admin, admin, super_admin. Reads are org-scoped for non-admins. */
    public static function isAtLeastAgent(): bool
    {
        return in_array(Session::role(), ['agent', 'org_admin', 'admin', 'super_admin'], true);
    }

    public static function isCustomer(): bool
    {
        return Session::role() === 'customer';
    }

    /**
     * Evaluate a requirement token as used in the gateway's REQUIRES map (§9):
     *   'auth'      — any authenticated session
     *   'agent'     — agent, org_admin or admin
     *   'org_admin' — org_admin or admin (org-scoped user management)
     *   'admin'     — system admin only
     *   'customer'  — customer only
     * ('owner' is record-scoped — see ownsTicket() / the gateway's owner handling.)
     */
    public static function satisfies(string $requirement): bool
    {
        return match ($requirement) {
            'auth'      => self::isAuthenticated(),
            'agent'     => self::isAtLeastAgent(),
            'org_admin' => self::isAtLeastOrgAdmin(),
            'admin'     => self::isAdmin(),
            'customer'  => self::isCustomer(),
            default     => false, // unknown requirement → deny
        };
    }

    /**
     * Ownership check (D2, §10.5) for the current session over a ticket.
     * Staff access is ORG-SCOPED (multi-tenancy): a system admin covers every
     * organization; an agent or org admin only "owns" tickets inside their own
     * organization (org-less staff cover the general/NULL queue). This is the gate
     * for uploads, downloads and the 'owner'-gated actions, so the tenant boundary
     * holds on those paths too — not just the ticket lists. A customer owns a ticket
     * when it is linked to their user id, OR the ticket's customer_email matches
     * their account email (covers tickets raised anonymously before their account
     * existed).
     */
    public static function ownsTicket(string $ticketId): bool
    {
        if (self::isAdmin()) {
            return true;
        }
        if (self::isAtLeastAgent()) {
            $me = User::findById((int) Session::userId());
            $org = (string) ($me['organization_id'] ?? '');
            return Ticket::inScope($ticketId, false, $org === '' ? null : $org);
        }
        if (!self::isAuthenticated()) {
            return false;
        }
        $ticket = Ticket::find($ticketId);
        if ($ticket === null) {
            return false;
        }
        $uid = Session::userId();
        $email = Session::email();
        if ($uid !== null && $ticket['customer_user_id'] !== null && (int) $ticket['customer_user_id'] === $uid) {
            return true;
        }
        return $email !== null && strcasecmp((string) $ticket['customer_email'], $email) === 0;
    }
}
