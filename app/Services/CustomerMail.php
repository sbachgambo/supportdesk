<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\AppConfig;

/**
 * CustomerMail (§3) — the customer-facing ticket emails. All sends go through
 * Mailer (§10.16), so recipient validation, header-injection rejection, demo-domain
 * suppression, pretend mode, and mail_log recording all apply automatically. A mail
 * failure never breaks the ticket flow — Mailer reports status, it does not throw.
 *
 * Three moments:
 *   ticketCreated — "we received your request" + the reference id (submission receipt)
 *   agentReplied  — "our team replied" + a snippet (never internal notes)
 *   csatRequest   — "how did we do?" after auto-close (only when unrated)
 *
 * All ticket-derived text is HTML-escaped before templating (customer content must
 * never become markup in an email body).
 */
final class CustomerMail
{
    public static function ticketCreated(array $ticket): void
    {
        $to = (string) ($ticket['customer_email'] ?? '');
        $tid = (string) ($ticket['ticket_id'] ?? '');
        if ($to === '' || $tid === '') {
            return;
        }
        $name = self::e(self::firstName($ticket));
        $subjectLine = self::e((string) ($ticket['subject'] ?? ''));
        $url = self::statusUrl();
        $body = "<p>Hello {$name},</p>"
            . "<p>We've received your request <strong>&ldquo;{$subjectLine}&rdquo;</strong> and our team is on it.</p>"
            . "<p>Your reference number is:</p>"
            . "<p style=\"font-size:20px;font-weight:700;letter-spacing:1px\">" . self::e($tid) . "</p>"
            . "<p>You can check progress any time at <a href=\"{$url}\">{$url}</a> using this reference "
            . "number and the email address you submitted with.</p>";
        Mailer::sendTemplate($to, "We received your request ({$tid})", 'Request received', $body);
    }

    public static function agentReplied(array $ticket, string $replyText): void
    {
        $to = (string) ($ticket['customer_email'] ?? '');
        $tid = (string) ($ticket['ticket_id'] ?? '');
        if ($to === '' || $tid === '') {
            return;
        }
        $name = self::e(self::firstName($ticket));
        $snippet = mb_substr(trim($replyText), 0, 300);
        $snippet = self::e($snippet) . (mb_strlen(trim($replyText)) > 300 ? '&hellip;' : '');
        $url = self::statusUrl();
        $body = "<p>Hello {$name},</p>"
            . "<p>Our team has replied to your request <strong>" . self::e($tid) . "</strong>:</p>"
            . "<blockquote style=\"border-left:3px solid #d0d5dd;margin:0;padding:8px 14px;color:#475467\">{$snippet}</blockquote>"
            . "<p>Read the full reply and respond at <a href=\"{$url}\">{$url}</a> using your reference "
            . "number and email address.</p>";
        Mailer::sendTemplate($to, "New reply on your request ({$tid})", 'Our team replied', $body);
    }

    public static function csatRequest(array $ticket): void
    {
        $to = (string) ($ticket['customer_email'] ?? '');
        $tid = (string) ($ticket['ticket_id'] ?? '');
        if ($to === '' || $tid === '') {
            return;
        }
        $name = self::e(self::firstName($ticket));
        $subjectLine = self::e((string) ($ticket['subject'] ?? ''));
        $portal = rtrim(Config::string('APP_URL', ''), '/') . '/login';
        $body = "<p>Hello {$name},</p>"
            . "<p>Your request <strong>&ldquo;{$subjectLine}&rdquo;</strong> (" . self::e($tid) . ") has been closed.</p>"
            . "<p>We'd love to know how we did — sign in to your portal at "
            . "<a href=\"{$portal}\">{$portal}</a> to rate the support you received.</p>"
            . "<p>If anything is still unresolved, just check the ticket and reply — it will reopen automatically.</p>";
        Mailer::sendTemplate($to, "How did we do? ({$tid})", 'Your request was closed', $body);
    }

    // ── internals ────────────────────────────────────────────────────────────
    private static function statusUrl(): string
    {
        return rtrim(Config::string('APP_URL', ''), '/') . '/status';
    }

    private static function firstName(array $ticket): string
    {
        $name = trim((string) ($ticket['customer_name'] ?? ''));
        if ($name === '') {
            return 'there';
        }
        return explode(' ', $name)[0];
    }

    private static function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
