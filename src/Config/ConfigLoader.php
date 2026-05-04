<?php

declare(strict_types=1);

namespace Nene2\Config;

use Dotenv\Dotenv;

final readonly class ConfigLoader
{
    /**
     * @param array<string, string> $defaults
     */
    public function __construct(
        private string $projectRoot,
        private array $defaults = [
            'APP_ENV' => 'local',
            'APP_DEBUG' => 'false',
            'APP_NAME' => 'NENE2',
        ],
    ) {
    }

    /**
     * @param array<string, string> $overrides
     */
    public function load(array $overrides = []): AppConfig
    {
        $this->loadDotenvIfAvailable();

        $values = array_merge(
            $this->defaults,
            $this->readEnvironmentValues(array_keys($this->defaults)),
            $overrides,
        );

        return new AppConfig(
            AppEnvironment::fromConfigValue($values['APP_ENV']),
            $this->parseBoolean('APP_DEBUG', $values['APP_DEBUG']),
            trim($values['APP_NAME']),
        );
    }

    private function loadDotenvIfAvailable(): void
    {
        if (!is_file($this->projectRoot . '/.env')) {
            return;
        }

        Dotenv::createImmutable($this->projectRoot)->safeLoad();
    }

    /**
     * @param list<string> $keys
     * @return array<string, string>
     */
    private function readEnvironmentValues(array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? null;

            if (is_string($value) && $value !== '') {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    private function parseBoolean(string $key, string $value): bool
    {
        return match (strtolower($value)) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new ConfigException(sprintf('%s must be a boolean value.', $key)),
        };
    }
}
