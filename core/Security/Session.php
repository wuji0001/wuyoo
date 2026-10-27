<?php

declare(strict_types=1);

namespace Wuyoo\Security;

use Wuyoo\Http\Request;

final class Session
{
    private bool $started = false;

    public function __construct(private readonly Request $request, private readonly string $directory, private readonly bool $secure = true) {}

    private function start(): void
    {
        if ($this->started) { return; }
        if (session_status() === PHP_SESSION_ACTIVE) {
            throw new \LogicException('A session is already active; isolate request lifetimes');
        }
        session_save_path($this->directory);
        session_name('wuyoo_session');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_cookies', '0');
        session_cache_limiter('');
        $id = $this->request->cookie('wuyoo_session', '');
        session_id(is_string($id) && preg_match('/^[a-zA-Z0-9,-]{16,128}$/D', $id) ? $id : '');
        $_SESSION = [];
        if (!session_start()) { throw new \RuntimeException('Unable to start session'); }
        $this->started = true;
    }

    public function get(string $key, mixed $default = null): mixed { $this->start(); return $_SESSION[$key] ?? $default; }
    public function put(string $key, mixed $value): void { $this->start(); $_SESSION[$key] = $value; }
    public function regenerate(): void { $this->start(); if (!session_regenerate_id(true)) { throw new \RuntimeException('Session regeneration failed'); } }
    public function token(): string
    {
        $token = $this->get('_csrf');
        if (!is_string($token)) { $token = bin2hex(random_bytes(32)); $this->put('_csrf', $token); }
        return $token;
    }

    public function apply(\Wuyoo\Http\Response $response): \Wuyoo\Http\Response
    {
        if (!$this->started) { return $response; }
        return $response->withCookie('wuyoo_session', session_id(), ['secure' => $this->secure])
            ->withHeader('Cache-Control', 'private, no-store');
    }

    public function close(): void
    {
        if ($this->started) {
            try {
                if (!session_write_close()) { throw new \RuntimeException('Session write failed'); }
            } finally {
                $_SESSION = [];
                session_id('');
                $this->started = false;
            }
        }
    }
}
