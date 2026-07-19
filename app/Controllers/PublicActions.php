<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Logger;
use App\Core\Request;
use App\Core\ValidationException;
use App\Models\Ticket;
use App\Security\MessageVisibility;
use App\Security\RateLimit;
use App\Services\TicketService;

/**
 * PublicActions — the unauthenticated surfaces reached through the gateway with the
 * stateless HMAC CSRF token (D6): submitTicket and checkTicketStatus.
 *
 * submitTicket (§3): honeypot, rate limits (5/hr/email + 30/hr global), priority
 * ceiling (customers may not select 'urgent'), input length caps, category validated
 * against the DB.
 *
 * checkTicketStatus (§3, threat model): ticket id AND matching email BOTH required;
 * wrong-email and unknown-id return BYTE-IDENTICAL generic errors; internal notes and
 * system messages are never exposed (MessageVisibility 'anonymous'); 20/hr/email.
 */
final class PublicActions
{
    /** One generic lookup failure — identical for wrong email and unknown id. */
    public const STATUS_GENERIC_ERROR = 'No ticket matches that ID and email.';

    public function submitTicket(array $payload, Request $request): array
    {
        // Honeypot: a hidden field bots fill in. If present, pretend success and drop it.
        if (trim((string) ($payload['website'] ?? '')) !== '') {
            Logger::security('submit_honeypot', 'ip=' . $request->ip());
            return ['ticket_id' => null, 'message' => 'Thank you — your request has been received.'];
        }

        $email = strtolower(trim((string) ($payload['customer_email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('A valid email address is required.');
        }

        // Rate limits: per-email and a global ceiling (§3, §10.6).
        $perEmail = Config::int('RATE_SUBMIT_PER_HOUR', 5);
        $global = Config::int('RATE_SUBMIT_GLOBAL_PER_HOUR', 30);
        if (RateLimit::exceeded('SUBMIT', $email, $perEmail) || RateLimit::exceeded('SUBMITGLOBAL', 'all', $global)) {
            throw new ValidationException('Too many submissions right now. Please try again later.');
        }

        // Priority ceiling: the public may not raise a ticket to 'urgent'.
        $priority = (string) ($payload['priority'] ?? 'normal');
        if (!in_array($priority, ['high', 'normal', 'low'], true)) {
            $priority = 'normal';
        }

        $result = TicketService::create([
            'subject'          => mb_substr((string) ($payload['subject'] ?? ''), 0, 200),
            'description'      => mb_substr((string) ($payload['description'] ?? ''), 0, 5000),
            'customer_name'    => mb_substr((string) ($payload['customer_name'] ?? ''), 0, 120),
            'customer_email'   => $email,
            'priority'         => $priority,
            'category_id'      => (string) ($payload['category_id'] ?? ''),
        ], (string) ($payload['_channel'] ?? 'web_form'));

        if (($result['ok'] ?? false) !== true) {
            throw new ValidationException((string) $result['error']);
        }

        // Count the successful submission against both buckets.
        RateLimit::recordHit('SUBMIT', $email, 3600);
        RateLimit::recordHit('SUBMITGLOBAL', 'all', 3600);

        return [
            'ticket_id' => $result['ticket']['ticket_id'],
            'message'   => 'Thank you — your request has been received.',
        ];
    }

    public function checkTicketStatus(array $payload, Request $request): array
    {
        $ticketId = trim((string) ($payload['ticket_id'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));

        // Both required; a missing field is the same generic failure (no hints).
        if ($ticketId === '' || $email === '') {
            throw new ValidationException(self::STATUS_GENERIC_ERROR);
        }

        $limit = Config::int('RATE_STATUS_PER_HOUR', 20);
        if (RateLimit::exceeded('STATUS', $email, $limit)) {
            throw new ValidationException('Too many lookups. Please try again later.');
        }
        RateLimit::recordHit('STATUS', $email, 3600);

        $ticket = Ticket::find($ticketId);
        // Wrong email and unknown id must be indistinguishable — same BYTE-IDENTICAL error.
        if ($ticket === null || strcasecmp((string) $ticket['customer_email'], $email) !== 0) {
            Logger::security('status_lookup_miss', "id={$ticketId}");
            throw new ValidationException(self::STATUS_GENERIC_ERROR);
        }

        // Anonymous visibility — internal notes / system messages never exposed (D2).
        return [
            'ticket' => [
                'ticket_id'   => $ticket['ticket_id'],
                'subject'     => $ticket['subject'],
                'status'      => $ticket['status'],
                'priority'    => $ticket['priority'],
                'created_at'  => $ticket['created_at'],
                'resolved_at' => $ticket['resolved_at'],
            ],
            'messages' => MessageVisibility::for('anonymous', $ticketId),
        ];
    }
}
