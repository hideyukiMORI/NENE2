<?php

declare(strict_types=1);

namespace Nene2\Install;

/**
 * Read-only view of the runtime and filesystem facts a {@see ServerRequirementChecker}
 * needs to diagnose an installation target.
 *
 * Extracting these behind an interface keeps the checker pure and unit-testable: tests
 * supply a synthetic probe, while production uses {@see LiveSystemProbe}. Part of the
 * opt-in installer toolkit — nothing here runs unless a product's installer calls it.
 */
interface SystemProbe
{
    /**
     * The running PHP version, e.g. `PHP_VERSION` (`"8.4.22"`).
     */
    public function phpVersion(): string;

    /**
     * Whether the named PHP extension is loaded, e.g. `extension_loaded('pdo_mysql')`.
     */
    public function extensionLoaded(string $extension): bool;

    /**
     * Whether the path exists and is writable.
     */
    public function isWritable(string $path): bool;

    /**
     * Whether anything exists at the path (file, directory, or otherwise).
     */
    public function exists(string $path): bool;
}
