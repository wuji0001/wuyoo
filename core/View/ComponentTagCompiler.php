<?php

declare(strict_types=1);

namespace Wuyoo\View;

/** Replace only Laravel Application namespace discovery; keep upstream component parsing. */
final class ComponentTagCompiler extends \Illuminate\View\Compilers\ComponentTagCompiler
{
    public string $componentNamespace = '';

    public function guessClassName(string $component)
    {
        return trim($this->componentNamespace, '\\').'\\'.$this->formatClassName($component);
    }
}
