<?php

declare(strict_types=1);

namespace Nene2\Install;

/**
 * The production {@see SystemProbe}: answers each question straight from the PHP
 * runtime and filesystem. Injected by default into {@see ServerRequirementChecker}
 * so callers get real checks without wiring, while tests substitute a fake.
 */
final class LiveSystemProbe implements SystemProbe
{
    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    public function extensionLoaded(string $extension): bool
    {
        return extension_loaded($extension);
    }

    public function isWritable(string $path): bool
    {
        return is_writable($path);
    }

    public function exists(string $path): bool
    {
        return file_exists($path);
    }
}
