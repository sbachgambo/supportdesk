<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;
use App\Models\AppConfig;
use App\Models\Ticket;

/**
 * InboundMail (§10.17, §13, D3) — turns an inbound email into a threaded reply or a
 * new ticket. The IMAP fetch/parse lives in the cron; this holds the (testable) rule:
 *
 *   THE SENDER-MATCH RULE: a message whose subject contains a ticket id threads onto
 *   that ticket ONLY IF the sender address matches the ticket's customer_email. Any
 *   mismatch creates a NEW ticket instead — never threads. Without this, anyone who
 *   can guess TKT-2026-0001 could post into a stranger's ticket. This is a security
 *   control, not a convenience; mismatches are logged to security.log.
 *
 * Machine senders (mailer-daemon@ / no-reply@ / postmaster@) and our own address are
 * skipped to avoid mail loops. Inbound is untrusted: subject/body are truncated and
 * flow through the same prepared-statement / escaping paths as any other input.
 */
final class InboundMail
{
    public const RESULT_THREADED = 'threaded';
    public const RESULT_CREATED = 'created';
    public const RESULT_MISMATCH_CREATED = 'mismatch_created';
    public const RESULT_SKIPPED = 'skipped';

    /**
     * @return array{result:string, ticket_id:?string}
     */
    public static function processMessage(string $fromEmail, string $fromName, string $subject, string $body): array
    {
        $fromEmail = strtolower(trim($fromEmail));
        $subject = trim($subject) === '' ? '(no subject)' : trim($subject);
        $body = trim($body) === '' ? '(no content)' : trim($body);

        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || self::isMachineSender($fromEmail)) {
            return ['result' => self::RESULT_SKIPPED, 'ticket_id' => null];
        }

        $ticketId = self::extractTicketId($subject);
        if ($ticketId !== null) {
            $ticket = Ticket::find($ticketId);
            if ($ticket !== null) {
                if (strcasecmp((string) $ticket['customer_email'], $fromEmail) === 0) {
                    // Sender matches → thread as a customer reply (reopens if resolved).
                    TicketService::customerReply($ticketId, $body, ['name' => $fromName, 'email' => $fromEmail]);
                    return ['result' => self::RESULT_THREADED, 'ticket_id' => $ticketId];
                }
                // Sender MISMATCH → never thread; create a new ticket and log it.
                Logger::security('inbound_sender_mismatch', "subject_ticket={$ticketId} from={$fromEmail}");
                $new = self::createFromEmail($fromEmail, $fromName, $subject, $body);
                return ['result' => self::RESULT_MISMATCH_CREATED, 'ticket_id' => $new];
            }
        }

        // No id in subject (or id not found) → a brand-new ticket.
        $new = self::createFromEmail($fromEmail, $fromName, $subject, $body);
        return ['result' => self::RESULT_CREATED, 'ticket_id' => $new];
    }

    private static function createFromEmail(string $email, string $name, string $subject, string $body): ?string
    {
        $result = TicketService::create([
            'subject'        => mb_substr($subject, 0, 200),
            'description'    => mb_substr($body, 0, 5000),
            'customer_name'  => mb_substr($name, 0, 120),
            'customer_email' => $email,
            'priority'       => 'normal',
        ], 'email');
        return ($result['ok'] ?? false) ? (string) $result['ticket']['ticket_id'] : null;
    }

    /** Extract a ticket id from the subject using the configured prefix. */
    public static function extractTicketId(string $subject): ?string
    {
        $prefix = preg_quote(AppConfig::get('ticket_prefix', 'TKT'), '/');
        if (preg_match('/' . $prefix . '-\d{4}-\d{4}/i', $subject, $m)) {
            return strtoupper($m[0]);
        }
        return null;
    }

    public static function isMachineSender(string $email): bool
    {
        $local = strtolower(substr($email, 0, (int) strpos($email . '@', '@')));
        if (in_array($local, ['mailer-daemon', 'no-reply', 'noreply', 'postmaster', 'do-not-reply', 'donotreply'], true)) {
            return true;
        }
        // never ingest our own outbound address (loop guard)
        return strcasecmp($email, Config::string('MAIL_FROM', '')) === 0
            || strcasecmp($email, Config::string('IMAP_USER', '')) === 0;
    }
}
