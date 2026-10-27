<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80300) { throw new RuntimeException('Wuyoo requires PHP 8.3 or later'); }
require __DIR__.'/vendor/autoload.php';

$app = new Wuyoo\Application(__DIR__);
$services = require __DIR__.'/config/services.php';
$services($app);
$app->loadRoutes();
return $app;
