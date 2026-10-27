<?php

declare(strict_types=1);

return [
    'default' => getenv('DB_CONNECTION') ?: 'mysql',
    'connections' => [
        'mysql' => [
            'driver' => 'mysql', 'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('DB_PORT') ?: 3306), 'database' => getenv('DB_DATABASE') ?: 'wuyoo',
            'username' => getenv('DB_USERNAME') ?: 'wuyoo', 'password' => getenv('DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ],
        'pgsql' => [
            'driver' => 'pgsql', 'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('DB_PORT') ?: 5432), 'database' => getenv('DB_DATABASE') ?: 'wuyoo',
            'username' => getenv('DB_USERNAME') ?: 'wuyoo', 'password' => getenv('DB_PASSWORD') ?: '',
            'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => getenv('DB_SSLMODE') ?: 'prefer',
        ],
    ],
];
