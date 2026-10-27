<?php

declare(strict_types=1);

return ['host' => getenv('REDIS_HOST') ?: '127.0.0.1', 'port' => (int) (getenv('REDIS_PORT') ?: 6379), 'password' => getenv('REDIS_PASSWORD') ?: null, 'username' => getenv('REDIS_USERNAME') ?: null, 'database' => (int) (getenv('REDIS_DATABASE') ?: 0), 'prefix' => 'wuyoo:', 'timeout' => 1.5];
