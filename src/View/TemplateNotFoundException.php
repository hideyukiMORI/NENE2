<?php

declare(strict_types=1);

namespace Nene2\View;

use RuntimeException;

/**
 * Thrown by {@see NativePhpViewRenderer} when the requested template file does not exist.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final class TemplateNotFoundException extends RuntimeException
{
}
