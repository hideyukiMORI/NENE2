<?php

declare(strict_types=1);

namespace Nene2\DependencyInjection;

use Psr\Container\ContainerInterface;

/**
 * Fluent builder for the PSR-11 service container.
 *
 * Register service factories with {@see set()} and {@see value()}, group related services
 * with {@see addProvider()}, then call {@see build()} to obtain the immutable container.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 *
 * @phpstan-import-type ServiceFactory from Container
 */
final class ContainerBuilder
{
    /** @var array<string, ServiceFactory> */
    private array $factories = [];

    /**
     * @param ServiceFactory $factory
     */
    public function set(string $id, callable $factory): self
    {
        $this->factories[$id] = $factory;

        return $this;
    }

    public function value(string $id, mixed $value): self
    {
        return $this->set(
            $id,
            static fn (ContainerInterface $container): mixed => $value,
        );
    }

    public function addProvider(ServiceProviderInterface $provider): self
    {
        $provider->register($this);

        return $this;
    }

    public function build(): ContainerInterface
    {
        return new Container($this->factories);
    }
}
