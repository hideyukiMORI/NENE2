<?php

declare(strict_types=1);

namespace Nene2\Tests\Database\MySql;

use Nene2\Config\DatabaseConfig;
use Nene2\Database\DatabaseQueryExecutorInterface;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Database\PdoDatabaseTransactionManager;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

final class PdoMySqlConnectionTest extends TestCase
{
    public function testConnectsExecutesQueriesAndRollsBackTransactions(): void
    {
        $tableName = 'nene2_mysql_verification_' . bin2hex(random_bytes(4));
        $connection = $this->connection();
        $executor = new PdoDatabaseQueryExecutor(new PdoConnectionFactory($this->config()), $connection);

        try {
            $executor->execute(sprintf('CREATE TABLE %s (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NOT NULL)', $tableName));

            self::assertSame(1, $executor->execute(
                sprintf('INSERT INTO %s (name) VALUES (:name)', $tableName),
                ['name' => 'NENE2'],
            ));
            self::assertSame(['name' => 'NENE2'], $executor->fetchOne(
                sprintf('SELECT name FROM %s WHERE name = :name', $tableName),
                ['name' => 'NENE2'],
            ));

            $manager = new PdoDatabaseTransactionManager(new PdoConnectionFactory($this->config()));

            try {
                $manager->transactional(static function (DatabaseQueryExecutorInterface $database) use ($tableName): void {
                    $database->execute(
                        sprintf('INSERT INTO %s (name) VALUES (:name)', $tableName),
                        ['name' => 'rolled back'],
                    );

                    throw new \LogicException('Force rollback.');
                });
            } catch (\LogicException) {
                // Expected rollback path.
            }

            self::assertNull($executor->fetchOne(
                sprintf('SELECT name FROM %s WHERE name = :name', $tableName),
                ['name' => 'rolled back'],
            ));
        } finally {
            $executor->execute(sprintf('DROP TABLE IF EXISTS %s', $tableName));
        }
    }

    private function connection(): PDO
    {
        $lastError = null;

        for ($attempt = 0; $attempt < 20; $attempt++) {
            try {
                return (new PdoConnectionFactory($this->config()))->create();
            } catch (Throwable $exception) {
                $lastError = $exception;
                usleep(250_000);
            }
        }

        self::fail('MySQL connection could not be created: ' . $lastError->getMessage());
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
