<?php
declare(strict_types=1);

/**
 * Route table. Returns a configured Router. The full §9 route set is filled in
 * across later phases; Phase 2 wires the shell: landing, a health/scratch JSON
 * route, and (non-production only) a route that throws to exercise ErrorHandler.
 */

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;

$router = new Router();

// Landing page (public). Full landing is built in Phase 9; this is the shell.
$router->get('/', static function (Request $request): Response {
    $html = View::render('landing', [
        'company' => Config::string('APP_URL'),
        'title'   => 'P3A Support',
    ]);
    return Response::html($html);
});

// Scratch/health route — proves the stack returns JSON end-to-end (§15 Phase 2).
$router->get('/health', static function (Request $request): Response {
    return Response::json([
        'status'     => 'ok',
        'app_env'    => Config::string('APP_ENV'),
        'php'        => PHP_VERSION,
        'time_utc'   => gmdate('c'),
        'request_id' => \App\Core\Logger::requestId(),
    ]);
});

// Non-production only: deliberately throw, to verify the generic error page +
// request id + no stack trace (§15 Phase 2 exit criterion, §10.10).
if (!Config::isProduction()) {
    $router->get('/__boom', static function (Request $request): Response {
        throw new \RuntimeException('boom: intentional test exception');
    });
}

$router->fallback(static function (Request $request): Response {
    return Response::html(
        View::render('error', ['code' => 404, 'message' => 'Page not found']),
        404
    );
});

return $router;
