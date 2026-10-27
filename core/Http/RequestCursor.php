<?php

declare(strict_types=1);

namespace Wuyoo\Http;

use Illuminate\Pagination\Cursor;

/** Keep upstream decoding/pagination; validate request values and the keys the query actually reads. */
final class RequestCursor extends Cursor
{
    public function __construct(array $parameters, $pointsToNextItems = true)
    {
        if ($parameters === [] || !is_bool($pointsToNextItems)) {
            throw new HttpException(400, 'Invalid pagination cursor');
        }
        foreach ($parameters as $value) {
            if (!is_scalar($value) || (is_float($value) && !is_finite($value))) {
                throw new HttpException(400, 'Invalid pagination cursor');
            }
        }
        parent::__construct($parameters, $pointsToNextItems);
    }

    public function parameter(string $parameterName)
    {
        if (!array_key_exists($parameterName, $this->parameters)) {
            throw new HttpException(400, 'Invalid pagination cursor');
        }
        return parent::parameter($parameterName);
    }
}
