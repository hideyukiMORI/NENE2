<?php

declare(strict_types=1);

namespace Nene2\Tests\Example\Note;

use Nene2\Example\Note\Note;
use Nene2\Example\Note\NoteRepositoryInterface;

final class InMemoryNoteRepository implements NoteRepositoryInterface
{
    /** @var array<int, Note> */
    private array $notes;

    /** @param list<Note> $notes */
    public function __construct(array $notes = [])
    {
        $this->notes = [];

        foreach ($notes as $note) {
            if ($note->id !== null) {
                $this->notes[$note->id] = $note;
            }
        }
    }

    public function findById(int $id): ?Note
    {
        return $this->notes[$id] ?? null;
    }
}
