<?php

declare(strict_types=1);

namespace Wuyoo\Database;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use LogicException;

/** Compatibility bridge active only inside Application::handle / using(). */
final class DB
{
    private static ?Closure $resolver = null;

    public static function enter(Closure $resolver): void
    {
        if (self::$resolver !== null) { throw new LogicException('Concurrent database scopes are not supported'); }
        self::$resolver = $resolver;
    }

    public static function leave(): void { self::$resolver = null; }

    public static function manager(): Database
    {
        if (self::$resolver === null) { throw new LogicException('Use DB inside a request or Application::using()'); }
        return (self::$resolver)();
    }

    public static function connection(?string $name = null): Connection { return self::manager()->connection($name); }
    public static function table(string $table, ?string $connection = null): Builder { return self::connection($connection)->table($table); }
    public static function transaction(callable $callback, int $attempts = 1): mixed { return self::connection()->transaction(Closure::fromCallable($callback), $attempts); }
}
