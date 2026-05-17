<?php

declare(strict_types=1);

namespace Nene2\Tests\Database\MySql;

use Nene2\Config\DatabaseConfig;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Example\Tag\PdoTagRepository;
use Nene2\Example\Tag\Tag;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

final class PdoTagRepositoryMySqlTest extends TestCase
{
    private PdoDatabaseQueryExecutor $executor;

    protected function setUp(): void
    {
        $this->executor = new PdoDatabaseQueryExecutor(new PdoConnectionFactory($this->config()), $this->connection());
        $this->executor->execute('TRUNCATE TABLE tags');
    }

    public function testSaveReturnsNewIdAndFindByIdRetrievesIt(): void
    {
        $repository = new PdoTagRepository($this->executor);
        $id = $repository->save(new Tag(name: 'php'));

        self::assertGreaterThan(0, $id);

        $tag = $repository->findById($id);
        self::assertNotNull($tag);
        self::assertSame($id, $tag->id);
        self::assertSame('php', $tag->name);
    }

    public function testSaveAssignsDistinctIds(): void
    {
        $repository = new PdoTagRepository($this->executor);
        $id1 = $repository->save(new Tag(name: 'php'));
        $id2 = $repository->save(new Tag(name: 'api'));

        self::assertNotSame($id1, $id2);
        self::assertGreaterThan(0, $id1);
        self::assertGreaterThan(0, $id2);
    }

    public function testFindAllReturnsSavedTags(): void
    {
        $repository = new PdoTagRepository($this->executor);
        $repository->save(new Tag(name: 'php'));
        $repository->save(new Tag(name: 'api'));

        $tags = $repository->findAll(10, 0);

        self::assertCount(2, $tags);
        self::assertSame('php', $tags[0]->name);
        self::assertSame('api', $tags[1]->name);
    }

    public function testFindAllRespectsLimitAndOffset(): void
    {
        $repository = new PdoTagRepository($this->executor);
        $repository->save(new Tag(name: 'php'));
        $repository->save(new Tag(name: 'api'));
        $repository->save(new Tag(name: 'mcp'));

        $tags = $repository->findAll(2, 1);

        self::assertCount(2, $tags);
        self::assertSame('api', $tags[0]->name);
        self::assertSame('mcp', $tags[1]->name);
    }

    public function testUpdateChangesTagName(): void
    {
        $repository = new PdoTagRepository($this->executor);
        $id = $repository->save(new Tag(name: 'php'));

        $repository->update(new Tag(name: 'php8', id: $id));
        $tag = $repository->findById($id);

        self::assertNotNull($tag);
        self::assertSame('php8', $tag->name);
    }

    public function testDeleteRemovesTag(): void
    {
        $repository = new PdoTagRepository($this->executor);
        $id = $repository->save(new Tag(name: 'php'));
        $repository->delete($id);

        self::assertNull($repository->findById($id));
    }

    public function testFindByIdReturnsNullWhenAbsent(): void
    {
        $repository = new PdoTagRepository($this->executor);

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
