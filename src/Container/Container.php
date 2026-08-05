<?php

declare(strict_types=1);

namespace Keel\Container;

use Closure;
use ReflectionClass;
use ReflectionNamedType;

class Container
{
    /** @var array<string, Closure|string> */
    private array $bindings = [];

    /** @var array<string, object> */
    private array $singletons = [];

    public function bind(string $abstract, Closure|string $factory): void
    {
        $this->bindings[$abstract] = $factory;
    }

    public function singleton(string $abstract, Closure|string $factory): void
    {
        $this->bindings[$abstract] = function () use ($abstract, $factory) {
            if (!isset($this->singletons[$abstract])) {
                $this->singletons[$abstract] = $factory instanceof Closure
                    ? $factory($this)
                    : $this->build($factory);
            }
            return $this->singletons[$abstract];
        };
    }

    public function get(string $abstract): object
    {
        if (isset($this->bindings[$abstract])) {
            $factory = $this->bindings[$abstract];
            return $factory instanceof Closure ? $factory($this) : $this->build($factory);
        }

        return $this->build($abstract);
    }

    private function build(string $class): object
    {
        $ref = new ReflectionClass($class);

        if (!$ref->isInstantiable()) {
            throw new \RuntimeException("Cannot instantiate [$class].");
        }

        $constructor = $ref->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        $deps = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $deps[] = $this->get($type->getName());
            } elseif ($param->isDefaultValueAvailable()) {
                $deps[] = $param->getDefaultValue();
            } else {
                throw new \RuntimeException(
                    "Cannot resolve parameter [{$param->getName()}] in [$class]."
                );
            }
        }

        return $ref->newInstanceArgs($deps);
    }
}
