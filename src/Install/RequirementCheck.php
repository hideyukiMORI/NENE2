<?php

declare(strict_types=1);

namespace Nene2\Install;

/**
 * The structured result of a single server requirement check.
 *
 * Carries machine-readable fields only — a requirement kind, the target that was
 * checked, whether it is satisfied, and reason codes — so a product's installer
 * template owns all user-facing wording (labels, fixes, translations). This keeps
 * the toolkit free of UI and locale assumptions.
 */
final readonly class RequirementCheck
{
    /**
     * @param string       $requirement One of {@see ServerRequirementChecker}'s `REQUIREMENT_*` kinds.
     * @param string       $target      What was required (min PHP version, extension name, or path).
     * @param bool         $satisfied   Whether the requirement is met.
     * @param list<string> $reasonCodes Machine-readable codes explaining the outcome, e.g. `['php_too_old']`,
     *                                   `['extension_missing']`, `['creatable']`.
     */
    public function __construct(
        public string $requirement,
        public string $target,
        public bool $satisfied,
        public array $reasonCodes,
    ) {
    }
}
