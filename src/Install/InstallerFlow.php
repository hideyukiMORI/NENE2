<?php

declare(strict_types=1);

namespace Nene2\Install;

use RuntimeException;

/**
 * The ordered sequence of {@see InstallerStep}s a wizard walks through, with the
 * navigation an orchestrator needs (first/last, next, position "step X of N").
 *
 * The steps are supplied by the caller — the toolkit ships no baked-in flow, because the
 * right steps differ per product (the lesson from dropping a "standard" default
 * elsewhere). Pure and immutable, so it is trivially testable.
 */
final readonly class InstallerFlow
{
    /**
     * @param list<InstallerStep> $steps Non-empty, with unique step ids.
     *
     * @throws RuntimeException if the list is empty or contains duplicate ids.
     */
    public function __construct(
        public array $steps,
    ) {
        if ($steps === []) {
            throw new RuntimeException('An installer flow needs at least one step.');
        }

        $ids = array_map(static fn (InstallerStep $step): string => $step->id, $steps);

        if (count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('Installer step ids must be unique.');
        }
    }

    public function first(): InstallerStep
    {
        return $this->steps[0];
    }

    public function last(): InstallerStep
    {
        return $this->steps[count($this->steps) - 1];
    }

    public function has(string $id): bool
    {
        return $this->indexOf($id) !== null;
    }

    /**
     * @throws RuntimeException if no step has that id.
     */
    public function step(string $id): InstallerStep
    {
        $index = $this->indexOf($id);

        if ($index === null) {
            throw new RuntimeException(sprintf('Unknown installer step: %s', $id));
        }

        return $this->steps[$index];
    }

    /**
     * The step after $id, or null when $id is the last step (or unknown).
     */
    public function next(string $id): ?InstallerStep
    {
        $index = $this->indexOf($id);

        if ($index === null) {
            return null;
        }

        return $this->steps[$index + 1] ?? null;
    }

    public function isLast(string $id): bool
    {
        $index = $this->indexOf($id);

        return $index !== null && $index === count($this->steps) - 1;
    }

    /**
     * 1-based position of the step, for "step X of N" display.
     *
     * @throws RuntimeException if no step has that id.
     */
    public function position(string $id): int
    {
        $index = $this->indexOf($id);

        if ($index === null) {
            throw new RuntimeException(sprintf('Unknown installer step: %s', $id));
        }

        return $index + 1;
    }

    public function count(): int
    {
        return count($this->steps);
    }

    private function indexOf(string $id): ?int
    {
        foreach ($this->steps as $index => $step) {
            if ($step->id === $id) {
                return $index;
            }
        }

        return null;
    }
}
