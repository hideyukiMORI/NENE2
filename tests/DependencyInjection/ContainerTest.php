<?php

declare(strict_types=1);

namespace Nene2\Tests\DependencyInjection;

use LogicException;
use Nene2\DependencyInjection\ContainerBuilder;
use Nene2\DependencyInjection\ServiceNotFoundException;
use Nene2\DependencyInjection\ServiceProviderInterface;
use Nene2\DependencyInjection\ServiceResolutionException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class ContainerTest extends TestCase
{
    public function testResolvesExplicitFactoryAndCachesResult(): void
    {
        $resolvedCount = 0;
        $container = (new ContainerBuilder())
            ->set('service', static function (ContainerInterface $container) use (&$resolvedCount): object {
                $resolvedCount++;

                return new class () {
                };
            })
            ->build();

        $first = $container->get('service');
        $second = $container->get('service');

        self::assertSame($first, $second);
        self::assertSame(1, $resolvedCount);
    }

    public function testResolvesValueDefinitions(): void
    {
        $container = (new ContainerBuilder())
            ->value('config.name', 'NENE2')
            ->build();

        self::assertTrue($container->has('config.name'));
        self::assertSame('NENE2', $container->get('config.name'));
    }

    public function testServiceProviderRegistersDefinitions(): void
    {
        $container = (new ContainerBuilder())
            ->addProvider(new class () implements ServiceProviderInterface {
                public function register(ContainerBuilder $builder): void
                {
                    $builder->value('registered', true);
                }
            })
            ->build();

        self::assertTrue($container->get('registered'));
    }

    public function testMissingServiceThrowsPsr11NotFoundException(): void
    {
        $container = (new ContainerBuilder())->build();

        $this->expectException(ServiceNotFoundException::class);

        $container->get('missing');
    }

    public function testFactoryFailureThrowsPsr11ContainerException(): void
    {
        $container = (new ContainerBuilder())
            ->set(
                'broken',
                static fn (ContainerInterface $container): never => throw new LogicException('Broken factory.'),
            )
            ->build();

        $this->expectException(ServiceResolutionException::class);

        $container->get('broken');
    }
}
