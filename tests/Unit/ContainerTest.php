<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Container;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ContainerTest extends TestCase
{
    public function test_autowires_nested_dependencies(): void
    {
        $container = new Container();

        $service = $container->get(NeedsDependency::class);

        $this->assertInstanceOf(NeedsDependency::class, $service);
        $this->assertInstanceOf(SimpleDependency::class, $service->dependency);
        $this->assertSame($service, $container->get(NeedsDependency::class));
    }

    public function test_uses_default_scalar_values(): void
    {
        $container = new Container();

        $service = $container->get(HasScalarDefault::class);

        $this->assertSame('fallback', $service->name);
    }

    public function test_fails_when_a_required_scalar_cannot_be_resolved(): void
    {
        $container = new Container();

        $this->expectException(RuntimeException::class);

        $container->get(HasRequiredScalar::class);
    }

    public function test_bindings_resolve_once(): void
    {
        $container = new Container();
        $calls = 0;

        $container->bind('service', static function () use (&$calls): SimpleDependency {
            $calls++;

            return new SimpleDependency();
        });

        $first = $container->get('service');
        $second = $container->get('service');

        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);
    }

    public function test_instance_binding_wins(): void
    {
        $container = new Container();
        $instance = new SimpleDependency();

        $container->instance(SimpleDependency::class, $instance);

        $this->assertSame($instance, $container->get(SimpleDependency::class));
    }

    public function test_unknown_identifier_fails(): void
    {
        $container = new Container();

        $this->expectException(RuntimeException::class);

        $container->get('missing.service');
    }
}

final class SimpleDependency
{
}

final class NeedsDependency
{
    public function __construct(public readonly SimpleDependency $dependency)
    {
    }
}

final class HasScalarDefault
{
    public function __construct(public readonly string $name = 'fallback')
    {
    }
}

final class HasRequiredScalar
{
    public function __construct(public readonly string $name)
    {
    }
}
