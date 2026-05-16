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

    public function testUpdateChangesNoteFields(): void
    {
        $repository = new PdoNoteRepository($this->executor);
        $id = $repository->save(new Note(title: 'Original', body: 'Old body'));

        $repository->update(new Note(title: 'Updated', body: 'New body', id: $id));
        $note = $repository->findById($id);

        self::assertNotNull($note);
        self::assertSame('Updated', $note->title);
        self::assertSame('New body', $note->body);
    }

    public function testDeleteRemovesNote(): void
    {
        $repository = new PdoNoteRepository($this->executor);
        $id = $repository->save(new Note(title: 'To delete', body: 'Body'));
        $repository->delete($id);

        self::assertNull($repository->findById($id));
    }

    public function testFindAllReturnsAllNotes(): void
    {
        $repository = new PdoNoteRepository($this->executor);
        $repository->save(new Note(title: 'First', body: 'A'));
        $repository->save(new Note(title: 'Second', body: 'B'));

        $notes = $repository->findAll(10, 0);

        self::assertCount(2, $notes);
        self::assertSame('First', $notes[0]->title);
        self::assertSame('Second', $notes[1]->title);
    }

    public function testFindAllRespectsLimitAndOffset(): void
    {
        $repository = new PdoNoteRepository($this->executor);

        for ($i = 1; $i <= 5; $i++) {
            $repository->save(new Note(title: "Note {$i}", body: 'Body'));
        }

        $notes = $repository->findAll(2, 2);

        self::assertCount(2, $notes);
        self::assertSame('Note 3', $notes[0]->title);
        self::assertSame('Note 4', $notes[1]->title);
    }
}
