<?php
declare(strict_types=1);

namespace App\Core;

/**
 * SecurityHeaders (§10.12) — set in PHP on every application response so
 * per-route overrides are possible (a blanket .htaccess XFO:DENY would kill
 * the widget, which exists to be iframed).
 *
 * forRoute() returns the header map (pure, testable). send() applies it.
 * Route profiles:
 *   'default' — the strict profile (script-src 'self', frame-ancestors 'self')
 *   'widget'  — no X-Frame-Options; frame-ancestors * (must be embeddable)
 *   'reset'   — adds Referrer-Policy: no-referrer (§10.9 token-leak defence)
 */
final class SecurityHeaders
{
    /**
     * @return array<string,string> header name => value (X-Frame-Options may be absent)
     */
    public static function forRoute(string $route = 'default'): array
    {
        $csp = self::csp($route);

        $headers = [
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            'X-Frame-Options'           => 'SAMEORIGIN',
            'X-Content-Type-Options'    => 'nosniff',
            'Referrer-Policy'           => 'strict-origin-when-cross-origin',
            'Permissions-Policy'        => 'geolocation=(), camera=(), microphone=(), payment=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Content-Security-Policy'   => $csp,
        ];

        if ($route === 'widget') {
            // The widget is meant to be iframed by third parties: no XFO at all
            // (there is no valid "allow any" value), and frame-ancestors * in CSP.
            unset($headers['X-Frame-Options']);
        }

        if ($route === 'reset') {
            $headers['Referrer-Policy'] = 'no-referrer';
        }

        return $headers;
    }

    private static function csp(string $route): string
    {
        $frameAncestors = $route === 'widget' ? '*' : "'self'";

        $directives = [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data:",
            "connect-src 'self'",
            "frame-ancestors {$frameAncestors}",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ];

        return implode('; ', $directives);
    }

    public static function send(string $route = 'default'): void
    {
        if (headers_sent()) {
            return;
        }
        foreach (self::forRoute($route) as $name => $value) {
            header("{$name}: {$value}");
        }
    }
}
