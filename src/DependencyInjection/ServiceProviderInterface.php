<?php

declare(strict_types=1);

namespace Nene2\DependencyInjection;

/**
 * Groups related service definitions and registers them with the container.
 *
 * Pass implementations to {@see ContainerBuilder::addProvider()}.
 */
interface ServiceProviderInterface
{
    public function register(ContainerBuilder $builder): void;
}
