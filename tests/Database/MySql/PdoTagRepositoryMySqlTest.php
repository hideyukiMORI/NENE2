<?php

declare(strict_types=1);

namespace Nene2\Tests\Database\MySql;

use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Example\Tag\PdoTagRepository;
use Nene2\Example\Tag\Tag;
use PHPUnit\Framework\TestCase;

final class PdoTagRepositoryMySqlTest extends TestCase
{
    use RequiresMySql;

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
}
