<?php

namespace Wuyoo\View;

final readonly class View
{
    public function __construct(public string $name, public array $data = []) {}
}
