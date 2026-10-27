<?php

declare(strict_types=1);

namespace Wuyoo\View;

final class Compiler extends \Illuminate\View\Compilers\BladeCompiler
{
    public string $componentNamespace = '';

    protected function compileComponentTags($value)
    {
        if (!$this->compilesComponentTags) { return $value; }
        $tags = new ComponentTagCompiler($this->classComponentAliases, $this->classComponentNamespaces, $this);
        $tags->componentNamespace = $this->componentNamespace;
        return $tags->compile($value);
    }
}
