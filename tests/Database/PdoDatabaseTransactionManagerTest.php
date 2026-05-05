<?php

declare(strict_types=1);

namespace Nene2\Tests\Database;

use LogicException;
use Nene2\Config\DatabaseConfig;
use Nene2\Database\DatabaseQueryExecutorInterface;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseTransactionManager;
use PHPUnit\Framework\TestCase;

final class PdoDatabaseTransactionManagerTest extends TestCase
{
    /** @var list<string> */
    private array $databaseFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->databaseFiles as $databaseFile) {
            if (is_file($databaseFile)) {
                unlink($databaseFile);
            }
        }

        $this->databaseFiles = [];
    }

    public function testCommitsSuccessfulTransaction(): void
    {
        $manager = $this->manager();

        $result = $manager->transactional(function (DatabaseQueryExecutorInterface $database): string {
            $database->execute('CREATE TABLE events (name TEXT NOT NULL)');
            $database->execute('INSERT INTO events (name) VALUES (:name)', ['name' => 'committed']);

            return 'done';
        });

        self::assertSame('done', $result);

        $manager->transactional(function (DatabaseQueryExecutorInterface $database): void {
            self::assertSame(['name' => 'committed'], $database->fetchOne('SELECT name FROM events'));
        });
    }

    public function testRollsBackFailedTransaction(): void
    {
        $manager = $this->manager();

        $manager->transactional(static function (DatabaseQueryExecutorInterface $database): void {
            $database->execute('CREATE TABLE events (name TEXT NOT NULL)');
        });

        try {
            $manager->transactional(static function (DatabaseQueryExecutorInterface $database): void {
                $database->execute('INSERT INTO events (name) VALUES (:name)', ['name' => 'rolled back']);

                throw new LogicException('Stop transaction.');
            });
        } catch (LogicException) {
            // Expected failure path.
        }

        $manager->transactional(function (DatabaseQueryExecutorInterface $database): void {
            self::assertSame([], $database->fetchAll('SELECT name FROM events'));
        });
    }

    private function manager(): PdoDatabaseTransactionManager
    {
        $databaseFile = sys_get_temp_dir() . '/nene2-test-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->databaseFiles[] = $databaseFile;

        return new PdoDatabaseTransactionManager(new PdoConnectionFactory(new DatabaseConfig(
            null,
            'test',
            'sqlite',
            'localhost',
            1,
            $databaseFile,
            'nene2',
            '',
            'utf8',
        )));
    }
}
