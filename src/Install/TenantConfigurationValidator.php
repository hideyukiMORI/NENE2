<?php

declare(strict_types=1);

namespace Nene2\Install;

/**
 * Validates and normalises the tenant resolution mode an installer collected before it
 * is written to the product's configuration.
 *
 * Generic by construction: the set of allowed modes — and which of them need a base
 * domain — is supplied by the caller, so the toolkit bakes in no product vocabulary.
 * This is deliberate: the value written here must match the value the product later
 * reads (invoice reads `.env`'s `TENANT_RESOLUTION`), so letting the product declare
 * its own modes keeps installer and runtime in lock-step. {@see standard()} offers the
 * common NeNe set for convenience.
 *
 * A mode that needs a base domain (typically `subdomain`) requires a non-empty domain
 * matching `^[A-Za-z0-9.-]+$`; any other mode has its base domain normalised to `''`.
 * Part of the opt-in installer toolkit.
 */
final readonly class TenantConfigurationValidator
{
    private const BASE_DOMAIN_PATTERN = '/^[A-Za-z0-9.-]+$/';

    /**
     * @param list<string> $allowedModes             Modes the product accepts, e.g.
     *                                                `['single', 'path', 'subdomain', 'custom_domain']`.
     * @param list<string> $modesRequiringBaseDomain Subset of `$allowedModes` that need a base domain.
     */
    public function __construct(
        private array $allowedModes,
        private array $modesRequiringBaseDomain = [],
    ) {
    }

    /**
     * The common NeNe resolution vocabulary: `single` / `path` / `subdomain` / `custom_domain`,
     * where only `subdomain` needs a base domain.
     */
    public static function standard(): self
    {
        return new self(
            ['single', 'path', 'subdomain', 'custom_domain'],
            ['subdomain'],
        );
    }

    /**
     * Validate a proposed mode and (optional) base domain, returning a normalised result.
     */
    public function validate(string $mode, ?string $baseDomain = null): TenantConfigurationResult
    {
        if (!in_array($mode, $this->allowedModes, true)) {
            return TenantConfigurationResult::invalid(['unknown_mode']);
        }

        $trimmed = trim($baseDomain ?? '');

        if (!in_array($mode, $this->modesRequiringBaseDomain, true)) {
            // Base domain is meaningless for this mode — normalise it away.
            return TenantConfigurationResult::ok(new TenantConfiguration($mode, ''));
        }

        if ($trimmed === '') {
            return TenantConfigurationResult::invalid(['base_domain_required']);
        }

        if (preg_match(self::BASE_DOMAIN_PATTERN, $trimmed) !== 1) {
            return TenantConfigurationResult::invalid(['base_domain_invalid']);
        }

        return TenantConfigurationResult::ok(new TenantConfiguration($mode, $trimmed));
    }
}
