<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use ReflectionFunction;
use ReflectionNamedType;
use RuntimeException;

final class ControllerDispatcher
{
    public function __construct(private readonly Container $container)
    {
    }

    public function dispatch(Route $route, Request $request): mixed
    {
        $handler = $route->handler();

        if ($handler instanceof Closure) {
            return $this->invoke($handler, $request);
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            $controller = is_object($class) ? $class : $this->container->get((string) $class);

            if (!method_exists($controller, (string) $method)) {
                throw new RuntimeException(sprintf(
                    'Controller method [%s::%s] does not exist.',
                    $controller::class,
                    (string) $method,
                ));
            }

            return $this->invoke([$controller, (string) $method], $request);
        }

        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);

            return $this->dispatch(
                new Route([], $route->uri(), [$class, $method], static fn () => null),
                $request,
            );
        }

        throw new RuntimeException('Unsupported route handler. Use a closure or [Controller::class, method].');
    }

    /**
     * @param Closure|array{0: object, 1: string} $callable
     */
    private function invoke(Closure|array $callable, Request $request): mixed
    {
        $reflection = new ReflectionFunction(Closure::fromCallable($callable));
        $arguments = [];

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = $parameter->getName();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $class = $type->getName();

                if (is_a($class, Request::class, true)) {
                    $arguments[] = $request;
                    continue;
                }

                if (is_a($class, FormRequest::class, true)) {
                    /** @var FormRequest $form */
                    $form = new $class($request);
                    $form->validateRequest();
                    $arguments[] = $form;
                    continue;
                }

                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();
                    continue;
                }

                $arguments[] = $this->container->get($class);
                continue;
            }

            $routeParams = $request->routeParams();

            if (array_key_exists($name, $routeParams)) {
                $value = $routeParams[$name];

                if ($type instanceof ReflectionNamedType && $type->getName() === 'int') {
                    $value = (int) $value;
                }

                $arguments[] = $value;
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            if ($parameter->allowsNull()) {
                $arguments[] = null;
                continue;
            }

            throw new RuntimeException(sprintf(
                'Cannot resolve parameter [$%s] for %s.',
                $name,
                $reflection->getName(),
            ));
        }

        return $reflection->invokeArgs($arguments);
    }
}
