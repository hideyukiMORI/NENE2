<?php

declare(strict_types=1);

namespace Nene2\DependencyInjection;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final class ServiceNotFoundException extends RuntimeException implements NotFoundExceptionInterface
{
}
