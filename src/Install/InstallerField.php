<?php

declare(strict_types=1);

namespace Nene2\Install;

/**
 * A single input an {@see InstallerStep} collects: the field name plus its
 * {@see InstallerInputType}. Carries no wording — labels come from the product's
 * {@see InstallerMessages}, like the rest of the neutral toolkit.
 *
 * A bare field name (string) passed to a step is promoted to a `Text` field, so existing
 * callers keep working and only fields that need it declare a different type.
 */
final readonly class InstallerField
{
    public function __construct(
        public string $name,
        public InstallerInputType $type = InstallerInputType::Text,
    ) {
    }

    public static function text(string $name): self
    {
        return new self($name, InstallerInputType::Text);
    }

    public static function password(string $name): self
    {
        return new self($name, InstallerInputType::Password);
    }
}
