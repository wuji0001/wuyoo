<?php

declare(strict_types=1);

namespace Wuyoo;

use ErrorException;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\Paginator;
use Throwable;
use Wuyoo\Cache\RedisCache;
use Wuyoo\Database\DB;
use Wuyoo\Database\Database;
use Wuyoo\Database\Mongo;
use Wuyoo\Http\ExceptionHandler;
use Wuyoo\Http\HttpException;
use Wuyoo\Http\PaginationInput;
use Wuyoo\Http\Request;
use Wuyoo\Http\Response;
use Wuyoo\Routing\ControllerResolver;
use Wuyoo\Routing\Router;
use Wuyoo\Security\CsrfMiddleware;
use Wuyoo\Security\Session;
use Wuyoo\Support\Config;
use Wuyoo\Support\Logger;
use Wuyoo\Support\Scope;
use Wuyoo\View\Blade;
use Wuyoo\View\View;

final class Application
{
    private static bool $active = false;
    private array $factories;
    private array $middleware = [];
    private ExceptionHandler $exceptions;
    private readonly Logger $logger;
    public readonly Router $router;
    public readonly Config $config;

    public function __construct(public readonly string $basePath, ?Config $config = null)
    {
        $this->config = $config ?? Config::load($basePath.'/config');
        $this->router = new Router();
        $logger = new Logger($basePath.'/storage/logs');
        $this->logger = $logger;
        $this->exceptions = new ExceptionHandler($logger, (bool) $this->config->get('app.debug', false));
        $this->factories = [
            Config::class => fn (Scope $s) => $this->config,
            Logger::class => fn (Scope $s) => $logger,
            Database::class => fn (Scope $s) => new Database($this->config->get('database')),
            Session::class => fn (Scope $s) => new Session($s->get(Request::class), $basePath.'/storage/sessions', (bool) $this->config->get('app.secure_cookies', true)),
            CsrfMiddleware::class => fn (Scope $s) => new CsrfMiddleware($s->get(Session::class)),
            Blade::class => fn (Scope $s) => new Blade($basePath.'/resources/views', $basePath.'/storage/cache/views', $s->get(Session::class), $this->config->get('view.component_namespace', '')),
            RedisCache::class => fn (Scope $s) => new RedisCache($this->config->get('redis')),
            Mongo::class => fn (Scope $s) => new Mongo($this->config->get('mongodb')),
        ];
    }

    public function bind(string $id, \Closure $factory): void { $this->factories[$id] = $factory; }
    public function middleware(callable|string $middleware): void { $this->middleware[] = $middleware; }

    public function loadRoutes(): void
    {
        foreach (['web', 'api'] as $file) {
            $register = require $this->basePath.'/routes/'.$file.'.php';
            $register($this->router);
        }
        $this->router->freeze();
    }

    /** Serial worker-safe. Eloquent/Blade globals make concurrent coroutines unsupported. */
    public function using(Request $request, callable $callback): mixed
    {
        if (self::$active) { throw new \LogicException('Nested/concurrent request handling is not supported'); }
        self::$active = true;
        $scope = new Scope($this->factories);
        $scope->set(Request::class, $request);
        $modelLoaded = class_exists(Eloquent::class, false);
        $previousResolver = $modelLoaded ? Eloquent::getConnectionResolver() : null;
        $previousDispatcher = $modelLoaded ? Eloquent::getEventDispatcher() : null;
        $dbEntered = false;
        $callbackError = null;
        try {
            if ($modelLoaded) {
                Eloquent::clearBootedModels();
                Eloquent::unsetEventDispatcher();
            }
            DB::enter(fn () => $scope->get(Database::class));
            $dbEntered = true;
            Paginator::currentPageResolver(fn (string $name = 'page'): int => PaginationInput::page($scope->get(Request::class)->query($name, 1)));
            Paginator::currentPathResolver(fn () => $scope->get(Request::class)->encodedPath);
            Paginator::queryStringResolver(fn () => $scope->get(Request::class)->query());
            CursorPaginator::currentCursorResolver(fn (string $name = 'cursor'): ?Cursor => PaginationInput::cursor($scope->get(Request::class)->query($name)));
            Paginator::viewFactoryResolver(fn () => $scope->get(Blade::class)->factory());
            Paginator::defaultView('pagination.default');
            Paginator::defaultSimpleView('pagination.simple');
            return $callback($scope);
        } catch (Throwable $error) {
            $callbackError = $error;
            throw $error;
        } finally {
            $cleanupError = null;
            try {
                foreach ([Blade::class, Database::class, RedisCache::class, Session::class] as $id) {
                    try { if ($scope->initialized($id)) { $scope->get($id)->close(); } }
                    catch (Throwable $error) { $cleanupError ??= $error; }
                }
            } finally {
                if ($dbEntered) { DB::leave(); }
                if (class_exists(Eloquent::class, false)) {
                    Eloquent::clearBootedModels();
                    $previousResolver === null ? Eloquent::unsetConnectionResolver() : Eloquent::setConnectionResolver($previousResolver);
                    $previousDispatcher === null ? Eloquent::unsetEventDispatcher() : Eloquent::setEventDispatcher($previousDispatcher);
                }
                Paginator::currentPageResolver(fn () => 1);
                Paginator::currentPathResolver(fn () => '/');
                Paginator::queryStringResolver(fn () => []);
                CursorPaginator::currentCursorResolver(fn () => null);
                Paginator::viewFactoryResolver(fn () => throw new \LogicException('Pagination views require an active request scope'));
                self::$active = false;
            }
            if ($cleanupError !== null) {
                $this->logger->error('Scope cleanup failed', ['exception' => $cleanupError::class, 'file' => $cleanupError->getFile(), 'line' => $cleanupError->getLine()]);
                if ($callbackError === null) { throw $cleanupError; }
            }
        }
    }

