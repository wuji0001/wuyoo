<?php

declare(strict_types=1);

namespace Wuyoo\Database;

final class Mongo
{
    private ?\MongoDB\Client $client = null;

    public function __construct(private readonly array $config) {}

    public function database(): \MongoDB\Database
    {
        if (!extension_loaded('mongodb') || !class_exists(\MongoDB\Client::class)) {
            throw new \RuntimeException('MongoDB requires ext-mongodb and composer require mongodb/mongodb:^2.1');
        }
        $this->client ??= new \MongoDB\Client($this->config['uri'], ['serverSelectionTimeoutMS' => 1500]);
        return $this->client->selectDatabase($this->config['database']);
    }
}
