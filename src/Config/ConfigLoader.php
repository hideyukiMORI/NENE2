<?php

declare(strict_types=1);

namespace Nene2\Config;

use Dotenv\Dotenv;
use Nene2\Demo\DemoConfig;

/** @internal */
final readonly class ConfigLoader
{
    /**
     * Canonical framework configuration defaults.
     *
     * Every key {@see load()} reads is present here, so the loader can treat each
     * `$values[...]` access as a guaranteed string. Custom values passed to the
     * constructor are layered *over* this base rather than replacing it (see
     * {@see __construct()}), so a partial map can never drop a key and trigger a
     * `TypeError` deep inside parsing.
     *
     * @var array<string, string>
     */
    private const DEFAULTS = [
        'APP_ENV' => 'local',
        'APP_DEBUG' => 'false',
        'APP_NAME' => 'NENE2',
        'NENE2_MACHINE_API_KEY' => '',
        'NENE2_LOCAL_JWT_SECRET' => '',
        'NENE2_ALLOW_DEV_SECRET' => '',
        'PROBLEM_DETAILS_BASE_URL' => 'https://nene2.dev/problems/',
        'DATABASE_URL' => '',
        'DB_ENV' => 'local',
        'DB_ADAPTER' => 'mysql',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3306',
        'DB_NAME' => 'nene2',
        'DB_USER' => 'nene2',
        'DB_PASSWORD' => '',
        'DB_CHARSET' => 'utf8mb4',
        'DEMO_MODE' => '',
        'DEMO_SLUG_PREFIX' => 'demo-',
        'DEMO_TTL_HOURS' => '3',
        'DEMO_MAX_ORGS' => '200',
        'DEMO_SLUG_ATTEMPTS' => '5',
    ];

    /** @var array<string, string> */
    private array $defaults;

    /**
     * @param array<string, string> $defaults Values layered over {@see self::DEFAULTS};
     *        any unspecified key falls back to the canonical default, so a partial map
     *        is safe (it never omits a key that {@see load()} requires).
     */
    public function __construct(
        private string $projectRoot,
        array $defaults = [],
    ) {
        $this->defaults = array_merge(self::DEFAULTS, $defaults);
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
            trim($values['PROBLEM_DETAILS_BASE_URL']),
            $this->parseStrictOptIn($values['NENE2_ALLOW_DEV_SECRET']),
            new DemoConfig(
                // Strict opt-in parse, same rationale as the dev-secret flag: the demo
                // route creates orgs unauthenticated, so a typo must leave it OFF.
                $this->parseStrictOptIn($values['DEMO_MODE']),
                trim($values['DEMO_SLUG_PREFIX']),
                $this->parsePositiveInt('DEMO_TTL_HOURS', $values['DEMO_TTL_HOURS']),
                $this->parsePositiveInt('DEMO_MAX_ORGS', $values['DEMO_MAX_ORGS']),
                $this->parsePositiveInt('DEMO_SLUG_ATTEMPTS', $values['DEMO_SLUG_ATTEMPTS']),
            ),
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

    /**
     * Parse a security-sensitive opt-in flag strictly.
     *
     * Unlike {@see parseBoolean}, this never throws and accepts only `1`, `true`, or
     * `yes` (case-insensitive, trimmed) as truthy. Any other value — including empty,
     * `0`, `false`, `off`, or an arbitrary string — is treated as opted out, so a typo
     * never silently enables the guarded behaviour. Used for `NENE2_ALLOW_DEV_SECRET`
     * (consumed by {@see \Nene2\Auth\GuardedJwtSecretResolver} via
     * {@see AppConfig::$allowDevSecret}) and `DEMO_MODE`
     * ({@see \Nene2\Demo\DemoConfig::$demoMode}).
     */
    private function parseStrictOptIn(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
    }

    private function optionalString(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function parsePositiveInt(string $key, string $value): int
    {
        $value = trim($value);

        if (preg_match('/\A\d+\z/', $value) !== 1) {
            throw new ConfigException(sprintf('%s must be an integer.', $key));
        }

        return (int) $value;
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
