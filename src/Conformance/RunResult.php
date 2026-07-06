<?php

declare(strict_types=1);

namespace Nene2\Conformance;

/**
 * Outcome of a conformance run: the findings that survived suppression, and how
 * many were masked by the baseline/allowlist/inline ignores.
 */
final readonly class RunResult
{
    /**
     * @param list<Finding> $findings active (non-suppressed) findings
     */
    public function __construct(
        public array $findings,
        public int $suppressed,
    ) {
    }

    /**
     * @return list<Finding>
     */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (Finding $f): bool => $f->severity === Severity::Error,
        ));
    }

    public function exitCode(): int
    {
        return $this->errors() === [] ? 0 : 1;
    }
}
