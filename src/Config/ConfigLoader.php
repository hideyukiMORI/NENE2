<?php

declare(strict_types=1);

namespace Nene2\Config;

use Dotenv\Dotenv;

/** @internal */
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
            'NENE2_MACHINE_API_KEY' => '',
            'NENE2_LOCAL_JWT_SECRET' => '',
            'DATABASE_URL' => '',
            'DB_ENV' => 'local',
            'DB_ADAPTER' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_NAME' => 'nene2',
            'DB_USER' => 'nene2',
            'DB_PASSWORD' => '',
            'DB_CHARSET' => 'utf8mb4',
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
            new DatabaseConfig(
                $this->optionalString($values['DATABASE_URL']),
                trim($values['DB_ENV']),
                trim($values['DB_ADAPTER']),
                trim($values['DB_HOST']),
                $this->parsePort($values['DB_PORT']),
                trim($values['DB_NAME']),
                trim($values['DB_USER']),
                $values['DB_PASSWORD'],
                trim($values['DB_CHARSET']),
            ),
            $this->optionalString($values['NENE2_MACHINE_API_KEY']),
            $this->optionalString($values['NENE2_LOCAL_JWT_SECRET']),
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

    private function optionalString(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function parsePort(string $value): int
    {
        $value = trim($value);

        if (preg_match('/\A\d+\z/', $value) !== 1) {
            throw new ConfigException('DB_PORT must be an integer.');
        }

        return (int) $value;
    }
}
