<?php

declare(strict_types=1);

ini_set('display_errors', '0');
try {
    $app = require dirname(__DIR__).'/init.php';
    $app->run();
} catch (Throwable $error) {
    error_log('Wuyoo initialization failed: '.get_class($error));
    if (class_exists(Wuyoo\Http\Response::class)) {
        Wuyoo\Http\Response::html('<h1>500</h1><p>Internal Server Error</p>', 500)->send(($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD');
    } else {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') { echo 'Internal Server Error'; }
    }
}
