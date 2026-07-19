<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Response — an immutable-ish value object holding status, headers, and body.
 * Controllers return one of these; the front controller calls send().
 * Controllers never echo (§16).
 */
final class Response
{
    private int $status = 200;
    /** @var array<string,string> */
    private array $headers = [];
    private string $body = '';

    public static function json(array $data, int $status = 200): self
    {
        $r = new self();
        $r->status = $status;
        // §10.2 hardened JSON flags: neutralize <, &, ', " and keep unicode readable.
        $encoded = json_encode(
            $data,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );
        $r->body = $encoded === false ? '{}' : $encoded;
        $r->headers['Content-Type'] = 'application/json; charset=utf-8';
        return $r;
    }

    public static function html(string $html, int $status = 200): self
    {
        $r = new self();
        $r->status = $status;
        $r->body = $html;
        $r->headers['Content-Type'] = 'text/html; charset=utf-8';
        return $r;
    }

    public static function redirect(string $to, int $status = 302): self
    {
        $r = new self();
        $r->status = $status;
        $r->headers['Location'] = $to;
        return $r;
    }

    public static function noContent(): self
    {
        $r = new self();
        $r->status = 204;
        return $r;
    }

    /** Arbitrary body + content type (e.g. a downloaded file stream). */
    public static function make(string $body, int $status, string $contentType): self
    {
        $r = new self();
        $r->status = $status;
        $r->body = $body;
        $r->headers['Content-Type'] = $contentType;
        return $r;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }
        echo $this->body;
    }
}
