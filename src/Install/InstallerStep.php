<?php

declare(strict_types=1);

namespace Nene2\Install;

/**
 * One step of an installer wizard: a machine identifier and the {@see InstallerField}s it
 * collects. It carries no wording — the display text comes from {@see InstallerMessages}
 * so a product owns branding and locale. Ordered into an {@see InstallerFlow}.
 */
final readonly class InstallerStep
{
    /**
     * The fields this step collects, in order (empty for display-only steps). Bare string
     * names are normalised to {@see InstallerField}s of type {@see InstallerInputType::Text}.
     *
     * @var list<InstallerField>
     */
    public array $inputs;

    /**
     * @param string                      $id     Machine identifier, e.g. `requirements`, `database`, `administrator`.
     * @param list<string|InstallerField> $inputs Fields collected. A plain name is treated as a text field;
     *                                            pass an {@see InstallerField} to set a type (e.g. password).
     */
    public function __construct(
        public string $id,
        array $inputs = [],
    ) {
        $this->inputs = array_map(
            static fn (string|InstallerField $input): InstallerField
                => $input instanceof InstallerField ? $input : new InstallerField($input),
            $inputs,
        );
    }
}
