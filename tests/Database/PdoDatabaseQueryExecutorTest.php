<?php

declare(strict_types=1);

namespace Nene2\Tests\Database;

use Nene2\Config\DatabaseConfig;
use Nene2\Database\DatabaseConnectionException;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use PHPUnit\Framework\TestCase;

final class PdoDatabaseQueryExecutorTest extends TestCase
{
    public function testExecutesParameterizedQueries(): void
    {
        $executor = $this->executor();

        $executor->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');

        self::assertSame(1, $executor->execute('INSERT INTO users (name) VALUES (:name)', ['name' => 'NENE2']));
        self::assertSame(['id' => 1, 'name' => 'NENE2'], $executor->fetchOne(
            'SELECT id, name FROM users WHERE name = :name',
            ['name' => 'NENE2'],
        ));
        self::assertSame([
            ['id' => 1, 'name' => 'NENE2'],
        ], $executor->fetchAll('SELECT id, name FROM users ORDER BY id'));
    }

    public function testFetchOneReturnsNullWhenNoRowMatches(): void
    {
        $executor = $this->executor();

        $executor->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');

        self::assertNull($executor->fetchOne('SELECT id, name FROM users WHERE name = :name', ['name' => 'missing']));
    }

    public function testQueryFailureUsesDatabaseConnectionException(): void
    {
        $executor = $this->executor();

        $this->expectException(DatabaseConnectionException::class);
        $this->expectExceptionMessage('Database query could not be executed.');

        $executor->fetchAll('SELECT * FROM missing_table');
    }

    private function executor(): PdoDatabaseQueryExecutor
    {
        return new PdoDatabaseQueryExecutor(new PdoConnectionFactory(new DatabaseConfig(
            null,
            'test',
            'sqlite',
            'localhost',
            1,
            ':memory:',
            'nene2',
            '',
            'utf8',
        )));
    }
}
