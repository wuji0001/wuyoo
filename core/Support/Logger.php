<?php

declare(strict_types=1);

namespace Wuyoo\Support;

final readonly class Logger
{
    public function __construct(private string $directory) {}
    public function debug(string $message, array $context = []): void { $this->write('debug', $message, $context); }
    public function info(string $message, array $context = []): void { $this->write('info', $message, $context); }
    public function warning(string $message, array $context = []): void { $this->write('warning', $message, $context); }
    public function error(string $message, array $context = []): void { $this->write('error', $message, $context); }

    private function write(string $level, string $message, array $context): void
    {
        $record = json_encode(['time' => gmdate('c'), 'level' => $level, 'message' => $message, 'context' => $this->redact($context)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (!is_dir($this->directory) || !@error_log($record.PHP_EOL, 3, $this->directory.'/'.gmdate('Y-m-d').'.log')) {
            error_log('Wuyoo log write failed: '.$level.' '.preg_replace('/[\r\n]/', ' ', $message));
        }
    }

    private function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (preg_match('/password|secret|token|authorization|cookie|dsn/i', (string) $key)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = $this->redact($value);
            } elseif (is_object($value)) {
                $context[$key] = '['.get_debug_type($value).']';
            }
        }
        return $context;
    }
}
