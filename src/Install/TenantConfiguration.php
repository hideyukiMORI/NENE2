<?php

declare(strict_types=1);

namespace Nene2\Install;

/**
 * A validated, normalised tenant resolution configuration produced by
 * {@see TenantConfigurationValidator}.
 *
 * `$mode` is one of the product's allowed resolution modes (e.g. `single`, `path`,
 * `subdomain`, `custom_domain`); the installer writes this verbatim to wherever the
 * product reads it (invoice reads `.env`'s `TENANT_RESOLUTION`). `$baseDomain` is set
 * only for modes that need one and is `''` otherwise.
 */
final readonly class TenantConfiguration
{
    public function __construct(
        public string $mode,
        public string $baseDomain,
    ) {
    }
}
