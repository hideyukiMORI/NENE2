<?php

declare(strict_types=1);

namespace Nene2\Config;

final readonly class AppConfig
{
    public function __construct(
        public AppEnvironment $environment,
        public bool $debug,
        public string $name,
        public DatabaseConfig $database,
        public ?string $machineApiKey,
        public ?string $localJwtSecret = null,
    ) {
        if ($this->name === '') {
            throw new ConfigException('APP_NAME must not be empty.');
        }
    }
}
