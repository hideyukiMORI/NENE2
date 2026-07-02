<?php

declare(strict_types=1);

namespace Nene2\Install;

/**
 * Turns a toolkit reason code (e.g. `php_too_old`, `base_domain_required`) into a
 * human-readable message for the installer UI.
 *
 * This is the seam that keeps the wizard reason-code-driven: the orchestrator branches
 * on the machine codes emitted by {@see ServerRequirementChecker},
 * {@see TenantConfigurationValidator} and {@see ReInstallationGuard}, and asks this for
 * the wording — never by matching exception message strings. A product swaps in its own
 * implementation to translate or rebrand. {@see DefaultInstallerMessages} is the neutral,
 * unbranded default.
 */
interface InstallerMessages
{
    /**
     * A display message for the given reason code, or a safe generic fallback for an
     * unrecognised code (never throws — the UI always has something to show).
     */
    public function forReasonCode(string $reasonCode): string;
}
