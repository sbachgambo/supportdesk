<?php
declare(strict_types=1);

/**
 * Router script for the built-in server used by tests/smoke_http.php.
 *
 * Emulates production's .htaccess: existing files under public/ are served as-is;
 * everything else routes through the front controller. Without this, `php -S`
 * 404s on missing paths that carry a static extension (e.g. /widget.js) instead of
 * falling through to index.php — which the real Apache rewrite does.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . '/../public' . $path;

if ($path !== '/' && is_file($file)) {
    return false; // let the built-in server serve the real static file
}

require __DIR__ . '/../public/index.php';
