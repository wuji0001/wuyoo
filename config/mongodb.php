<?php

declare(strict_types=1);

return ['uri' => getenv('MONGODB_URI') ?: 'mongodb://127.0.0.1:27017', 'database' => getenv('MONGODB_DATABASE') ?: 'wuyoo'];
