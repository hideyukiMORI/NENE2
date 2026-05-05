<?php

declare(strict_types=1);

namespace Nene2\Tests\Database;

use Nene2\Config\DatabaseConfig;
use Nene2\Database\DatabaseConnectionException;
use Nene2\Database\PdoConnectionFactory;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoConnectionFactoryTest extends TestCase
{
    public function testCreatesSqliteConnectionWithSafeDefaults(): void
    {
        $connection = (new PdoConnectionFactory($this->sqliteConfig()))->create();

        self::assertSame(PDO::ERRMODE_EXCEPTION, $connection->getAttribute(PDO::ATTR_ERRMODE));
        self::assertSame(PDO::FETCH_ASSOC, $connection->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));

        $connection->exec('CREATE TABLE health_checks (name TEXT NOT NULL)');
        $connection->exec("INSERT INTO health_checks (name) VALUES ('NENE2')");

        $statement = $connection->query('SELECT name FROM health_checks');

        self::assertNotFalse($statement);
        self::assertSame(['name' => 'NENE2'], $statement->fetch());
    }

    public function testUnsupportedAdapterFailsFast(): void
    {
        $factory = new PdoConnectionFactory(new DatabaseConfig(
            null,
            'test',
            'unknown',
            'localhost',
            1,
            'nene2',
            'nene2',
            '',
            'utf8',
        ));

        $this->expectException(DatabaseConnectionException::class);
        $this->expectExceptionMessage('Database adapter "unknown" is not supported by the PDO factory.');

        $factory->create();
    }

    private function sqliteConfig(): DatabaseConfig
    {
        return new DatabaseConfig(
            null,
            'test',
            'sqlite',
            'localhost',
            1,
            ':memory:',
            'nene2',
            '',
            'utf8',
        );
    }
}
