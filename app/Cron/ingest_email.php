<?php
declare(strict_types=1);

/**
 * Cron: inbound email → ticket/reply (§13, D3). Every 5 min. IMAP only.
 *
 * Mutual exclusion via GET_LOCK (connection-scoped, auto-released — no stale lock
 * files). Messages are marked \Seen only after the DB write commits, so a crash
 * mid-run never silently drops mail. The sender-match rule lives in InboundMail.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Config;
use App\Core\Db;
use App\Core\Logger;
use App\Services\InboundMail;

/** IMAP parsing helpers (kept here — only the ingest cron needs them). */
final class ImapHelpers
{
    /** Decode MIME-encoded header words to UTF-8. */
    public static function decodeMime(string $value): string
    {
        return $value === '' ? '' : (string) imap_utf8($value);
    }

    /** Extract the plain-text body; fall back to HTML with tags stripped. */
    public static function plainBody(\IMAP\Connection|false $mbox, int $num): string
    {
        if ($mbox === false) {
            return '';
        }
        $structure = imap_fetchstructure($mbox, $num);
        // multipart: search for a text/plain part, else a text/html part.
        if ($structure !== false && !empty($structure->parts)) {
            $plain = self::findPart($structure->parts, 'PLAIN');
            if ($plain !== null) {
                return self::decodePart((string) imap_fetchbody($mbox, $num, $plain[0]), $plain[1]);
            }
            $html = self::findPart($structure->parts, 'HTML');
            if ($html !== null) {
                return trim(strip_tags(self::decodePart((string) imap_fetchbody($mbox, $num, $html[0]), $html[1])));
            }
        }
        $body = (string) imap_body($mbox, $num);
        $encoding = $structure !== false ? (int) ($structure->encoding ?? 0) : 0;
        $decoded = self::decodePart($body, $encoding);
        return stripos($decoded, '<html') !== false ? trim(strip_tags($decoded)) : trim($decoded);
    }

    /** @return array{0:string,1:int}|null [partNumber, encoding] */
    private static function findPart(array $parts, string $subtype, string $prefix = ''): ?array
    {
        foreach ($parts as $i => $part) {
            $no = $prefix . ($i + 1);
            if (strtoupper((string) ($part->subtype ?? '')) === $subtype) {
                return [$no, (int) ($part->encoding ?? 0)];
            }
            if (!empty($part->parts)) {
                $found = self::findPart($part->parts, $subtype, $no . '.');
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    private static function decodePart(string $data, int $encoding): string
    {
        return match ($encoding) {
            3 => (string) base64_decode($data, false),           // BASE64
            4 => (string) quoted_printable_decode($data),        // QUOTED-PRINTABLE
            default => $data,
        };
    }
}

if (Config::string('INBOUND_MAIL_MODE', 'imap') !== 'imap') {
    fwrite(STDERR, "ingest_email: INBOUND_MAIL_MODE is not 'imap' — nothing to do.\n");
    exit(0);
}
if (!function_exists('imap_open')) {
    Logger::error('ingest_no_imap_ext', 'ext-imap is not loaded');
    fwrite(STDERR, "ingest_email: ext-imap not loaded.\n");
    exit(1);
}

// Single-runner lock; if we can't get it, another run is active — exit cleanly.
$pdo = Db::connect();
$got = (int) $pdo->query("SELECT GET_LOCK('p3a_ingest', 0)")->fetchColumn();
if ($got !== 1) {
    fwrite(STDOUT, "ingest_email: another run holds the lock; exiting.\n");
    exit(0);
}

$counts = ['created' => 0, 'threaded' => 0, 'skipped' => 0, 'mismatched' => 0];
$mbox = null;
try {
    $host = Config::string('IMAP_HOST');
    $port = Config::int('IMAP_PORT', 993);
    $enc = Config::string('IMAP_ENCRYPTION', 'ssl');
    $flags = '/imap' . ($enc === 'ssl' ? '/ssl' : '/tls') . '/validate-cert'; // cert validation ON (§10.13)
    $mailbox = '{' . $host . ':' . $port . $flags . '}INBOX';

    $mbox = imap_open($mailbox, Config::string('IMAP_USER'), Config::string('IMAP_PASS', ''), 0, 1);
    if ($mbox === false) {
        throw new RuntimeException('imap_open failed: ' . imap_last_error());
    }

    $unseen = imap_search($mbox, 'UNSEEN') ?: [];
    $max = Config::int('IMAP_MAX_PER_RUN', 20);
    $unseen = array_slice($unseen, 0, $max);

    foreach ($unseen as $num) {
        $header = imap_headerinfo($mbox, $num);
        $fromEmail = '';
        $fromName = '';
        if ($header !== false && !empty($header->from[0])) {
            $fromEmail = ($header->from[0]->mailbox ?? '') . '@' . ($header->from[0]->host ?? '');
            $fromName = ImapHelpers::decodeMime($header->from[0]->personal ?? '');
        }
        $subject = isset($header->subject) ? ImapHelpers::decodeMime($header->subject) : '';
        $body = ImapHelpers::plainBody($mbox, $num);

        $res = InboundMail::processMessage($fromEmail, $fromName, $subject, $body);
        $counts[$res['result'] === InboundMail::RESULT_MISMATCH_CREATED ? 'mismatched'
              : ($res['result'] === InboundMail::RESULT_THREADED ? 'threaded'
              : ($res['result'] === InboundMail::RESULT_SKIPPED ? 'skipped' : 'created'))]++;

        // Mark seen ONLY after the row is committed.
        imap_setflag_full($mbox, (string) $num, '\\Seen');
    }
} catch (Throwable $e) {
    Logger::error('ingest_failed', $e->getMessage());
    fwrite(STDERR, "ingest_email FAILED: " . $e->getMessage() . "\n");
} finally {
    if ($mbox !== null && $mbox !== false) {
        imap_close($mbox);
    }
    $pdo->query("SELECT RELEASE_LOCK('p3a_ingest')");
}

fwrite(STDOUT, sprintf(
    "[%s] ingest_email: created=%d threaded=%d skipped=%d mismatched=%d\n",
    gmdate('c'),
    $counts['created'],
    $counts['threaded'],
    $counts['skipped'],
    $counts['mismatched']
));

