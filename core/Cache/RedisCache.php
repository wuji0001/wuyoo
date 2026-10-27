<?php

declare(strict_types=1);

namespace Wuyoo\Cache;

use RuntimeException;

final class RedisCache
{
    private ?\Redis $client = null;

    public function __construct(private readonly array $config) {}

    private function client(): \Redis
    {
        if ($this->client !== null) { return $this->client; }
        if (!extension_loaded('redis')) { throw new RuntimeException('Optional cache requires ext-redis'); }
        $client = new \Redis();
        if (!$client->connect($this->config['host'], (int) $this->config['port'], (float) ($this->config['timeout'] ?? 1.5))) {
            throw new RuntimeException('Redis connection failed');
        }
        $client->setOption(\Redis::OPT_READ_TIMEOUT, (float) ($this->config['timeout'] ?? 1.5));
        if (!empty($this->config['password']) && !$client->auth(empty($this->config['username']) ? $this->config['password'] : [$this->config['username'], $this->config['password']])) {
            throw new RuntimeException('Redis authentication failed');
        }
        if (!$client->select((int) ($this->config['database'] ?? 0))) { throw new RuntimeException('Redis database selection failed'); }
        return $this->client = $client;
    }

    private function key(string $key): string { return ($this->config['prefix'] ?? 'wuyoo:').$key; }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->client()->get($this->key($key));
        return $value === false ? $default : json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    public function put(string $key, mixed $value, int $seconds = 300): void
    {
        if ($seconds <= 0) { $this->forget($key); return; }
        if (!$this->client()->setex($this->key($key), $seconds, json_encode($value, JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('Redis write failed');
        }
    }

    public function forget(string $key): bool { return $this->client()->del($this->key($key)) > 0; }
    public function close(): void { if ($this->client !== null) { $this->client->close(); $this->client = null; } }
}
