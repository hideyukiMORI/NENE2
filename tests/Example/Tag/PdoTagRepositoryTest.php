<?php

declare(strict_types=1);

namespace Nene2\Tests\Example\Tag;

use Nene2\Config\DatabaseConfig;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Example\Tag\PdoTagRepository;
use Nene2\Example\Tag\Tag;
use PHPUnit\Framework\TestCase;

final class PdoTagRepositoryTest extends TestCase
{
    private PdoDatabaseQueryExecutor $executor;

    protected function setUp(): void
    {
        $this->executor = new PdoDatabaseQueryExecutor(new PdoConnectionFactory(new DatabaseConfig(
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

        $this->executor->execute(
            'CREATE TABLE tags (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)',
        );
    }

    public function testFindByIdReturnsTag(): void
    {
        $this->executor->execute("INSERT INTO tags (name) VALUES ('php')");

        $repository = new PdoTagRepository($this->executor);
        $tag = $repository->findById(1);

        self::assertNotNull($tag);
        self::assertSame(1, $tag->id);
        self::assertSame('php', $tag->name);
    }

    public function testFindByIdReturnsNullWhenAbsent(): void
    {
        $repository = new PdoTagRepository($this->executor);

        self::assertNull($repository->findById(99));
    }

    public function testSaveReturnsNewId(): void
    {
        $repository = new PdoTagRepository($this->executor);
        $id = $repository->save(new Tag(name: 'api'));

        self::assertSame(1, $id);
    }

    public function testFindAllReturnsTags(): void
    {
        $this->executor->execute("INSERT INTO tags (name) VALUES ('php')");
        $this->executor->execute("INSERT INTO tags (name) VALUES ('api')");

        $repository = new PdoTagRepository($this->executor);
        $tags = $repository->findAll(10, 0);

        self::assertCount(2, $tags);
        self::assertSame('php', $tags[0]->name);
        self::assertSame('api', $tags[1]->name);
    }

    public function testFindAllRespectsLimitAndOffset(): void
    {
        $this->executor->execute("INSERT INTO tags (name) VALUES ('php')");
        $this->executor->execute("INSERT INTO tags (name) VALUES ('api')");
        $this->executor->execute("INSERT INTO tags (name) VALUES ('mcp')");

        $repository = new PdoTagRepository($this->executor);
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
}
