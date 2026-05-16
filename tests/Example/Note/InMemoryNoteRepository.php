<?php

declare(strict_types=1);

namespace Nene2\Tests\Example\Note;

use Nene2\Example\Note\Note;
use Nene2\Example\Note\NoteRepositoryInterface;

final class InMemoryNoteRepository implements NoteRepositoryInterface
{
    /** @var array<int, Note> */
    private array $notes;

    private int $nextId;

    /** @param list<Note> $notes */
    public function __construct(array $notes = [])
    {
        $this->notes = [];
        $this->nextId = 1;

        foreach ($notes as $note) {
            if ($note->id !== null) {
                $this->notes[$note->id] = $note;
                $this->nextId = max($this->nextId, $note->id + 1);
            }
        }
    }

    public function findById(int $id): ?Note
    {
        return $this->notes[$id] ?? null;
    }

    public function save(Note $note): int
    {
        $id = $this->nextId++;
        $this->notes[$id] = new Note(title: $note->title, body: $note->body, id: $id);

        return $id;
    }

    public function delete(int $id): void
    {
        unset($this->notes[$id]);
    }
}
