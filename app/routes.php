<?php
declare(strict_types=1);

/**
 * Route table. Returns a configured Router. The full §9 route set is filled in
 * across later phases; Phase 2 wires the shell: landing, a health/scratch JSON
 * route, and (non-production only) a route that throws to exercise ErrorHandler.
 */

use App\Controllers\AuthController;
use App\Core\Config;
use App\Core\Dispatch;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;

$router = new Router();
$auth = new AuthController();

// THE action gateway (§9). Every data/state operation POSTs here as {action,payload,csrf}.
$router->post('/api', static fn(Request $request): Response => Dispatch::handle($request));

// Attachments (§10.7): multipart upload + access-checked download (not JSON /api).
$upload = new \App\Controllers\UploadController();
$router->post('/upload', [$upload, 'upload']);
$router->getPrefix('/download', [$upload, 'download']);

// Reports CSV export (agent+, access-checked; §8).
$router->get('/export/tickets.csv', [new \App\Controllers\ExportController(), 'ticketsCsv']);

// Routing-rule editor (admin, Phase 11).
$router->get('/admin/rules', static function (Request $request): Response {
    if (Session::current() === null) {
        return redirect('login');
    }
    if (Session::role() !== 'admin') {
        return redirect('dashboard');
    }
    return Response::html(View::render('rules', [
        'title'      => 'Routing rules — P3A Support',
        'email'      => (string) Session::email(),
        'role'       => (string) Session::role(),
        'csrf'       => \App\Core\Csrf::token(),
        'company'    => \App\Models\AppConfig::get('company_name', 'P3A Support'),
        'pageScript' => 'rules.js',
    ], 'app'));
});

// Reports dashboard page (agent+).
$router->get('/reports', static function (Request $request): Response {
    if (Session::current() === null) {
        return redirect('login');
    }
    if (!\App\Security\Rbac::isAtLeastAgent()) {
        return redirect('dashboard');
    }
    return Response::html(View::render('reports', [
        'title'      => 'Reports — P3A Support',
        'email'      => (string) Session::email(),
        'role'       => (string) Session::role(),
        'csrf'       => \App\Core\Csrf::token(),
        'company'    => \App\Models\AppConfig::get('company_name', 'P3A Support'),
        'pageScript' => 'reports.js',
    ], 'app'));
});

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
        'title'   => 'Dashboard — P3A Support',
        'email'   => (string) Session::email(),
        'role'    => (string) Session::role(),
        'csrf'    => \App\Core\Csrf::token(),
        'company' => \App\Models\AppConfig::get('company_name', 'P3A Support'),
    ], 'app'));
});

// ── Public surfaces (§9, Phase 9) ──
$router->get('/submit', static function (Request $request): Response {
    $widget = $request->query('widget') === '1';
    return Response::html(View::render('submit', [
        'title'      => 'Submit a request — ' . \App\Models\AppConfig::get('company_name', 'P3A Support'),
        'csrf'       => \App\Core\Csrf::publicToken('submitTicket'),
        'categories' => \App\Models\Category::allActive(),
        'widget'     => $widget,
        'pageScript' => 'public.js',
    ], 'main'));
});

$router->get('/status', static function (Request $request): Response {
    return Response::html(View::render('status', [
        'title'      => 'Check ticket status — ' . \App\Models\AppConfig::get('company_name', 'P3A Support'),
        'csrf'       => \App\Core\Csrf::publicToken('checkTicketStatus'),
        'pageScript' => 'public.js',
    ], 'main'));
});

// Widget loader (§9, §10.12). Routed through index.php so it gets the 'widget' header
// profile (no X-Frame-Options; frame-ancestors *). Injects an iframe to /submit?widget=1.
$router->get('/widget.js', static function (Request $request): Response {
    $js = <<<'JS'
(function(){
  var cs = document.currentScript;
  var base = (cs && cs.src) ? cs.src.replace(/\/widget\.js.*$/, '') : '';
  var f = document.createElement('iframe');
  f.src = base + '/submit?widget=1';
  f.title = 'Support request';
  f.setAttribute('style', 'border:0;width:100%;max-width:440px;height:620px;');
  f.setAttribute('loading', 'lazy');
  if (cs && cs.parentNode) { cs.parentNode.insertBefore(f, cs.nextSibling); }
  else { document.body.appendChild(f); }
})();
JS;
    return Response::make($js, 200, 'application/javascript; charset=utf-8')
        ->withHeader('Cache-Control', 'public, max-age=300');
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
