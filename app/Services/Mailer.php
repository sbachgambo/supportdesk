<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;
use App\Core\Db;
use App\Models\AppConfig;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use Throwable;

/**
 * Mailer (§10.16) — the single outbound-mail path. Every send is:
 *   - recipient-validated (filter_var); any \r or \n in recipient/subject is rejected
 *     outright (header injection);
 *   - suppressed for SUPPRESS_EMAIL_DOMAINS (seed/demo addresses never get real mail);
 *   - recorded in mail_log (sent | suppressed | failed) for deliverability + abuse forensics.
 *
 * Bodies are wrapped in a branded template (company name + brand colour from config).
 * In non-production or when MAIL_PRETEND=1, SMTP is skipped and the send is logged as
 * 'sent' — so the whole mail path is exercised safely in tests.
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): string
    {
        $to = trim($to);
        $subject = trim($subject);

        // Header-injection guard (§10.16): CR/LF in recipient or subject → reject.
        if (preg_match('/[\r\n]/', $to) || preg_match('/[\r\n]/', $subject)) {
            self::log($to, $subject, 'failed', 'header injection attempt');
            Logger::security('mail_header_injection', 'to=' . substr($to, 0, 60));
            return 'failed';
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            self::log($to, $subject, 'failed', 'invalid recipient');
            return 'failed';
        }

        // Seed/demo domain suppression.
        $domain = strtolower(substr(strrchr($to, '@') ?: '', 1));
        if (in_array($domain, array_map('strtolower', Config::list('SUPPRESS_EMAIL_DOMAINS', '')), true)) {
            self::log($to, $subject, 'suppressed', '');
            return 'suppressed';
        }

        // Pretend mode (tests / non-production): skip SMTP, record as sent.
        if (!Config::isProduction() || Config::bool('MAIL_PRETEND', false)) {
            self::log($to, $subject, 'sent', 'pretend');
            return 'sent';
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = Config::string('MAIL_HOST');
            $mail->Port = Config::int('MAIL_PORT', 587);
            $mail->SMTPAuth = true;
            $mail->Username = Config::string('MAIL_USER');
            $mail->Password = Config::string('MAIL_PASS', '');
            $mail->SMTPSecure = Config::string('MAIL_ENCRYPTION', 'tls');
            $mail->CharSet = 'UTF-8';
            $mail->setFrom(Config::string('MAIL_FROM'), Config::string('MAIL_FROM_NAME', 'Support'));
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody ?? trim(strip_tags($htmlBody));
            $mail->send();
            self::log($to, $subject, 'sent', '');
            return 'sent';
        } catch (PHPMailerException | Throwable $e) {
            self::log($to, $subject, 'failed', substr($e->getMessage(), 0, 200));
            Logger::error('mail_send_failed', 'to=' . substr($to, 0, 60));
            return 'failed';
        }
    }

    /** Convenience: wrap content in the branded HTML shell and send. */
    public static function sendTemplate(string $to, string $subject, string $heading, string $bodyHtml): string
    {
        return self::send($to, $subject, self::template($heading, $bodyHtml));
    }

    public static function template(string $heading, string $bodyHtml): string
    {
        $company = self::escape(AppConfig::get('company_name', 'Support'));
        $color = AppConfig::get('brand_color', '#4057F5');
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#4057F5';
        $heading = self::escape($heading);
        return <<<HTML
        <div style="font-family:system-ui,Arial,sans-serif;max-width:560px;margin:0 auto;color:#1f2937">
          <div style="background:{$color};color:#fff;padding:16px 20px;border-radius:8px 8px 0 0;font-weight:700">{$company}</div>
          <div style="border:1px solid #e5e7eb;border-top:0;border-radius:0 0 8px 8px;padding:20px">
            <h2 style="margin-top:0">{$heading}</h2>
            {$bodyHtml}
          </div>
        </div>
        HTML;
    }

    private static function log(string $recipient, string $subject, string $status, string $error): void
    {
        try {
            Db::insert('mail_log', [
                'recipient'  => mb_substr($recipient, 0, 254),
                'subject'    => mb_substr($subject, 0, 255),
                'status'     => $status,
                'error'      => mb_substr($error, 0, 255),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            // logging the mail must never break the send path
            Logger::error('mail_log_failed', $e->getMessage());
        }
    }

    private static function escape(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
