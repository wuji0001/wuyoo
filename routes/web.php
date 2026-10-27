<?php

declare(strict_types=1);

use App\Http\Controllers\HomeController;
use Wuyoo\Routing\Router;
use Wuyoo\Security\CsrfMiddleware;

return static function (Router $router): void {
    $router->group(['middleware' => [CsrfMiddleware::class]], static function (Router $router): void {
        $router->get('/', [HomeController::class, 'index']);
    });
};
