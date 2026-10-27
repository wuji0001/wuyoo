<?php

declare(strict_types=1);

namespace Wuyoo\Support;

final readonly class Config
{
    public function __construct(private array $values) {}

    public static function load(string $directory): self
    {
        $values = [];
        foreach (glob($directory.'/*.php') ?: [] as $file) {
            if (!in_array(basename($file), ['local.php', 'services.php'], true)) {
                $values[pathinfo($file, PATHINFO_FILENAME)] = require $file;
            }
        }
        if (is_file($directory.'/local.php')) {
            $values = array_replace_recursive($values, require $directory.'/local.php');
        }
        return new self($values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }
}
