<?php

declare(strict_types=1);

namespace Wuyoo\Routing;

use InvalidArgumentException;
use Wuyoo\Http\HttpException;
use Wuyoo\Http\Request;
use Wuyoo\Http\Response;

/** Macaw API and token matching, rewritten without static process-wide state. See docs/UPSTREAM.md. */
final class Router
{
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
    private array $static = [];
    private array $dynamic = [];
    private array $groups = [];
    private array $signatures = [];
    private bool $frozen = false;

    public function get(string $path, mixed $handler, array $middleware = [], array $where = []): Route { return $this->map(['GET'], $path, $handler, $middleware, $where); }
    public function post(string $path, mixed $handler, array $middleware = [], array $where = []): Route { return $this->map(['POST'], $path, $handler, $middleware, $where); }
    public function put(string $path, mixed $handler, array $middleware = [], array $where = []): Route { return $this->map(['PUT'], $path, $handler, $middleware, $where); }
    public function patch(string $path, mixed $handler, array $middleware = [], array $where = []): Route { return $this->map(['PATCH'], $path, $handler, $middleware, $where); }
    public function delete(string $path, mixed $handler, array $middleware = [], array $where = []): Route { return $this->map(['DELETE'], $path, $handler, $middleware, $where); }
    public function head(string $path, mixed $handler, array $middleware = [], array $where = []): Route { return $this->map(['HEAD'], $path, $handler, $middleware, $where); }
    public function options(string $path, mixed $handler, array $middleware = [], array $where = []): Route { return $this->map(['OPTIONS'], $path, $handler, $middleware, $where); }
    public function any(string $path, mixed $handler, array $middleware = [], array $where = []): Route { return $this->map(self::METHODS, $path, $handler, $middleware, $where); }

    public function map(array $methods, string $path, mixed $handler, array $middleware = [], array $where = []): Route
    {
        if ($this->frozen) {
            throw new \LogicException('Routes are immutable after initialization');
        }
        $prefix = '';
        $namespace = '';
        $inherited = [];
        foreach ($this->groups as $group) {
            $prefix .= '/'.trim($group['prefix'] ?? '', '/');
            if (isset($group['namespace'])) {
                $namespace = trim($namespace.'\\'.trim($group['namespace'], '\\'), '\\');
            }
            $inherited = array_merge($inherited, $group['middleware'] ?? []);
        }
        $path = '/'.trim(preg_replace('~/+~', '/', $prefix.'/'.ltrim($path, '/')), '/');
        if ($namespace !== '' && is_string($handler) && str_contains($handler, '@')) {
            $handler = str_starts_with($handler, '\\') ? ltrim($handler, '\\') : $namespace.'\\'.$handler;
        }
        $methods = array_values(array_unique(array_map('strtoupper', $methods)));
        if ($methods === [] || array_diff($methods, self::METHODS) !== []) {
            throw new InvalidArgumentException('Unsupported route method');
        }
        $route = new Route($methods, $path, $handler, array_merge($inherited, $middleware), $where);
        foreach ($methods as $method) {
            $signature = $method.' '.($route->regex ?? $path);
            $signature = preg_replace('/\(\?P<\w+>/', '(?:', $signature);
            if (isset($this->signatures[$signature])) {
                throw new InvalidArgumentException('Duplicate route: '.$method.' '.$path);
            }
        }
        foreach ($methods as $method) {
            $signature = preg_replace('/\(\?P<\w+>/', '(?:', $method.' '.($route->regex ?? $path));
            $this->signatures[$signature] = true;
        }
        if ($route->regex === null) {
            foreach ($methods as $method) {
                $this->static[$path][$method] = $route;
            }
        } else {
            $segment = explode('/', ltrim($path, '/'))[0];
            $bucket = strpbrk($segment, '{(') === false ? $segment : '*';
            $this->dynamic[$bucket][] = $route;
        }
        return $route;
    }

    public function group(array $options, callable $register): void
    {
        $this->groups[] = $options;
        try {
            $register($this);
        } finally {
            array_pop($this->groups);
        }
    }

    public function freeze(): void { $this->frozen = true; }

    /** @return array{0: Route, 1: array}|Response */
    public function match(Request $request): array|Response
    {
        $path = $request->path;
        $method = $request->method;
        $candidates = [];
        if (isset($this->static[$path])) {
            foreach (array_unique($this->static[$path], SORT_REGULAR) as $route) {
                $candidates[] = [$route, []];
            }
        } else {
            $segment = explode('/', ltrim($path, '/'))[0];
            foreach (array_merge($this->dynamic[$segment] ?? [], $this->dynamic['*'] ?? []) as $route) {
                if (($params = $route->matches($path)) !== null) {
                    $candidates[] = [$route, $params];
                }
            }
        }
        foreach ($candidates as [$route, $params]) {
            if (in_array($method, $route->methods, true)) {
                return [$route, $params];
            }
        }
        if ($method === 'HEAD') {
            foreach ($candidates as [$route, $params]) {
                if (in_array('GET', $route->methods, true)) {
                    return [$route, $params];
                }
            }
        }
        if ($candidates === []) {
            throw new HttpException(404, 'Not Found');
        }
        $allow = [];
        foreach ($candidates as [$route]) {
            $allow = array_merge($allow, $route->methods);
        }
        if (in_array('GET', $allow, true)) {
            $allow[] = 'HEAD';
        }
        $allow[] = 'OPTIONS';
        $allow = implode(', ', array_values(array_intersect(self::METHODS, array_unique($allow))));
        if ($method === 'OPTIONS') {
            return new Response('', 204, ['Allow' => $allow]);
        }
        throw new HttpException(405, 'Method Not Allowed', ['Allow' => $allow]);
    }
}
