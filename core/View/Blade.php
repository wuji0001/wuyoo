<?php

declare(strict_types=1);

namespace Wuyoo\View;

use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Component;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Engines\PhpEngine;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Wuyoo\Security\Session;

final class Blade
{
    private Factory $factory;
    private Compiler $compiler;
    private Container $container;
    private ?ContainerContract $previousContainer = null;
    private bool $active = false;
    private bool $closed = false;

    public function __construct(string $views, string $cache, Session $session, string $componentNamespace = '')
    {
        $files = new Filesystem();
        $this->container = new Container();
        $this->compiler = new Compiler($files, $cache);
        $this->compiler->componentNamespace = $componentNamespace;
        $engines = new EngineResolver();
        $engines->register('blade', fn () => new CompilerEngine($this->compiler, $files));
        $engines->register('php', fn () => new PhpEngine($files));
        $this->factory = new Factory($engines, new FileViewFinder($files, [$views]), new Dispatcher($this->container));
        $this->factory->setContainer($this->container);
        $this->container->instance('view', $this->factory);
        $this->container->instance(Factory::class, $this->factory);
        $this->container->instance(\Illuminate\Contracts\View\Factory::class, $this->factory);
        $this->container->instance('blade.compiler', $this->compiler);
        $this->compiler->directive('csrf', fn () => '<?php echo \'<input type="hidden" name="_token" value="\'.htmlspecialchars($__wuyooSession->token(), ENT_QUOTES, \'UTF-8\').\'">\'; ?>');
        $this->factory->share('__wuyooSession', $session);
        $this->container->instance('config', new \Wuyoo\Support\Config(['view' => ['compiled' => $cache]]));
        $previous = Container::getInstance();
        Container::setInstance($this->container);
        try { $this->compiler->anonymousComponentPath($views.'/components'); }
        finally { Container::setInstance($previous); }
    }

    /** Factory and its views must be used within the owning request Scope. */
    public function factory(): Factory
    {
        if ($this->closed) { throw new \LogicException('Blade requires an active request scope'); }
        if (!$this->active) {
            $this->previousContainer = Container::getInstance();
            Component::flushCache();
            Component::forgetFactory();
            Container::setInstance($this->container);
            $this->active = true;
        }
        return $this->factory;
    }

    public function close(): void
    {
        try {
            if ($this->active) { $this->factory->flushState(); }
        } finally {
            if ($this->active) {
                Component::flushCache();
                Component::forgetFactory();
                Container::setInstance($this->previousContainer);
                $this->previousContainer = null;
                $this->active = false;
            }
            $this->closed = true;
        }
    }

    public function directive(string $name, callable $compiler): void { $this->compiler->directive($name, $compiler); }

    public function render(View $view): string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*$/D', $view->name)) {
            throw new \InvalidArgumentException('Invalid view name');
        }
        $data = $view->data;
        foreach (array_keys($data) as $key) {
            if (str_starts_with((string) $key, '__')) { throw new \InvalidArgumentException('Reserved view data key'); }
        }
        return $this->factory()->make($view->name, $data)->render();
    }
}
