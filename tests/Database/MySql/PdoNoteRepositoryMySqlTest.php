<?php

declare(strict_types=1);

namespace Nene2\Tests\Database\MySql;

use Nene2\Config\DatabaseConfig;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Example\Note\Note;
use Nene2\Example\Note\PdoNoteRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

final class PdoNoteRepositoryMySqlTest extends TestCase
{
    private PdoDatabaseQueryExecutor $executor;

    protected function setUp(): void
    {
        $this->executor = new PdoDatabaseQueryExecutor(new PdoConnectionFactory($this->config()), $this->connection());
        $this->executor->execute('TRUNCATE TABLE notes');
    }

    public function testSaveReturnsNewIdAndFindByIdRetrievesIt(): void
    {
        $repository = new PdoNoteRepository($this->executor);
        $id = $repository->save(new Note(title: 'Hello', body: 'World'));

        self::assertGreaterThan(0, $id);

        $note = $repository->findById($id);
        self::assertNotNull($note);
        self::assertSame($id, $note->id);
        self::assertSame('Hello', $note->title);
        self::assertSame('World', $note->body);
    }

    public function testSaveAssignsDistinctIds(): void
    {
        $repository = new PdoNoteRepository($this->executor);
        $id1 = $repository->save(new Note(title: 'First', body: 'A'));
        $id2 = $repository->save(new Note(title: 'Second', body: 'B'));

        self::assertNotSame($id1, $id2);
        self::assertGreaterThan(0, $id1);
        self::assertGreaterThan(0, $id2);
    }

    public function testDeleteRemovesNote(): void
    {
        $repository = new PdoNoteRepository($this->executor);
        $id = $repository->save(new Note(title: 'To delete', body: 'Body'));
        $repository->delete($id);

        self::assertNull($repository->findById($id));
    }

    public function testFindByIdReturnsNullWhenAbsent(): void
    {
        $repository = new PdoNoteRepository($this->executor);

        self::assertNull($repository->findById(99999));
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
