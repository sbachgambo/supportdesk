<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Router — maps METHOD + exact path to a handler `fn(Request): Response`.
 *
 * The app keeps the GAS single-gateway pattern (§9): a handful of GET pages plus
 * one POST /api action gateway (built in Phase 4). This router covers the page
 * routes; /api dispatch is layered on in Phase 4.
 */
final class Router
{
    /** @var array<string, array<string, callable>> method => path => handler */
    private array $routes = [];
    /** @var array<string, callable> GET prefix => handler(Request, string $rest) */
    private array $getPrefixes = [];
    /** @var null|callable(Request):Response */
    private $fallback = null;

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][$this->normalize($path)] = $handler;
    }

    /**
     * A GET route with a single trailing segment, e.g. getPrefix('/download', fn)
     * matches /download/42 and calls the handler with $rest = '42'.
     */
    public function getPrefix(string $prefix, callable $handler): void
    {
        $this->getPrefixes[$this->normalize($prefix)] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][$this->normalize($path)] = $handler;
    }

    /** Handler for unmatched routes (404). */
    public function fallback(callable $handler): void
    {
        $this->fallback = $handler;
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $path = $request->path();

        // HEAD is handled as GET with an empty body by the SAPI; treat it as GET here.
        if ($method === 'HEAD') {
            $method = 'GET';
        }

        $handler = $this->routes[$method][$path] ?? null;
        if ($handler !== null) {
            return $handler($request);
        }

        if ($method === 'GET') {
            foreach ($this->getPrefixes as $prefix => $prefixHandler) {
                if (str_starts_with($path, $prefix . '/')) {
                    $rest = substr($path, strlen($prefix) + 1);
                    return $prefixHandler($request, $rest);
                }
            }
        }

        if ($this->fallback !== null) {
            return ($this->fallback)($request);
        }

        return Response::json(['error' => 'Not found'], 404);
    }

    private function normalize(string $path): string
    {
        $path = rtrim($path, '/');
        return $path === '' ? '/' : $path;
    }
}
