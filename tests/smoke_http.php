<?php
declare(strict_types=1);

/**
 * HTTP smoke test — boots the built-in PHP server against public/ and exercises
 * the real request stack end-to-end. Covers the Phase 2 exit criteria that are
 * HTTP-level (§15): scratch route returns JSON; §10.12 headers present on a normal
 * route; a thrown exception yields a generic page + request id with NO stack trace.
 *
 * Requires a working local .env (APP_ENV != production so the /__boom route exists).
 *   php tests/smoke_http.php
 */

require __DIR__ . '/lib.php';

$root = dirname(__DIR__);
$host = '127.0.0.1';
$port = 8199;
$base = "http://{$host}:{$port}";

if (!is_file("$root/.env")) {
    T::note('.env not found — SKIPPING HTTP smoke (needs a local .env, APP_ENV=local)');
    exit(T::summary());
}

// Start the built-in server rooted at public/ (front-controller routing).
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
// Use a router script so the built-in server matches production's .htaccess:
// real files under public/ are served as-is; everything else → index.php.
$cmd = sprintf(
    'php -d display_errors=0 -S %s:%d -t %s %s',
    $host,
    $port,
    escapeshellarg("$root/public"),
    escapeshellarg("$root/tests/_router.php")
);
$proc = proc_open($cmd, $descriptors, $pipes, $root);
if (!is_resource($proc)) {
    T::ok(false, 'failed to start php -S');
    exit(T::summary());
}

// Wait for the server to accept connections.
$up = false;
for ($i = 0; $i < 50; $i++) {
    $fp = @fsockopen($host, $port, $errno, $errstr, 0.2);
    if ($fp) {
        fclose($fp);
        $up = true;
        break;
    }
    usleep(100_000);
}

/** Minimal HTTP GET returning [status, headers(string), body]. */
$get = static function (string $url): array {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
    $body = file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }
    $headers = implode("\n", $http_response_header ?? []);
    return [$status, $headers, (string) $body];
};

