<?php

declare(strict_types=1);

namespace Nene2\Http;

use Nene2\DependencyInjection\ContainerBuilder;
use Psr\Container\ContainerInterface;

final readonly class RuntimeContainerFactory
{
    public function create(): ContainerInterface
    {
        return (new ContainerBuilder())
            ->addProvider(new RuntimeServiceProvider())
            ->build();
    }
}
