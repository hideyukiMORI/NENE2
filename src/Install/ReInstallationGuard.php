<?php

declare(strict_types=1);

namespace Nene2\Install;

use RuntimeException;

/**
 * Refuses to re-run an installer against an already-provisioned target, so a stray
 * second visit to `install.php` cannot overwrite `.env` or recreate the admin account.
 *
 * Two layers: an on-disk `.installed` marker, and — as defence in depth when the
 * marker is lost (an ephemeral `var/` wiped on redeploy) — an optional
 * {@see ProvisioningProbe} that inspects the datastore. The marker is checked first
 * and short-circuits, so the probe is only consulted when the marker is absent.
 *
 * Both the marker path and the probe are injected, keeping the toolkit generic and
 * unit-testable. Part of the opt-in installer toolkit.
 */
final readonly class ReInstallationGuard
{
    public function __construct(
        private string $markerPath,
        private ?ProvisioningProbe $provisioningProbe = null,
    ) {
    }

    /**
     * Why installation must be refused, or `null` when it may proceed.
     *
     * @return 'marker_present'|'database_provisioned'|null
     */
    public function blockedReason(): ?string
    {
        if (is_file($this->markerPath)) {
            return 'marker_present';
        }

        if ($this->provisioningProbe !== null && $this->provisioningProbe->isProvisioned()) {
            return 'database_provisioned';
        }

        return null;
    }

    /**
     * Whether installation must be refused.
     */
    public function isBlocked(): bool
    {
        return $this->blockedReason() !== null;
    }

    /**
     * Write the `.installed` marker so a later run is refused. Written atomically
     * (sibling temp file then rename) for consistency with the rest of the install.
     *
     * @param string $note Optional non-secret content (e.g. an ISO timestamp) for operators.
     *
     * @throws RuntimeException if the marker's directory is missing or the write fails.
     */
    public function markInstalled(string $note = ''): void
    {
        $dir = dirname($this->markerPath);

        if (!is_dir($dir)) {
            throw new RuntimeException(sprintf('The installation marker directory does not exist: %s', $dir));
        }

        $tmp = $this->markerPath . '.tmp.' . bin2hex(random_bytes(6));

        if (@file_put_contents($tmp, $note) === false) {
            throw new RuntimeException('Could not write the installation marker.');
        }

        if (!@rename($tmp, $this->markerPath)) {
            @unlink($tmp);

            throw new RuntimeException('Could not save the installation marker.');
        }
    }
}
