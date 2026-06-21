<?php

declare(strict_types=1);

namespace Nene2\Tests\Database\MySql;

use Nene2\Database\DatabaseQueryExecutorInterface;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Database\PdoDatabaseTransactionManager;
use PHPUnit\Framework\TestCase;

final class PdoMySqlConnectionTest extends TestCase
{
    use RequiresMySql;

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
}
