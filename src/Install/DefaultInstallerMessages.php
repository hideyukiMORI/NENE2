<?php

declare(strict_types=1);

namespace Nene2\Install;

/**
 * The neutral, unbranded {@see InstallerMessages}: plain-English wording for the reason
 * codes the toolkit itself emits. It names no product, so any installer can use it and a
 * product can wrap or replace it for translation or branding.
 *
 * A default catalogue here is safe (unlike a baked-in tenant vocabulary) because these
 * codes are produced by shipped toolkit classes — they are universal, not a per-product
 * assumption. Unknown codes fall back to a generic message so the UI never shows a bare
 * code or breaks.
 */
final readonly class DefaultInstallerMessages implements InstallerMessages
{
    /** @var array<string, string> */
    private const MESSAGES = [
        // ServerRequirementChecker
        'php_too_old' => 'The installed PHP version is too old. Switch your hosting to a newer PHP version and retry.',
        'extension_missing' => 'A required PHP extension is not enabled. Ask your host to enable it, then retry.',
        'not_writable' => 'A required file or directory is not writable. Adjust its permissions and retry.',
        // TenantConfigurationValidator
        'unknown_mode' => 'The selected tenancy mode is not supported by this product.',
        'base_domain_required' => 'This tenancy mode needs a base domain. Enter one to continue.',
        'base_domain_invalid' => 'The base domain format is invalid. Use letters, digits, dots and hyphens only.',
        // ReInstallationGuard
        'marker_present' => 'This application is already installed. Remove the installer to continue using it.',
        'database_provisioned' => 'A provisioned database already exists, so installation was refused.',
    ];

    private const FALLBACK = 'An installation check did not pass. Review the details and try again.';

    public function forReasonCode(string $reasonCode): string
    {
        return self::MESSAGES[$reasonCode] ?? self::FALLBACK;
    }
}
