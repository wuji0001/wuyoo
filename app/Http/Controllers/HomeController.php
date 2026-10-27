<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Wuyoo\Http\Response;

final class HomeController
{
    public function index(): Response
    {
        return Response::view('home', ['title' => 'Wuyoo', 'message' => '欢迎使用 Wuyoo']);
    }

    public function hello(string $name = 'world'): array
    {
        return ['message' => 'Hello, '.$name, 'framework' => 'Wuyoo'];
    }
}
