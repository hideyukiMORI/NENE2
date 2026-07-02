<?php

declare(strict_types=1);

namespace Nene2\Install;

/**
 * The set of server requirements a product's installer expects, checked by
 * {@see ServerRequirementChecker}. Supplying this as a value object keeps the
 * toolkit generic: each product declares its own minimum PHP version, extensions,
 * writable paths and required files — the framework carries no product assumptions.
 */
final readonly class ServerRequirements
{
    /**
     * @param string       $minPhpVersion      Minimum PHP version, e.g. `"8.4.0"` (compared with `version_compare`).
     * @param list<string> $requiredExtensions PHP extensions that must be loaded, e.g. `['pdo_mysql', 'mbstring']`.
     * @param list<string> $writablePaths      Paths that must be writable (or creatable), e.g. `['/app/var', '/app']`.
     * @param list<string> $requiredFiles      Paths that must already exist, e.g. `['/app/vendor/autoload.php']`.
     */
    public function __construct(
        public string $minPhpVersion,
        public array $requiredExtensions = [],
        public array $writablePaths = [],
        public array $requiredFiles = [],
    ) {
    }
}
