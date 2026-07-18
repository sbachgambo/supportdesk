<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Request (§16) — the ONLY class permitted to read $_GET/$_POST/$_FILES/$_COOKIE/$_SERVER.
 * Enforced by StaticSuite. Everything else receives validated input from here, which is
 * what makes "is every input validated?" an answerable question.
 *
 * Construct via capture() from the real superglobals, or via the constructor in tests.
 */
final class Request
{
    /**
     * @param array<string,mixed> $query   $_GET
     * @param array<string,mixed> $post     parsed body ($_POST or decoded JSON)
     * @param array<string,mixed> $server  $_SERVER
     * @param array<string,mixed> $cookies $_COOKIE
     * @param array<string,mixed> $files    $_FILES
     */
    public function __construct(
        private array $query = [],
        private array $post = [],
        private array $server = [],
        private array $cookies = [],
        private array $files = [],
        private string $rawBody = ''
    ) {
    }

    public static function capture(): self
    {
        $server = $_SERVER;
        $raw = file_get_contents('php://input') ?: '';
        $post = $_POST;

        // JSON bodies (the /api gateway posts application/json) are decoded here so
        // no other layer touches php://input or $_POST.
        $contentType = $server['CONTENT_TYPE'] ?? '';
        if (str_contains(strtolower($contentType), 'application/json') && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $post = $decoded;
            }
        }

        return new self($_GET, $post, $server, $_COOKIE, $_FILES, $raw);
    }

    public function method(): string
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    /** Path only, without query string, normalized without a trailing slash. */
    public function path(): string
    {
        $uri = (string) ($this->server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        $path = rtrim($path, '/');
        return $path === '' ? '/' : $path;
    }

    public function query(string $key, ?string $default = null): ?string
    {
        $v = $this->query[$key] ?? $default;
        return is_scalar($v) ? (string) $v : $default;
    }

    /** Body field (from $_POST or decoded JSON). Returns raw value (may be array). */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    /** Body field coerced to a trimmed string. */
    public function str(string $key, string $default = ''): string
    {
        $v = $this->post[$key] ?? $default;
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    /** Entire decoded body (used by the /api gateway: {action, payload, csrf}). */
    public function body(): array
    {
        return $this->post;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function cookie(string $key, ?string $default = null): ?string
    {
        $v = $this->cookies[$key] ?? $default;
        return is_scalar($v) ? (string) $v : $default;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $v = $this->server[$key] ?? null;
        return $v === null ? null : (string) $v;
    }

    public function files(): array
    {
        return $this->files;
    }

    /**
     * Best-effort client IP. On shared hosting behind a proxy, REMOTE_ADDR is the
     * proxy; X-Forwarded-For is attacker-spoofable and is NOT trusted for security
     * decisions — it is recorded for forensics only. Rate-limit/lockout keys use
     * REMOTE_ADDR (§10.3, §10.6).
     */
    public function ip(): string
    {
        $ip = (string) ($this->server['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isHttps(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));
        return $https !== '' && $https !== 'off';
    }
}
