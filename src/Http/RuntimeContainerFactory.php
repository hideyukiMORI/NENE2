<?php

declare(strict_types=1);

namespace Nene2\Http;

use Nene2\DependencyInjection\ContainerBuilder;
use Psr\Container\ContainerInterface;

final readonly class RuntimeContainerFactory
{
    public function __construct(
        private ?string $projectRoot = null,
    ) {
    }

    public function create(): ContainerInterface
    {
        $projectRoot = $this->projectRoot ?? dirname(__DIR__, 2);

        return (new ContainerBuilder())
            ->value(RuntimeServiceProvider::PROJECT_ROOT, $projectRoot)
            ->addProvider(new RuntimeServiceProvider())
            ->build();
    }
}
