<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;
use App\Models\AppConfig;
use App\Models\Organization;
use Throwable;

/**
 * SlackNotifier (§3) — optional Slack alerts via an Incoming Webhook. Enabled only
 * when `slack_webhook_url` config is set (an https URL); otherwise every call is a
 * no-op. A server-to-server POST (not a browser request), so the strict CSP does not
 * apply. It NEVER throws and NEVER blocks ticketing — a Slack outage is invisible to
 * customers, exactly like the Mailer path.
 *
 * Dev/tests use "pretend" mode (the same convention as Mailer): the message is logged,
 * not actually sent, so the whole path is exercised without hitting Slack.
 *
 * Every message includes the organization/team name so a single shared channel still
 * shows which tenant a ticket belongs to (multi-tenancy).
 */
final class SlackNotifier
{
    /** New-ticket alert. Returns sent|pretend|disabled|failed. */
    public static function ticketCreated(array $ticket): string
    {
        $id = (string) ($ticket['ticket_id'] ?? '');
        if ($id === '') {
            return 'disabled';
        }
        $subject  = self::clip((string) ($ticket['subject'] ?? ''), 160);
        $priority = ucfirst((string) ($ticket['priority'] ?? 'normal'));
        $customer = (string) ($ticket['customer_name'] ?? '');
        if ($customer === '') {
            $customer = (string) ($ticket['customer_email'] ?? '');
        }
        $org = self::orgName($ticket);
        $text = ":ticket: *New ticket* `{$id}` — " . self::esc($subject) . "\n"
              . "Priority: *" . self::esc($priority) . "*"
              . ($org !== '' ? " · Team: " . self::esc($org) : '')
              . ($customer !== '' ? " · From: " . self::esc($customer) : '');
        return self::send($text);
    }

    /** SLA-breach alert ($milestone = 'response'|'resolution'). */
    public static function slaBreach(array $ticket, string $milestone): string
    {
        $id = (string) ($ticket['ticket_id'] ?? '');
        if ($id === '') {
            return 'disabled';
        }
        $subject = self::clip((string) ($ticket['subject'] ?? ''), 160);
        $agent   = (string) ($ticket['assigned_to'] ?? '');
        $org     = self::orgName($ticket);
        $text = ":rotating_light: *SLA " . self::esc($milestone) . " breach* `{$id}` — " . self::esc($subject) . "\n"
              . "Assigned to: " . self::esc($agent !== '' ? $agent : 'unassigned')
              . ($org !== '' ? " · Team: " . self::esc($org) : '');
        return self::send($text);
    }

    // ── internals ────────────────────────────────────────────────────────────
    private static function send(string $text): string
    {
        $url = trim((string) AppConfig::get('slack_webhook_url', ''));
        if ($url === '' || stripos($url, 'https://') !== 0) {
            return 'disabled';
        }
        // Dev/tests: never call Slack for real — log and report pretend (mirrors Mailer).
        if (!Config::isProduction()) {
            Logger::write('slack', 'slack_pretend', mb_substr($text, 0, 200));
            return 'pretend';
        }
        try {
            $ch = curl_init($url);
            if ($ch === false) {
                return 'failed';
            }
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['text' => $text]),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 200 && $code < 300) {
                return 'sent';
            }
            Logger::error('slack_failed', 'http=' . $code);
            return 'failed';
        } catch (Throwable $e) {
            Logger::error('slack_failed', substr($e->getMessage(), 0, 120));
            return 'failed';
        }
    }

    private static function orgName(array $ticket): string
    {
        $orgId = (string) ($ticket['organization_id'] ?? '');
        if ($orgId === '') {
            return '';
        }
        $org = Organization::find($orgId);
        return (string) ($org['name'] ?? '');
    }

    /** Slack mrkdwn escaping — only &, <, > per Slack's docs (prevents broken messages). */
    private static function esc(string $v): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $v);
    }

    private static function clip(string $v, int $max): string
    {
        return mb_strlen($v) > $max ? mb_substr($v, 0, $max - 1) . '…' : $v;
    }
}
