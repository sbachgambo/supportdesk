<?php
declare(strict_types=1);

/**
 * Global helpers (§16) — the ONLY permitted globals: e, raw, url, asset,
 * redirect, now, csrf_field, old. Autoloaded via composer "files".
 *
 * csrf_field() and old() are completed in Phase 3 (they need Csrf/Session); they
 * are defined here now with safe no-op behaviour so views can already call them.
 */

use App\Core\Config;

if (!function_exists('e')) {
    /** Escape for HTML output. ENT_SUBSTITUTE keeps invalid UTF-8 from voiding the escape (§10.2). */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('raw')) {
    /**
     * Emit a value WITHOUT escaping. Every call site must carry an adjacent comment
     * justifying why the value is trusted (§10.2). There should be almost none.
     */
    function raw(mixed $value): string
    {
        return (string) $value;
    }
}

if (!function_exists('url')) {
    /** Absolute URL from APP_URL + path. */
    function url(string $path = ''): string
    {
        $base = rtrim(Config::string('APP_URL'), '/');
        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    /**
     * ROOT-RELATIVE URL to a file under public/assets. Relative (not absolute) so
     * asset references are unambiguously same-origin — keeping the strict CSP and
     * the "no off-origin resources" guarantee simple. Honours an APP_URL subpath.
     *
     * Appends a ?v=<filemtime> cache-buster so the browser always fetches the current
     * version — no stale CSS/JS after an edit (and cache busts automatically on deploy).
     */
    function asset(string $path): string
    {
        $rel = ltrim($path, '/');
        $base = parse_url(Config::string('APP_URL'), PHP_URL_PATH);
        $base = is_string($base) ? rtrim($base, '/') : '';
        $url = $base . '/assets/' . $rel;

        $file = (defined('P3A_ROOT') ? P3A_ROOT : dirname(__DIR__)) . '/public/assets/' . $rel;
        if (is_file($file)) {
            $url .= '?v=' . filemtime($file);
        }
        return $url;
    }
}

if (!function_exists('now')) {
    /** Current UTC timestamp as 'Y-m-d H:i:s' (all datetimes are stored UTC — §7). */
    function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}

if (!function_exists('redirect')) {
    /** Build a redirect Response to an app path or absolute URL. */
    function redirect(string $to, int $status = 302): \App\Core\Response
    {
        $target = str_starts_with($to, 'http://') || str_starts_with($to, 'https://') ? $to : url($to);
        return \App\Core\Response::redirect($target, $status);
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Hidden CSRF input for authenticated forms. Wired to Csrf in Phase 3;
     * returns an empty placeholder until then so templates are stable.
     */
    function csrf_field(): string
    {
        if (class_exists('App\\Security\\Csrf')) {
            $token = e(\App\Security\Csrf::token());
            return '<input type="hidden" name="csrf" value="' . $token . '">';
        }
        return '';
    }
}

if (!function_exists('old')) {
    /** Previously-submitted value for redisplay after a validation error (Phase 3). */
    function old(string $key, string $default = ''): string
    {
        return $default;
    }
}
