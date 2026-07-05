<?php

declare(strict_types=1);

namespace Nene2\Config;

/**
 * Typed application configuration assembled by {@see ConfigLoader} from environment variables.
 *
 * Inject this value object wherever application-level settings are needed; never call
 * `getenv()` or `$_ENV` directly outside `ConfigLoader`.
 *
 * `$allowDevSecret` reflects the `NENE2_ALLOW_DEV_SECRET` opt-in (strictly `1`/`true`/`yes`)
 * consumed by {@see \Nene2\Auth\GuardedJwtSecretResolver}; it is ignored in production.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class AppConfig
{
    public function __construct(
        public AppEnvironment $environment,
        public bool $debug,
        public string $name,
        public DatabaseConfig $database,
        public ?string $machineApiKey,
        public ?string $localJwtSecret = null,
        public string $problemDetailsBaseUrl = 'https://nene2.dev/problems/',
        public bool $allowDevSecret = false,
    ) {
        if ($this->name === '') {
            throw new ConfigException('APP_NAME must not be empty.');
        }

        if ($this->problemDetailsBaseUrl === '') {
            throw new ConfigException('PROBLEM_DETAILS_BASE_URL must not be empty.');
        }
    }
}
