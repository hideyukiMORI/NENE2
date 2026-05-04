<?php

declare(strict_types=1);

namespace Nene2\DependencyInjection;

interface ServiceProviderInterface
{
    public function register(ContainerBuilder $builder): void;
}
