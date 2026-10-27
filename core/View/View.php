<?php

declare(strict_types=1);

namespace Wuyoo\View;

final readonly class View
{
    public function __construct(public string $name, public array $data = []) {}
}