try {
    T::suite('Smoke: server up');
    T::ok($up, "php -S is accepting connections on {$base}");

    // ── /health returns JSON (scratch route) ──
    T::suite('Smoke: /health JSON (§15 Phase 2)');
    [$st, $hdr, $body] = $get("$base/health");
    T::eq(200, $st, '/health → 200');
    T::ok(str_contains($hdr, 'application/json'), '/health content-type JSON');
    $json = json_decode($body, true);
    T::ok(is_array($json) && ($json['status'] ?? '') === 'ok', '/health body {status: ok}');
    T::ok(isset($json['request_id']), '/health carries a request_id');

    // ── §10.12 headers on a normal route ──
    T::suite('Smoke: §10.12 headers');
    [, $hdr] = $get("$base/health");
    foreach ([
        'Strict-Transport-Security', 'X-Content-Type-Options: nosniff',
        'Referrer-Policy', 'Content-Security-Policy', 'X-Frame-Options: SAMEORIGIN',
    ] as $needle) {
        T::ok(stripos($hdr, $needle) !== false, "header present: {$needle}");
    }
    T::ok(!preg_match('/script-src[^;\n]*unsafe-inline/i', $hdr), "script-src has no unsafe-inline");

    // ── landing is HTML ──
    T::suite('Smoke: / landing');
    [$st, $hdr, $body] = $get("$base/");
    T::eq(200, $st, '/ → 200');
    T::ok(str_contains($hdr, 'text/html'), '/ content-type HTML');
    T::ok(str_contains($body, '<h1>'), '/ renders a view');

    // ── exception → generic page + request id, NO stack trace (§10.10) ──
    T::suite('Smoke: exception handling (§10.10)');
    [$st, $hdr, $body] = $get("$base/__boom");
    T::eq(500, $st, '/__boom → 500');
    T::ok(stripos($body, 'stack trace') === false
          && !str_contains($body, 'RuntimeException')
          && !str_contains($body, 'boom: intentional'), 'no exception detail leaked to client');
    T::ok(preg_match('/[0-9a-f]{16}/', $body) === 1, 'generic page shows a request id');

    // ── 404 fallback ──
    T::suite('Smoke: 404');
    [$st] = $get("$base/no-such-route");
    T::eq(404, $st, 'unknown route → 404');

    // ── Phase 4: gateway + shell over real HTTP ──
    T::suite('Smoke: gateway & shell (Phase 4)');
    // unauthenticated dashboard → redirect to login
    [$st, $hdr] = $get("$base/dashboard");
    T::ok(in_array($st, [301, 302], true) && stripos($hdr, 'login') !== false,
        'unauthenticated /dashboard → redirect to /login');

    // /api unknown action over HTTP → JSON ok:false
    $post = static function (string $url, array $data): array {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($data), 'ignore_errors' => true, 'timeout' => 5,
        ]]);
        $body = file_get_contents($url, false, $ctx);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        return [$status, json_decode((string) $body, true)];
    };
    [$st, $body] = $post("$base/api", ['action' => 'noSuchAction', 'payload' => [], 'csrf' => 'x']);
    T::eq(400, $st, '/api unknown action → 400');
    T::ok(($body['ok'] ?? true) === false, '/api unknown action → ok:false JSON');

    // login page assets are same-origin only (no CDN), and CSP has script-src 'self'
    [, , $loginBody] = $get("$base/login");
    T::ok(!preg_match('/<(script|link)\b[^>]+(src|href)=["\']https?:\/\//i', $loginBody),
        'login page references no off-origin assets (no CDN)');
    T::ok(!preg_match('/\son(click|change|submit)\s*=/i', $loginBody),
        'login page has no inline event handlers (D5)');

    // ── Phase 9: public surfaces over real HTTP ──
    T::suite('Smoke: public surfaces (Phase 9)');
    [$st, , $submitBody] = $get("$base/submit");
    T::eq(200, $st, '/submit → 200');
    T::ok(str_contains($submitBody, 'name="website"'), '/submit includes the honeypot field');
    T::ok(!str_contains($submitBody, '>Urgent<') && !preg_match('/value="urgent"/', $submitBody), '/submit does not offer urgent (ceiling)');

    [$st] = $get("$base/status");
    T::eq(200, $st, '/status → 200');

    // widget loader: JS content type, embeddable header profile
    [$st, $whdr, $wbody] = $get("$base/widget.js");
    T::eq(200, $st, '/widget.js → 200');
    T::ok(stripos($whdr, 'javascript') !== false, '/widget.js served as JavaScript');
    T::ok(stripos($whdr, 'X-Frame-Options') === false, '/widget.js sends NO X-Frame-Options (embeddable)');
    T::ok(stripos($whdr, 'frame-ancestors *') !== false, '/widget.js CSP has frame-ancestors *');
    T::ok(str_contains($wbody, 'iframe') && str_contains($wbody, '/submit?widget=1'), 'widget loader injects the submit iframe');

    // the iframed submit page also gets the embeddable profile
    [, $iframeHdr] = $get("$base/submit?widget=1");
    T::ok(stripos($iframeHdr, 'X-Frame-Options') === false, '/submit?widget=1 is embeddable (no XFO)');
} finally {
    // Shut the server down. On Windows, proc_open via the shell means $proc's PID is
    // the cmd wrapper, not php -S — proc_terminate would orphan the server and leak
    // the port. Kill the whole tree with taskkill /T; POSIX uses proc_terminate.
    $status = proc_get_status($proc);
    foreach ($pipes as $p) {
        if (is_resource($p)) {
            fclose($p);
        }
    }
    if (stripos(PHP_OS, 'WIN') === 0 && isset($status['pid'])) {
        exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>&1', $o, $c);
    } else {
        proc_terminate($proc);
    }
    proc_close($proc);
}

exit(T::summary());
