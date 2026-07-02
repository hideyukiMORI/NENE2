<?php

declare(strict_types=1);

namespace Nene2\Install;

/**
 * The outcome of validating a proposed tenant configuration.
 *
 * Modelled as a result (not an exception) because invalid input is a normal part of
 * an install wizard's flow — the installer re-prompts. On success `$configuration`
 * holds the normalised values; on failure `$errors` carries machine-readable reason
 * codes the product's template maps to user-facing messages.
 */
final readonly class TenantConfigurationResult
{
    /**
     * @param list<string> $errors Reason codes, e.g. `['unknown_mode']`, `['base_domain_required']`,
     *                             `['base_domain_invalid']`. Empty when valid.
     */
    public function __construct(
        public bool $valid,
        public ?TenantConfiguration $configuration,
        public array $errors,
    ) {
    }

    public static function ok(TenantConfiguration $configuration): self
    {
        return new self(true, $configuration, []);
    }

    /**
     * @param list<string> $errors
     */
    public static function invalid(array $errors): self
    {
        return new self(false, null, $errors);
    }
}
