<?php

declare(strict_types=1);

namespace Nene2\Tests\Testing;

use InvalidArgumentException;
use Nene2\Config\DatabaseConfig;
use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Database\DatabaseQueryExecutorInterface;
use Nene2\Database\DatabaseTransactionManagerInterface;
use Nene2\Testing\DatabaseTestKit;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatabaseTestKitTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/' . uniqid('kit-', true) . '.sqlite';
    }

    protected function tearDown(): void
    {
        if ($this->path !== '' && is_file($this->path)) {
            @unlink($this->path);
        }
    }

    public function testSqliteFactoryReturnsWiredInterfaces(): void
    {
        $kit = DatabaseTestKit::sqlite($this->path);

        self::assertInstanceOf(DatabaseConnectionFactoryInterface::class, $kit->connectionFactory);
        self::assertInstanceOf(DatabaseQueryExecutorInterface::class, $kit->queryExecutor);
        self::assertInstanceOf(DatabaseTransactionManagerInterface::class, $kit->transactionManager);
    }

    public function testExecutorCanCreateInsertAndSelect(): void
    {
        $kit = DatabaseTestKit::sqlite($this->path);

        $kit->queryExecutor->execute('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $id = $kit->queryExecutor->insert('INSERT INTO items (name) VALUES (?)', ['first']);

        self::assertSame(1, $id);
        self::assertSame(['id' => 1, 'name' => 'first'], $kit->queryExecutor->fetchOne('SELECT * FROM items WHERE id = ?', [$id]));
    }

    public function testTransactionalCommit(): void
    {
        $kit = DatabaseTestKit::sqlite($this->path);
        $kit->queryExecutor->execute('CREATE TABLE counters (id INTEGER PRIMARY KEY, value INTEGER NOT NULL)');

        $result = $kit->transactionManager->transactional(function (DatabaseQueryExecutorInterface $tx): int {
            return $tx->insert('INSERT INTO counters (value) VALUES (?)', [42]);
        });

        self::assertSame(1, $result);
        self::assertSame(['id' => 1, 'value' => 42], $kit->queryExecutor->fetchOne('SELECT * FROM counters WHERE id = ?', [1]));
    }

    public function testTransactionalRollback(): void
    {
        $kit = DatabaseTestKit::sqlite($this->path);
        $kit->queryExecutor->execute('CREATE TABLE counters (id INTEGER PRIMARY KEY, value INTEGER NOT NULL)');

        try {
            $kit->transactionManager->transactional(function (DatabaseQueryExecutorInterface $tx): mixed {
                $tx->insert('INSERT INTO counters (value) VALUES (?)', [99]);
                throw new RuntimeException('abort');
            });
        } catch (RuntimeException $expected) {
            self::assertSame('abort', $expected->getMessage());
        }

        self::assertNull(
            $kit->queryExecutor->fetchOne('SELECT * FROM counters WHERE value = ?', [99]),
            'Rolled-back insert must not be visible after transactional() throws',
        );
    }

    public function testSqliteFactoryRejectsInMemory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/:memory:/');

        DatabaseTestKit::sqlite(':memory:');
    }

    public function testFromConfigAcceptsArbitraryDatabaseConfig(): void
    {
        $kit = DatabaseTestKit::fromConfig(DatabaseConfig::sqlite($this->path));

        $kit->queryExecutor->execute('CREATE TABLE pings (id INTEGER PRIMARY KEY)');
        self::assertSame(1, $kit->queryExecutor->insert('INSERT INTO pings DEFAULT VALUES'));
    }
}
