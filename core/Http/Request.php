<?php

declare(strict_types=1);

namespace Wuyoo\Http;

use JsonException;
use Wuyoo\Security\UploadedFile;

final class Request
{
    private array $headers = [];
    private ?array $json = null;
    public readonly string $method;
    public readonly string $path;
    public readonly string $encodedPath;

    public function __construct(
        string $method = 'GET',
        string $uri = '/',
        private readonly array $query = [],
        private readonly array $post = [],
        array $headers = [],
        private readonly array $cookies = [],
        private readonly array $files = [],
        private readonly string $body = '',
        private readonly array $server = [],
    ) {
        $this->method = strtoupper($method);
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = (string) $value;
        }
        $path = explode('?', $uri, 2)[0];
        if (!str_starts_with($path, '/') || preg_match('/%(?![0-9a-f]{2})|%2f|%5c|[\x00-\x20\x7f\\\\]/i', $path)) {
            throw new HttpException(400, 'Invalid request path');
        }
        $path = rawurldecode($path);
        if (!mb_check_encoding($path, 'UTF-8') || preg_match('/[\x00-\x20\x7f\\\\]/', $path)) {
            throw new HttpException(400, 'Invalid request path');
        }
        $this->path = rtrim($path, '/') ?: '/';
        $this->encodedPath = implode('/', array_map('rawurlencode', explode('/', $this->path)));
    }

    public static function capture(int $maxBytes = 2097152): self
    {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBytes) {
            throw new HttpException(413, 'Request body too large');
        }
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            }
        }
        foreach (['CONTENT_TYPE', 'CONTENT_LENGTH'] as $key) {
            if (isset($_SERVER[$key])) {
                $headers[str_replace('_', '-', $key)] = $_SERVER[$key];
            }
        }
        $stream = fopen('php://input', 'rb');
        $body = $stream ? stream_get_contents($stream, $maxBytes + 1) : '';
        if ($stream) {
            fclose($stream);
        }
        if ($body === false || strlen($body) > $maxBytes) {
            throw new HttpException(413, 'Request body too large');
        }
        return new self($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/', $_GET, $_POST, $headers, $_COOKIE, $_FILES, $body, $_SERVER);
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->query : ($this->query[$key] ?? $default);
    }

    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        if ($this->body === '') {
            return $this->json = [];
        }
        try {
            $decoded = json_decode($this->body, false, 64, JSON_THROW_ON_ERROR);
            if (!$decoded instanceof \stdClass) {
                throw new HttpException(400, 'JSON body must be an object');
            }
            return $this->json = json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpException(400, 'Malformed JSON body');
        }
    }

    public function input(?string $key = null, mixed $default = null): mixed
    {
        $contentType = strtolower(explode(';', $this->header('content-type', ''))[0]);
        $data = $contentType === 'application/json' || str_ends_with($contentType, '+json') ? $this->json() : $this->post;
        if ($data === [] && in_array($this->method, ['PUT', 'PATCH', 'DELETE'], true) && $contentType === 'application/x-www-form-urlencoded') {
            parse_str($this->body, $data);
        }
        $data = array_replace($this->query, $data);
        return $key === null ? $data : ($data[$key] ?? $default);
    }

    public function header(string $name, ?string $default = null): ?string { return $this->headers[strtolower($name)] ?? $default; }
    public function cookie(string $name, mixed $default = null): mixed { return $this->cookies[$name] ?? $default; }
    public function rawBody(): string { return $this->body; }
    public function secure(): bool { return ($this->server['HTTPS'] ?? '') === 'on' || ($this->server['HTTPS'] ?? '') === '1'; }
    public function expectsJson(): bool { return str_contains(strtolower($this->header('accept', '')), 'json') || $this->path === '/api' || str_starts_with($this->path, '/api/'); }

    public function file(string $name): ?UploadedFile
    {
        $file = $this->files[$name] ?? null;
        return $file === null ? null : UploadedFile::fromArray($file);
    }

    public function validate(array $rules): array
    {
        return \Wuyoo\Security\Validator::validate($this->input(), $rules);
    }
}
