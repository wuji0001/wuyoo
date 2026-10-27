<?php

declare(strict_types=1);

namespace Wuyoo\Security;

use Wuyoo\Http\HttpException;
use Wuyoo\Http\Request;
use Wuyoo\Http\Response;

final readonly class CsrfMiddleware
{
    public function __construct(private Session $session) {}

    public function __invoke(Request $request, callable $next): Response
    {
        if (!in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $token = $request->header('x-csrf-token') ?? $request->input('_token');
            if (!is_string($token) || !hash_equals($this->session->token(), $token)) {
                throw new HttpException(419, 'CSRF token mismatch');
            }
        }
        return $next($request);
    }
}
