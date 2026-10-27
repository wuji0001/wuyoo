<?php

declare(strict_types=1);

namespace Wuyoo\Http;

use Illuminate\Pagination\Cursor;

/** Bounds for untrusted HTTP input; native explicit paginator arguments remain caller-owned. */
final class PaginationInput
{
    public const MAX_PAGE = 1000000;
    public const MAX_PER_PAGE = 1000;

    public static function page(mixed $value): int
    {
        if (is_scalar($value) && is_numeric($value) && (float) $value > self::MAX_PAGE) {
            throw new HttpException(400, 'Pagination page exceeds '.self::MAX_PAGE);
        }
        $page = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
        return $page !== false && $page > 0 ? $page : 1;
    }

    public static function perPage(mixed $value): int
    {
        $size = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
        if ($size === false || $size < 1 || $size > self::MAX_PER_PAGE) {
            throw new HttpException(400, 'Pagination size must be between 1 and '.self::MAX_PER_PAGE);
        }
        return $size;
    }

    public static function cursor(mixed $value): ?Cursor
    {
        return is_string($value) ? RequestCursor::fromEncoded($value) : null;
    }
}
