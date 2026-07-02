<?php

declare(strict_types=1);

namespace Nene2\Install;

/**
 * Diagnoses whether a server meets a product's {@see ServerRequirements} before an
 * install proceeds — the PHP version, required extensions, writable paths, and files
 * that must already be present.
 *
 * Purely diagnostic: it never mutates the filesystem (a missing writable path is
 * reported as `creatable` when its parent is writable, rather than being created
 * here). All runtime facts come from an injected {@see SystemProbe}, so the logic is
 * fully unit-testable. Results are machine-readable {@see RequirementCheck}s; the
 * product's installer template maps them to user-facing labels and fixes.
 *
 * Part of the opt-in installer toolkit — dormant unless a product's installer calls it.
 */
final readonly class ServerRequirementChecker
{
    public const REQUIREMENT_PHP = 'php_version';
    public const REQUIREMENT_EXTENSION = 'extension';
    public const REQUIREMENT_WRITABLE = 'writable_path';
    public const REQUIREMENT_FILE = 'required_file';

    public function __construct(
        private SystemProbe $probe = new LiveSystemProbe(),
    ) {
    }

    /**
     * @return list<RequirementCheck> One check per PHP version, extension, writable path and required file.
     */
    public function check(ServerRequirements $requirements): array
    {
        $checks = [$this->checkPhpVersion($requirements->minPhpVersion)];

        foreach ($requirements->requiredExtensions as $extension) {
            $loaded = $this->probe->extensionLoaded($extension);
            $checks[] = new RequirementCheck(
                self::REQUIREMENT_EXTENSION,
                $extension,
                $loaded,
                [$loaded ? 'extension_loaded' : 'extension_missing'],
            );
        }

        foreach ($requirements->writablePaths as $path) {
            [$satisfied, $reason] = $this->evaluateWritable($path);
            $checks[] = new RequirementCheck(self::REQUIREMENT_WRITABLE, $path, $satisfied, [$reason]);
        }

        foreach ($requirements->requiredFiles as $file) {
            $present = $this->probe->exists($file);
            $checks[] = new RequirementCheck(
                self::REQUIREMENT_FILE,
                $file,
                $present,
                [$present ? 'present' : 'missing'],
            );
        }

        return $checks;
    }

    /**
     * Convenience: whether every check in the list is satisfied.
     *
     * @param list<RequirementCheck> $checks
     */
    public static function allSatisfied(array $checks): bool
    {
        foreach ($checks as $check) {
            if (!$check->satisfied) {
                return false;
            }
        }

        return true;
    }

    /**
     * Convenience: the subset of checks that are not satisfied.
     *
     * @param list<RequirementCheck> $checks
     *
     * @return list<RequirementCheck>
     */
    public static function unmet(array $checks): array
    {
        return array_values(array_filter($checks, static fn (RequirementCheck $c): bool => !$c->satisfied));
    }

    private function checkPhpVersion(string $minVersion): RequirementCheck
    {
        $satisfied = version_compare($this->probe->phpVersion(), $minVersion, '>=');

        return new RequirementCheck(
            self::REQUIREMENT_PHP,
            $minVersion,
            $satisfied,
            [$satisfied ? 'php_ok' : 'php_too_old'],
        );
    }

    /**
     * @return array{0: bool, 1: string} [satisfied, reasonCode] — `writable` when a writable dir/file
     *         already exists, `creatable` when the path is absent but its parent is writable, otherwise
     *         `not_writable`.
     */
    private function evaluateWritable(string $path): array
    {
        if ($this->probe->exists($path)) {
            return $this->probe->isWritable($path) ? [true, 'writable'] : [false, 'not_writable'];
        }

        return $this->probe->isWritable(dirname($path)) ? [true, 'creatable'] : [false, 'not_writable'];
    }
}
