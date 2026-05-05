<?php

declare(strict_types=1);

namespace Nene2\Config;

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

        if ($this->port < 1 || $this->port > 65_535) {
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
        return [
            'DB_ENV' => $this->environment,
            'DB_ADAPTER' => $this->adapter,
            'DB_HOST' => $this->host,
            'DB_NAME' => $this->name,
            'DB_USER' => $this->user,
            'DB_CHARSET' => $this->charset,
        ];
    }
}
