<?php

declare(strict_types=1);

namespace Nene2\Tests\Database\MySql;

use Nene2\Config\DatabaseConfig;
use Nene2\Database\PdoConnectionFactory;
use PDO;
use Throwable;

/**
 * Shared MySQL wiring for the DatabaseMySQL integration suite.
 *
 * These tests require a running MySQL instance (`docker compose up -d mysql`).
 * When the database cannot be reached the test is skipped instead of failed, so
 * the suite degrades gracefully on machines without MySQL provisioned. The first
 * unreachable attempt is cached so the remaining tests skip immediately rather
 * than each re-running the connection retry budget.
 */
trait RequiresMySql
{
    private const string SKIP_MESSAGE =
        'MySQL is not reachable — skipping integration test. Start it with `docker compose up -d mysql`.';

    private static ?bool $mySqlReachable = null;

    private function connection(): PDO
    {
        if (self::$mySqlReachable === false) {
            self::markTestSkipped(self::SKIP_MESSAGE);
        }

        $lastError = null;

        for ($attempt = 0; $attempt < 20; $attempt++) {
            try {
                $connection = (new PdoConnectionFactory($this->config()))->create();
                self::$mySqlReachable = true;

                return $connection;
            } catch (Throwable $exception) {
                $lastError = $exception;
                usleep(250_000);
            }
        }

        self::$mySqlReachable = false;
        self::markTestSkipped(self::SKIP_MESSAGE . ' Last error: ' . $lastError->getMessage());
    }

    private function config(): DatabaseConfig
    {
        return new DatabaseConfig(
            null,
            'test',
            'mysql',
            $this->env('DB_HOST', 'mysql'),
            (int) $this->env('DB_PORT', '3306'),
            $this->env('DB_NAME', 'nene2'),
            $this->env('DB_USER', 'nene2'),
            $this->env('DB_PASSWORD', 'nene2'),
            $this->env('DB_CHARSET', 'utf8mb4'),
        );
    }

    private function env(string $key, string $default): string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
