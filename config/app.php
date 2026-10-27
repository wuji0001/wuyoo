<?php

declare(strict_types=1);

return [
    'name' => 'Wuyoo',
    'debug' => filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOL),
    'secure_cookies' => filter_var(getenv('SESSION_SECURE') ?: 'true', FILTER_VALIDATE_BOOL),
    'max_body_bytes' => 2097152,
];
