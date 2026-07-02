<?php

declare(strict_types=1);

namespace Nene2\Install;

/**
 * One step of an installer wizard: a machine identifier and the names of the inputs it
 * collects. It carries no wording — the display text comes from {@see InstallerMessages}
 * so a product owns branding and locale. Ordered into an {@see InstallerFlow}.
 */
final readonly class InstallerStep
{
    /**
     * @param string       $id     Machine identifier, e.g. `requirements`, `database`, `administrator`.
     * @param list<string> $inputs Names of the fields this step collects (empty for display-only steps).
     */
    public function __construct(
        public string $id,
        public array $inputs = [],
    ) {
    }
}
