<?php

declare(strict_types=1);

namespace Nene2\Config;

/**
 * Typed database configuration assembled by {@see ConfigLoader} from environment variables.
 *
 * **SQLite**: only `adapter` and `name` are required. Pass empty strings for
 * `host`, `user`, `password`, and `charset` — they are not validated when
 * `adapter` is `'sqlite'`. `port` is also ignored; any value (e.g. `1`) is accepted.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class DatabaseConfig
{
    public function __construct(
        public ?string $url,
        public string $environment,
        public string $adapter,
        public string $host,
        public int $port,
        public string $name,
        public string $user,
        public string $password,
        public string $charset,
    ) {
        if ($this->url === '') {
            throw new ConfigException('DATABASE_URL must not be empty when provided.');
        }

        foreach ($this->requiredValues() as $key => $value) {
            if ($value === '') {
                throw new ConfigException(sprintf('%s must not be empty.', $key));
            }
        }

        if ($this->adapter !== 'sqlite' && ($this->port < 1 || $this->port > 65_535)) {
            throw new ConfigException('DB_PORT must be between 1 and 65535.');
        }
    }

    public function usesUrl(): bool
    {
        return $this->url !== null;
    }

    /**
     * @return array<string, string>
     */
    private function requiredValues(): array
    {
        $required = [
            'DB_ENV' => $this->environment,
            'DB_ADAPTER' => $this->adapter,
            'DB_NAME' => $this->name,
        ];

        if ($this->adapter !== 'sqlite') {
            $required['DB_HOST'] = $this->host;
            $required['DB_USER'] = $this->user;
            $required['DB_CHARSET'] = $this->charset;
        }

        return $required;
    }
}
