<?php

declare(strict_types=1);

namespace Wuyoo\Http;

use Throwable;
use Wuyoo\Support\Logger;

final readonly class ExceptionHandler
{
    public function __construct(private Logger $logger, private bool $debug = false) {}

    public function render(Throwable $error, ?Request $request): Response
    {
        $status = $error instanceof HttpException ? $error->status : 500;
        $message = $error instanceof HttpException ? $error->getMessage() : 'Internal Server Error';
        if ($status >= 500) {
            $this->logger->error('Request failed', ['exception' => $error::class, 'file' => $error->getFile(), 'line' => $error->getLine()]);
        }
        $data = ['message' => $message];
        if ($this->debug && $status >= 500) {
            $data['debug'] = ['type' => $error::class, 'message' => $error->getMessage(), 'file' => $error->getFile(), 'line' => $error->getLine()];
        }
        $response = $request?->expectsJson() ? Response::json($data, $status) : Response::html('<!doctype html><meta charset="utf-8"><h1>'.$status.'</h1><p>'.htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>', $status);
        foreach ($error instanceof HttpException ? $error->headers : [] as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }
}
