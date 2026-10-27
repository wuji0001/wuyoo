<?php

declare(strict_types=1);

namespace Wuyoo\Http;

use InvalidArgumentException;
use Wuyoo\View\View;

final class Response
{
    private array $headers = [];
    private array $cookies = [];

    public function __construct(public readonly string|View $body = '', public readonly int $status = 200, array $headers = [])
    {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException('Invalid HTTP status');
        }
        foreach ($headers as $name => $value) {
            $this->addHeader($name, (string) $value);
        }
    }

    public static function html(string $html, int $status = 200): self { return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']); }
    public static function json(mixed $data, int $status = 200): self { return new self(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $status, ['Content-Type' => 'application/json; charset=utf-8']); }
    public static function view(string $name, array $data = [], int $status = 200): self { return new self(new View($name, $data), $status, ['Content-Type' => 'text/html; charset=utf-8']); }

    public static function redirect(string $url, int $status = 302): self
    {
        if (!str_starts_with($url, '/') || str_starts_with($url, '//') || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || preg_match('/%0[ad]|%5c/i', $url)) {
            throw new InvalidArgumentException('Redirect requires a safe local path');
        }
        if (!in_array($status, [301, 302, 303, 307, 308], true)) {
            throw new InvalidArgumentException('Invalid redirect status');
        }
        return new self('', $status, ['Location' => $url]);
    }

    public function withHeader(string $name, string $value): self
    {
        $copy = clone $this;
        $copy->addHeader($name, $value);
        return $copy;
    }

    private function addHeader(string $name, string $value): void
    {
        if (!preg_match('/^[!#$%&\x27*+.^_`|~0-9a-z-]+$/iD', $name) || preg_match('/[\r\n\x00]/', $value)) {
            throw new InvalidArgumentException('Invalid response header');
        }
        $this->headers[strtolower($name)] = $value;
    }

    public function headers(): array { return $this->headers; }
    public function cookies(): array { return $this->cookies; }

    public function withCookie(string $name, string $value, array $options = []): self
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $name)) {
            throw new InvalidArgumentException('Invalid cookie name');
        }
        $options = array_replace(['expires' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'], $options);
        if (!in_array($options['samesite'], ['Lax', 'Strict', 'None'], true) || ($options['samesite'] === 'None' && !$options['secure']) || preg_match('/[\r\n\x00;]/', (string) $options['path'])) {
            throw new InvalidArgumentException('Invalid cookie options');
        }
        $copy = clone $this;
        $copy->cookies[$name] = [$value, $options];
        return $copy;
    }

    public function withBody(string $body): self
    {
        $copy = new self($body, $this->status, $this->headers);
        $copy->cookies = $this->cookies;
        return $copy;
    }

    public function send(bool $head = false): void
    {
        if ($this->body instanceof View) {
            throw new \LogicException('Render the view before sending');
        }
        if (headers_sent()) {
            throw new \LogicException('HTTP output started before Response::send');
        }
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name.': '.$value, true);
        }
        foreach ($this->cookies as $name => [$value, $options]) {
            setcookie($name, $value, $options);
        }
        if (!$head && $this->status >= 200 && !in_array($this->status, [204, 304], true)) {
            echo $this->body;
        }
    }
}
