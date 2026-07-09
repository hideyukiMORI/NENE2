<?php

declare(strict_types=1);

namespace Nene2\Demo;

use RuntimeException;

/**
 * Thrown by a {@see DisposableOrgProvisionerInterface} when the requested slug is
 * already taken. {@see StartDisposableDemoHandler} treats it as retryable and
 * generates a fresh random slug, up to {@see DemoConfig::$slugAttempts} times.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final class SlugConflictException extends RuntimeException
{
}
