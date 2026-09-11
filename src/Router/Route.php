<?php

declare(strict_types=1);

namespace Framework\Router;

class Route
{
    private array $params = [];

    public function __construct(
        private string $method,
        private string $path,
        private string $controller,
        private string $action
    ) {}

    public function matches(string $method, string $uri): bool
    {
        // HEAD is GET without a body (RFC 9110 §9.3.2); the server drops the body for us. Without
        // this, uptime monitors and link checkers that probe with HEAD get a 404 from every route.
        if ($method === 'HEAD') {
            $method = 'GET';
        }

        if ($this->method !== $method) {
            return false;
        }

        $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $this->path);
        $pattern = '#^' . $pattern . '$#';

        if (preg_match($pattern, $uri, $matches)) {
            $this->params = array_filter(
                $matches,
                fn($key) => is_string($key),
                ARRAY_FILTER_USE_KEY
            );
            return true;
        }

        return false;
    }

    public function getController(): string { return $this->controller; }
    public function getAction(): string     { return $this->action; }
    public function getParams(): array      { return $this->params; }
}
