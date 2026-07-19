<?php
declare(strict_types=1);

namespace App\Security;

use App\Core\Session;
use App\Models\Ticket;

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

    public static function isAdmin(): bool
    {
        return Session::role() === 'admin';
    }

    /** Agents and admins are "staff" — admin is a superset of agent for reads. */
    public static function isAtLeastAgent(): bool
    {
        return in_array(Session::role(), ['agent', 'admin'], true);
    }

    public static function isCustomer(): bool
    {
        return Session::role() === 'customer';
    }

    /**
     * Evaluate a requirement token as used in the gateway's REQUIRES map (§9):
     *   'auth'     — any authenticated session
     *   'agent'    — agent or admin
     *   'admin'    — admin only
     *   'customer' — customer only
     * ('owner' is record-scoped — see ownsTicket() / the gateway's owner handling.)
     */
    public static function satisfies(string $requirement): bool
    {
        return match ($requirement) {
            'auth'     => self::isAuthenticated(),
            'agent'    => self::isAtLeastAgent(),
            'admin'    => self::isAdmin(),
            'customer' => self::isCustomer(),
            default    => false, // unknown requirement → deny
        };
    }

    /**
     * Ownership check (D2, §10.5) for the current session over a ticket.
     * Staff (agent/admin) bypass by role. A customer owns a ticket when it is linked
     * to their user id, OR the ticket's customer_email matches their account email
     * (covers tickets raised anonymously before their account existed).
     */
    public static function ownsTicket(string $ticketId): bool
    {
        if (self::isAtLeastAgent()) {
            return true;
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
