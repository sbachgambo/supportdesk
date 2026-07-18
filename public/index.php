<?php
declare(strict_types=1);

/**
 * Front controller (§6, §9) — the ONLY PHP file under public/ that carries logic
 * besides widget.js.php. Every request routes through here.
 */

use App\Core\ErrorHandler;
use App\Core\Config;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Router;
use App\Core\SecurityHeaders;

require dirname(__DIR__) . '/app/bootstrap.php';

$request = Request::capture();

// Per-request logging context (request id + who/where) — §10.10.
Logger::boot(bin2hex(random_bytes(8)), $request->ip(), $request->method(), $request->path());

$path = $request->path();

// The /api gateway and any path under it speaks JSON; error output matches.
$wantsJson = $path === '/api' || str_starts_with($path, '/api/');
ErrorHandler::setJsonMode($wantsJson);

// Defence in depth (§10.13): the .htaccess rewrite forces HTTPS first, but if a
// hosting misconfiguration disables it, refuse to serve in production over HTTP.
if (Config::isProduction() && !$request->isHttps()) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'HTTPS required.';
    exit;
}

// Header profile per route (§10.12): widget is embeddable; reset hides referrer.
$profile = match (true) {
    $path === '/widget.js'          => 'widget',
    $path === '/reset'              => 'reset',
    default                          => 'default',
};
SecurityHeaders::send($profile);

/** @var Router $router */
$router = require dirname(__DIR__) . '/app/routes.php';

$response = $router->dispatch($request);
$response->send();
