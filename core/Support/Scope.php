<?php

declare(strict_types=1);

namespace Wuyoo\Support;

use Closure;
use LogicException;

/** A new Scope is created for every request. Factories must not capture request state. */
final class Scope
{
    private array $instances = [];
    private array $resolving = [];
    private array $observed = [];

    public function __construct(private readonly array $factories = []) {}

    public function set(string $id, mixed $value): void
    {
        if ((isset($this->observed[$id]) || isset($this->resolving[$id]))
            && (!array_key_exists($id, $this->instances) || $this->instances[$id] !== $value)) {
            throw new LogicException('Cannot replace an observed scope value: '.$id);
        }
        $this->instances[$id] = $value;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances) || isset($this->factories[$id]);
    }

    public function get(string $id): mixed
    {
        return $this->resolve($id);
    }

    /** Use an explicit fallback factory only when no value or factory is registered. */
    public function getOrCreate(string $id, Closure $factory): mixed
    {
        return $this->resolve($id, $factory);
    }

    private function resolve(string $id, ?Closure $fallback = null): mixed
    {
        $this->observed[$id] = true;
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (isset($this->resolving[$id])) {
            throw new LogicException('Circular service dependency: '.$id);
        }
        $factory = $this->factories[$id] ?? $fallback;
        if (!$factory instanceof Closure) {
            throw new LogicException('Register an explicit service factory for '.$id);
        }
        $this->resolving[$id] = true;
        try {
            return $this->instances[$id] = $factory($this);
        } finally {
            unset($this->resolving[$id]);
        }
    }

    public function initialized(string $id): bool
    {
        return array_key_exists($id, $this->instances);
    }
}
