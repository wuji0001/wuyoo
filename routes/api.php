<?php

declare(strict_types=1);

use App\Http\Controllers\HomeController;
use Wuyoo\Routing\Router;

return static function (Router $router): void {
    $router->group(['prefix' => 'api'], static function (Router $router): void {
        $router->get('/health', static fn (): array => ['status' => 'ok', 'framework' => 'Wuyoo']);
        $router->get('/hello/{name}', [HomeController::class, 'hello']);
    });
};
