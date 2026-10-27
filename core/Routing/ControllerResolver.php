<?php

declare(strict_types=1);

namespace Wuyoo\Routing;

use BackedEnum;
use Closure;
use LogicException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use Wuyoo\Http\HttpException;
use Wuyoo\Support\Scope;

final class ControllerResolver
{
    public function invoke(mixed $handler, array $routeParameters, Scope $scope, array $legacyNames = []): mixed
    {
        if (is_string($handler) && str_contains($handler, '@')) {
            $handler = explode('@', $handler, 2);
        }
        if (is_array($handler) && count($handler) === 2) {
            [$class, $action] = $handler;
            if (!is_string($action) || str_starts_with($action, '__')) {
                throw new LogicException('Unsafe controller action');
            }
            $method = new ReflectionMethod($class, $action);
            if (!$method->isPublic() || $method->isStatic()) {
                throw new LogicException('Controller action must be a public instance method');
            }
            $controller = is_object($class) ? $class : $this->controller($class, $scope);
            return $method->invokeArgs($controller, $this->arguments($method, $routeParameters, $scope, $legacyNames));
        }
        if (!$handler instanceof Closure && !is_object($handler)) {
            throw new LogicException('Handlers must be closures, invokable objects, or Controller@action');
        }
        $reflection = $handler instanceof Closure ? new ReflectionFunction($handler) : new ReflectionMethod($handler, '__invoke');
        return $handler(...$this->arguments($reflection, $routeParameters, $scope, $legacyNames));
    }

    private function controller(string $class, Scope $scope): object
    {
        if ($scope->has($class)) {
            return $scope->get($class);
        }
        return $scope->getOrCreate($class, static function (Scope $scope) use ($class): object {
            $reflection = new ReflectionClass($class);
            if (!$reflection->isInstantiable()) {
                throw new LogicException('Controller '.$class.' is not instantiable; register an explicit controller factory');
            }
            $arguments = [];
            $constructor = $reflection->getConstructor();
            foreach ($constructor?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();
                $context = $class.'::__construct($'.$parameter->getName().')';
                if ($parameter->isVariadic() || ($type !== null && !$type instanceof ReflectionNamedType)) {
                    throw new LogicException('Complex controller parameter '.$context.'; register an explicit controller factory');
                }
                if ($type !== null && !$type->isBuiltin()) {
                    $dependency = match ($type->getName()) {
                        'self' => $constructor->getDeclaringClass()->getName(),
                        'parent' => $constructor->getDeclaringClass()->getParentClass()->getName(),
                        default => $type->getName(),
                    };
                    if ($scope->has($dependency)) {
                        $arguments[] = $scope->get($dependency);
                        continue;
                    }
                }
                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();
                    continue;
                }
                throw new LogicException('Cannot resolve controller parameter '.$context.' ('.($type ?? 'untyped').'); register its service, provide a default, or register an explicit controller factory');
            }
            return $reflection->newInstanceArgs($arguments);
        });
    }

    private function arguments(ReflectionFunctionAbstract $function, array $values, Scope $scope, array $legacyNames): array
    {
        $arguments = [];
        $legacy = array_intersect_key($values, array_flip($legacyNames));
        foreach ($function->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($parameter->isVariadic() || ($type !== null && !$type instanceof ReflectionNamedType)) {
                throw new LogicException('Use simple named parameter types in actions');
            }
            $name = $parameter->getName();
            $typeName = $type?->getName();
            if ($type !== null && !$type->isBuiltin() && !is_subclass_of($typeName, BackedEnum::class)) {
                $arguments[] = $scope->get($typeName);
                continue;
            }
            if (array_key_exists($name, $values)) {
                $value = $values[$name];
                unset($legacy[$name]);
            } elseif ($legacy !== []) {
                $key = array_key_first($legacy);
                $value = $legacy[$key];
                unset($legacy[$key]);
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            } else {
                throw new LogicException('Missing action route parameter: '.$name);
            }
            $arguments[] = $this->cast($value, $typeName);
        }
        return $arguments;
    }

    private function cast(string $value, ?string $type): mixed
    {
        if ($type === null || $type === 'string') {
            return $value;
        }
        if ($type === 'int' && preg_match('/^-?(0|[1-9][0-9]*)$/D', $value) && filter_var($value, FILTER_VALIDATE_INT) !== false) {
            return (int) $value;
        }
        if ($type === 'float' && is_numeric($value) && is_finite((float) $value)) {
            return (float) $value;
        }
        if ($type === 'bool' && in_array($value, ['true', 'false', '0', '1'], true)) {
            return in_array($value, ['true', '1'], true);
        }
        if ($type !== null && is_subclass_of($type, BackedEnum::class)) {
            try {
                $backing = (new \ReflectionEnum($type))->getBackingType()->getName();
                $enum = $type::tryFrom($backing === 'int' ? $this->cast($value, 'int') : $value);
                if ($enum !== null) {
                    return $enum;
                }
            } catch (\ValueError|\TypeError) {}
        }
        throw new HttpException(400, 'Invalid route parameter');
    }
}
