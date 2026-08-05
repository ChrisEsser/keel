<?php

declare(strict_types=1);

namespace Keel\Http;

final class Request
{
    private array $query;
    private array $body;
    private array $server;
    private array $headers;
    private string $rawBody;
    private array $files;

    public function __construct(
        private string $method,
        private string $uri,
        array $query = [],
        array $body = [],
        array $server = [],
        array $headers = [],
        string $rawBody = '',
        array $files = []
    ) {
        $this->query = $query;
        $this->body = $body;
        $this->server = $server;
        $this->headers = $headers;
        $this->rawBody = $rawBody;
        $this->files = $files;
    }

    public static function fromGlobals(): static
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $headers = function_exists('getallheaders') ? getallheaders() : [];

        return new self(
            method: $method,
            uri: $uri,
            query: $_GET,
            body: $_POST,
            server: $_SERVER,
            headers: $headers,
            rawBody: file_get_contents('php://input') ?: '',
            files: $_FILES
        );
    }

    public function getMethod(): string { return $this->method; }
    public function getUri(): string { return $this->uri; }
    public function getQuery(): array { return $this->query; }
    public function getBody(): array { return $this->body; }
    public function getRawBody(): string { return $this->rawBody; }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function isJson(): bool
    {
        $ct = $this->headers['Content-Type'] ?? '';
        return str_contains($ct, 'application/json');
    }

    public function jsonBody(): array
    {
        return json_decode($this->rawBody, true) ?? [];
    }

    public function withUri(string $uri): static
    {
        $clone = clone $this;
        $clone->uri = $uri;
        return $clone;
    }

    public function withAttribute(string $key, mixed $value): static
    {
        $clone = clone $this;
        $clone->server['__attributes'][$key] = $value;
        return $clone;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->server['__attributes'][$key] ?? $default;
    }

    public function getHeader(string $key, mixed $default = null): mixed
    {
        return $this->headers[$key]
            ?? $this->headers[strtolower($key)]
            ?? $this->headers[ucwords(strtolower($key), '-')]
            ?? $default;
    }

    public function getFile(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        // Defense-in-depth: only accept a tmp_name that PHP itself recorded as an HTTP upload for
        // THIS request. Guarantees no caller can be tricked into reading an arbitrary server path
        // (e.g. /etc/passwd) via a forged tmp_name, before any of them touch file_get_contents().
        $tmpName = $file['tmp_name'] ?? '';
        if (!is_string($tmpName) || $tmpName === '' || !is_uploaded_file($tmpName)) {
            return null;
        }
        return $file;
    }
}
