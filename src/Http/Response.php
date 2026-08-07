<?php

declare(strict_types=1);

namespace Framework\Http;

final class Response
{
    private array $headers;
    private ?\Closure $streamFn = null;

    public function __construct(
        private int $status = 200,
        array $headers = [],
        private string $body = ''
    ) {
        $this->headers = $headers;
    }

    public static function html(string $body, int $status = 200): static
    {
        return new self($status, ['Content-Type' => 'text/html; charset=UTF-8'], $body);
    }

    public static function json(mixed $data, int $status = 200): static
    {
        return new self(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    public static function css(string $body, int $status = 200): static
    {
        return new self($status, ['Content-Type' => 'text/css; charset=UTF-8'], $body);
    }

    public static function text(string $body, int $status = 200): static
    {
        return new self($status, ['Content-Type' => 'text/plain; charset=UTF-8'], $body);
    }

    public static function xml(string $body, int $status = 200): static
    {
        return new self($status, ['Content-Type' => 'application/xml; charset=UTF-8'], $body);
    }

    public static function redirect(string $url, int $status = 302): static
    {
        return new self($status, ['Location' => $url]);
    }

    public static function download(string $content, string $filename, string $contentType = 'text/html; charset=UTF-8'): static
    {
        // $filename is caller-derived (org/page names) and must not be trusted in a header: a CR/LF
        // would split the response and a `"` would break out of the quoted filename. Strip both from
        // the ASCII form, and add an RFC 5987 filename* so non-ASCII names still round-trip cleanly.
        $ascii = substr(str_replace(["\r", "\n", '"', '\\'], '', $filename), 0, 255);
        $ascii = $ascii === '' ? 'download' : $ascii;
        $encoded = rawurlencode($filename);

        return new self(200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . $encoded,
            'Content-Length' => (string) strlen($content),
        ], $content);
    }

    public static function stream(\Closure $fn): static
    {
        $instance = new self(200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
        $instance->streamFn = $fn;
        return $instance;
    }

    public function getStream(): ?\Closure { return $this->streamFn; }
    public function getStatus(): int { return $this->status; }
    public function getBody(): string { return $this->body; }
    public function getHeaders(): array { return $this->headers; }

    public function withHeader(string $key, string $value): static
    {
        $clone = clone $this;
        $clone->headers[$key] = $value;
        return $clone;
    }

    public function withStatus(int $status): static
    {
        $clone = clone $this;
        $clone->status = $status;
        return $clone;
    }
}
