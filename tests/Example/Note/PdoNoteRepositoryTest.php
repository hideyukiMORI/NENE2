<?php

declare(strict_types=1);

namespace Nene2\Tests\Example\Note;

use Nene2\Config\DatabaseConfig;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Example\Note\Note;
use Nene2\Example\Note\PdoNoteRepository;
use PHPUnit\Framework\TestCase;

final class PdoNoteRepositoryTest extends TestCase
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
            'CREATE TABLE notes (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, body TEXT NOT NULL)',
        );
    }

    public function testFindByIdReturnsNote(): void
    {
        $this->executor->execute("INSERT INTO notes (title, body) VALUES ('Hello', 'World')");

        $repository = new PdoNoteRepository($this->executor);
        $note = $repository->findById(1);

        self::assertNotNull($note);
        self::assertSame(1, $note->id);
        self::assertSame('Hello', $note->title);
        self::assertSame('World', $note->body);
    }

    public function testFindByIdReturnsNullWhenNoteIsAbsent(): void
    {
        $repository = new PdoNoteRepository($this->executor);
        $note = $repository->findById(99);

        self::assertNull($note);
    }

    public function testSaveReturnsNewId(): void
    {
        $repository = new PdoNoteRepository($this->executor);
        $id = $repository->save(new Note(title: 'Hello', body: 'World'));

        self::assertSame(1, $id);
    }

    public function testSavedNoteIsRetrievableById(): void
    {
        $repository = new PdoNoteRepository($this->executor);
        $id = $repository->save(new Note(title: 'Hello', body: 'World'));
        $note = $repository->findById($id);

        self::assertNotNull($note);
        self::assertSame('Hello', $note->title);
        self::assertSame('World', $note->body);
    }

    public function testDeleteRemovesNote(): void
    {
        $repository = new PdoNoteRepository($this->executor);
        $id = $repository->save(new Note(title: 'To delete', body: 'Body'));
        $repository->delete($id);

        self::assertNull($repository->findById($id));
    }
}
