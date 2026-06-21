<?php

declare(strict_types=1);

namespace Nene2\Tests\Database\MySql;

use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Example\Note\Note;
use Nene2\Example\Note\PdoNoteRepository;
use PHPUnit\Framework\TestCase;

final class PdoNoteRepositoryMySqlTest extends TestCase
{
    use RequiresMySql;

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

    public function testUpdateChangesNoteFields(): void
    {
        $repository = new PdoNoteRepository($this->executor);
        $id = $repository->save(new Note(title: 'Original', body: 'First body'));

        $repository->update(new Note(id: $id, title: 'Updated', body: 'Second body'));

        $note = $repository->findById($id);
        self::assertNotNull($note);
        self::assertSame('Updated', $note->title);
        self::assertSame('Second body', $note->body);
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
}