    public function handle(Request $request): Response
    {
        $bufferLevel = ob_get_level();
        ob_start();
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) { return false; }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $response = $this->using($request, function (Scope $scope) use ($request): Response {
                try {
                    $dispatch = function (Request $request) use ($scope): Response {
                        $match = $this->router->match($request);
                        if ($match instanceof Response) { return $match; }
                        [$route, $parameters] = $match;
                        $destination = fn (Request $request) => $this->normalize((new ControllerResolver())->invoke($route->handler, $parameters, $scope, $route->legacyParameters));
                        return $this->pipeline($route->middleware, $destination, $scope, $request)($request);
                    };
                    $response = $this->pipeline($this->middleware, $dispatch, $scope)($request);
                    if ($response->body instanceof View) { $response = $response->withBody($scope->get(Blade::class)->render($response->body)); }
                    if (ob_get_length() > 0) { throw new \LogicException('Return a Response instead of printing controller output'); }
                } catch (Throwable $error) {
                    $response = $this->exceptions->render($error, $scope->get(Request::class));
                }
                if ($scope->initialized(Session::class)) { $response = $scope->get(Session::class)->apply($response); }
                return $response;
            });
            return $this->secure($response);
        } catch (Throwable $error) {
            return $this->secure($this->exceptions->render($error, $request));
        } finally {
            while (ob_get_level() > $bufferLevel) { ob_end_clean(); }
            restore_error_handler();
        }
    }

    private function normalize(mixed $result): Response
    {
        return match (true) {
            $result instanceof Response => $result,
            $result instanceof View => new Response($result, 200, ['Content-Type' => 'text/html; charset=utf-8']),
            is_array($result), $result instanceof \JsonSerializable => Response::json($result),
            is_string($result) => Response::html($result),
            is_int($result) && $result >= 100 && $result <= 599 => new Response('', $result),
            $result === null => new Response('', 204),
            default => throw new \LogicException('Unsupported controller return type'),
        };
    }

    private function pipeline(array $middleware, callable $destination, Scope $scope, ?Request $matchedRequest = null): callable
    {
        $destination = $this->requestBoundary($destination, $scope, $matchedRequest);
        foreach (array_reverse($middleware) as $entry) {
            $next = $destination;
            $destination = $this->requestBoundary(function (Request $request) use ($entry, $next, $scope): Response {
                $middleware = is_string($entry) ? $scope->get($entry) : $entry;
                return $middleware($request, $next);
            }, $scope, $matchedRequest);
        }
        return $destination;
    }

    private function requestBoundary(callable $next, Scope $scope, ?Request $matchedRequest): callable
    {
        return function (Request $request) use ($next, $scope, $matchedRequest): Response {
            if ($matchedRequest !== null && ($request->method !== $matchedRequest->method || $request->path !== $matchedRequest->path)) {
                throw new \LogicException('Cannot change request method or path after route matching');
            }
            $scope->set(Request::class, $request);
            return $next($request);
        };
    }

    private function secure(Response $response): Response
    {
        return $response->withHeader('X-Content-Type-Options', 'nosniff')->withHeader('X-Frame-Options', 'SAMEORIGIN')->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function run(): void
    {
        ini_set('display_errors', '0');
        error_reporting(E_ALL);
        $request = null;
        try {
            $request = Request::capture((int) $this->config->get('app.max_body_bytes', 2097152));
            $response = $this->handle($request);
        } catch (Throwable $error) {
            $response = $this->secure($this->exceptions->render($error, $request));
        }
        $response->send(($request?->method ?? $_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD');
    }
}
