<?php
declare(strict_types=1);

/**
 * Route table. Returns a configured Router. The full §9 route set is filled in
 * across later phases; Phase 2 wires the shell: landing, a health/scratch JSON
 * route, and (non-production only) a route that throws to exercise ErrorHandler.
 */

use App\Controllers\AuthController;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;

$router = new Router();
$auth = new AuthController();

// Landing page (public). Full landing is built in Phase 9; this is the shell.
$router->get('/', static function (Request $request): Response {
    $html = View::render('landing', [
        'company' => Config::string('APP_URL'),
        'title'   => 'P3A Support',
    ]);
    return Response::html($html);
});

// ── Auth (§9, §10.3, §10.9) ──
$router->get('/login', [$auth, 'showLogin']);
$router->post('/login', [$auth, 'login']);
$router->post('/logout', [$auth, 'logout']);
$router->get('/forgot', [$auth, 'showForgot']);
$router->post('/forgot', [$auth, 'forgot']);
$router->get('/reset', [$auth, 'showReset']);
$router->post('/reset', [$auth, 'reset']);

// Dashboard (auth-gated). Full role-branched shell is Phase 4; this proves sessions.
$router->get('/dashboard', static function (Request $request): Response {
    if (Session::current() === null) {
        return redirect('login');
    }
    return Response::html(View::render('dashboard', [
        'title' => 'Dashboard — P3A Support',
        'email' => (string) Session::email(),
        'role'  => (string) Session::role(),
    ]));
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
