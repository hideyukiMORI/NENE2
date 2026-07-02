<?php

declare(strict_types=1);

namespace Nene2\Install;

/**
 * Answers whether the target datastore already holds a provisioned installation —
 * the defence-in-depth check {@see ReInstallationGuard} uses when the on-disk
 * `.installed` marker is missing (e.g. an ephemeral `var/` wiped it on redeploy).
 *
 * Product-specific by nature (which table or row proves provisioning), so the
 * installer supplies the implementation; the toolkit stays generic. Optional: when
 * no probe is wired the guard relies on the marker alone.
 */
interface ProvisioningProbe
{
    /**
     * Whether the datastore already contains a provisioned installation. Implementations
     * should return `false` (not throw) when the datastore is simply unreachable or empty,
     * so a genuinely fresh target is not mistaken for a provisioned one.
     */
    public function isProvisioned(): bool;
}
