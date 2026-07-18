<?php
declare(strict_types=1);

namespace App\Core;

use ErrorException;
use Throwable;

/**
 * ErrorHandler (§10.10) — custom exception + error + shutdown handlers.
 *
 * Production posture is set HERE, never trusting php.ini on shared hosting:
 *   display_errors = Off, log_errors = On, error_reporting = E_ALL.
 *
 * Users always see a generic page/JSON with a request ID; the full detail
 * (message, file, line, trace) goes ONLY to error.log. The request ID is what
 * turns "it broke" into a two-second log lookup.
 *
 * Whether the client gets JSON or HTML is decided by the front controller
 * (setJsonMode) so this class never reads a superglobal (§16 — that lives in Request).
 */
final class ErrorHandler
{
    private static bool $jsonMode = false;
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');

        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function setJsonMode(bool $json): void
    {
        self::$jsonMode = $json;
    }

    /** Convert PHP errors into exceptions so they flow through one path. */
    public static function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if (!(error_reporting() & $severity)) {
            return false; // respect the current error_reporting level
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    public static function handleException(Throwable $e): void
    {
        $requestId = Logger::requestId();

        Logger::error(
            'uncaught_exception',
            sprintf(
                '%s: %s @ %s:%d',
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ),
            ['trace' => $e->getTraceAsString()]
        );

        self::alert($e, $requestId);
        self::renderGeneric($requestId);
    }

    public static function handleShutdown(): void
    {
        $err = error_get_last();
        if ($err === null) {
            return;
        }
        $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;
        if (($err['type'] & $fatal) === 0) {
            return;
        }
        $requestId = Logger::requestId();
        Logger::error(
            'fatal_error',
            sprintf('%s @ %s:%d', $err['message'], $err['file'], $err['line'])
        );
        self::renderGeneric($requestId);
    }

    private static function renderGeneric(string $requestId): void
    {
        if (headers_sent()) {
            return;
        }
        http_response_code(500);

        if (self::$jsonMode) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(
                ['error' => 'An unexpected error occurred.', 'request_id' => $requestId],
                JSON_HEX_TAG | JSON_HEX_AMP
            );
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        // request id is hex only — safe to embed, but escape defensively anyway.
        $rid = htmlspecialchars($requestId, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        echo <<<HTML
        <!doctype html>
        <html lang="en"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Something went wrong</title>
        <style>body{font-family:system-ui,sans-serif;max-width:32rem;margin:15vh auto;padding:0 1rem;color:#1f2937}
        code{background:#f3f4f6;padding:.15rem .4rem;border-radius:.25rem}</style></head>
        <body><h1>Something went wrong</h1>
        <p>The request could not be completed. Please try again.</p>
        <p>If the problem persists, quote this reference to support:</p>
        <p><code>{$rid}</code></p></body></html>
        HTML;
    }

    /**
     * Alerting hook (§10.10): ALERT_EMAIL receives any uncaught exception,
     * throttled to one per unique signature per hour. Mail is built in Phase 10;
     * until then the intent is recorded on security.log so the wiring is visible
     * and the throttle key is exercised.
     */
    private static function alert(Throwable $e, string $requestId): void
    {
        $signature = substr(hash('sha256', $e::class . '|' . $e->getFile() . '|' . $e->getLine()), 0, 16);
        Logger::security('exception_alert', "signature={$signature} request_id={$requestId}");
        // TODO(Phase 10): send throttled email to ALERT_EMAIL keyed by $signature (1/hr).
    }
}
