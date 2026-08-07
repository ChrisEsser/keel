<?php

declare(strict_types=1);

namespace Framework\Router;

use Framework\Http\Errors;
use Framework\Http\Request;
use Framework\Http\Response;
use Framework\Container\Container;

class Router
{
    /** @var Route[] */
    private array $routes = [];

    public function __construct(private Container $container, private Errors $errors) {}

    public function add(string $method, string $path, string $controller, string $action): self
    {
        $this->routes[] = new Route(strtoupper($method), $path, $controller, $action);
        return $this;
    }

    public function get(string $path, string $controllerAction): self
    {
        return $this->register('GET', $path, $controllerAction);
    }

    public function post(string $path, string $controllerAction): self
    {
        return $this->register('POST', $path, $controllerAction);
    }

    public function put(string $path, string $controllerAction): self
    {
        return $this->register('PUT', $path, $controllerAction);
    }

    public function delete(string $path, string $controllerAction): self
    {
        return $this->register('DELETE', $path, $controllerAction);
    }

    public function patch(string $path, string $controllerAction): self
    {
        return $this->register('PATCH', $path, $controllerAction);
    }

    // Accepts the Laravel-style "Controller@method" string and splits it
    private function register(string $method, string $path, string $controllerAction): self
    {
        [$controller, $action] = explode('@', $controllerAction);
        return $this->add($method, $path, $controller, $action);
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as $route) {
            if ($route->matches($request->getMethod(), $request->getUri())) {
                $request = $this->injectParams($request, $route);

                // Resolve the Controller from the container...
                $controller = $this->container->get($route->getController());
                $action = $route->getAction();

                // ...then call the matching method on it
                return $controller->$action($request);
            }
        }

        return $this->errors->notFound();
    }

    private function injectParams(Request $request, Route $route): Request
    {
        foreach ($route->getParams() as $key => $value) {
            $request = $request->withAttribute($key, $value);
        }
        return $request;
    }
}
