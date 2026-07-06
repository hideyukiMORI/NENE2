<?php

declare(strict_types=1);

namespace Nene2\Conformance;

/**
 * A conformance rule: a pure, side-effect-free check over a project tree.
 *
 * Rules MUST NOT mutate the filesystem and MUST return findings deterministically
 * for a given tree, so they are unit-testable against fixture directories.
 */
interface RuleInterface
{
    /**
     * Short, stable identifier used in output and baseline entries (e.g. `D1`).
     */
    public function id(): string;

    /**
     * One-line human description of what the rule enforces.
     */
    public function description(): string;

    /**
     * Inspect the project rooted at `$root` (absolute path) and return findings.
     *
     * @return list<Finding>
     */
    public function check(string $root): array;
}
