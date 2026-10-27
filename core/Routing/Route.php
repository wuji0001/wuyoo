<?php

declare(strict_types=1);

namespace Wuyoo\Routing;

use InvalidArgumentException;

final readonly class Route
{
    public ?string $regex;
    public array $parameters;
    public array $legacyParameters;

    public function __construct(public array $methods, public string $path, public mixed $handler, public array $middleware = [], array $constraints = [])
    {
        $names = [];
        $legacy = [];
        $offset = 0;
        $regex = '';
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}|\(:(num|any|all)\)/', $path, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $i => [$token, $position]) {
            $name = $matches[1][$i][0] ?: 'param'.$i;
            if ($matches[1][$i][0] === '') { $legacy[] = $name; }
            if (in_array($name, $names, true)) {
                throw new InvalidArgumentException('Duplicate route parameter: '.$name);
            }
            $pattern = $constraints[$name] ?? match ($matches[2][$i][0]) {
                'num' => '[0-9]+', 'all' => '.*', default => '[^/]+',
            };
            if (str_contains($pattern, '~')) {
                throw new InvalidArgumentException('Route constraints may not contain the ~ delimiter');
            }
            $regex .= preg_quote(substr($path, $offset, $position - $offset), '~').'(?P<'.$name.'>'.$pattern.')';
            $offset = $position + strlen($token);
            $names[] = $name;
        }
        $this->parameters = $names;
        $this->legacyParameters = $legacy;
        $this->regex = $names === [] ? null : '~\A'.$regex.preg_quote(substr($path, $offset), '~').'\z~uD';
        if ($this->regex !== null && @preg_match($this->regex, '') === false) {
            throw new InvalidArgumentException('Invalid route constraint');
        }
        foreach ($constraints as $name => $_) {
            if (!in_array($name, $names, true)) {
                throw new InvalidArgumentException('Constraint has no route parameter: '.$name);
            }
        }
    }

    public function matches(string $path): ?array
    {
        if ($this->regex === null) {
            return $this->path === $path ? [] : null;
        }
        $result = preg_match($this->regex, $path, $matches);
        if ($result === false) {
            throw new \RuntimeException('Route matching failed: '.preg_last_error_msg());
        }
        if ($result === 0) {
            return null;
        }
        return array_intersect_key($matches, array_flip($this->parameters));
    }
}
