<?php

declare(strict_types=1);

namespace Nene2\Demo;

use Nene2\Config\ConfigException;

/**
 * Typed configuration for the disposable-demo module, assembled by
 * {@see \Nene2\Config\ConfigLoader} from the `DEMO_*` environment variables and
 * carried on {@see \Nene2\Config\AppConfig::$demo}.
 *
 * This replaces the previous consumer pattern of `getenv('DEMO_MODE')` in the
 * handler and `getenv('DEMO_TTL_HOURS')` in an undocumented sweep script — every
 * knob is typed, defaulted, validated, and documented in `.env.example` in one
 * place. Never read the `DEMO_*` variables directly.
 *
 * Defaults are the values invoice ran in production (TTL 3h, ceiling 200 orgs,
 * 5 slug attempts). `$demoMode` defaults to OFF and is parsed strictly (only
 * `1`/`true`/`yes` enable it): the demo route creates organizations without
 * authentication, so a configuration typo must fail closed, never open.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class DemoConfig
{
    /**
     * @param bool $demoMode Master switch; when false the demo endpoint answers 404.
     * @param string $slugPrefix Slug namespace separating disposable orgs from real ones;
     *        the sweeper's selection and the guard's count key off it.
     * @param int $ttlHours Hours a demo org lives before {@see DisposableDemoSweeper} expires it.
     * @param int $maxOrgs Instance-wide ceiling on concurrently existing demo orgs.
     * @param int $slugAttempts Random slug candidates tried before a conflict is fatal.
     */
    public function __construct(
        public bool $demoMode = false,
        public string $slugPrefix = 'demo-',
        public int $ttlHours = 3,
        public int $maxOrgs = 200,
        public int $slugAttempts = 5,
    ) {
        if (trim($this->slugPrefix) === '') {
            throw new ConfigException('DEMO_SLUG_PREFIX must not be empty: without a prefix the sweeper cannot tell disposable demo organizations from real ones.');
        }

        if ($this->ttlHours < 1) {
            throw new ConfigException('DEMO_TTL_HOURS must be a positive integer.');
        }

        if ($this->maxOrgs < 1) {
            throw new ConfigException('DEMO_MAX_ORGS must be a positive integer.');
        }

        if ($this->slugAttempts < 1) {
            throw new ConfigException('DEMO_SLUG_ATTEMPTS must be a positive integer.');
        }
    }
}
