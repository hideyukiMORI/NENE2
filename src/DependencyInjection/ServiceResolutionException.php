<?php

declare(strict_types=1);

namespace Nene2\DependencyInjection;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

final class ServiceResolutionException extends RuntimeException implements ContainerExceptionInterface
{
}
