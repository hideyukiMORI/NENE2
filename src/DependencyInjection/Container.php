<?php

declare(strict_types=1);

namespace Nene2\DependencyInjection;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * @phpstan-type ServiceFactory callable(ContainerInterface): mixed
 */
final class Container implements ContainerInterface
{
    /** @var array<string, ServiceFactory> */
    private array $factories;

    /** @var array<string, mixed> */
    private array $resolved = [];

    /**
     * @param array<string, ServiceFactory> $factories
     */
    public function __construct(array $factories)
    {
        $this->factories = $factories;
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }

        if (!$this->has($id)) {
            throw new ServiceNotFoundException(sprintf('Service "%s" is not defined.', $id));
        }

        try {
            return $this->resolved[$id] = $this->factories[$id]($this);
        } catch (ContainerExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ServiceResolutionException(
                sprintf('Service "%s" could not be resolved.', $id),
                previous: $exception,
            );
        }
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->factories);
    }
}
