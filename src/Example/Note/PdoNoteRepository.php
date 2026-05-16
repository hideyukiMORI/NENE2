<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class PdoNoteRepository implements NoteRepositoryInterface
{
    public function __construct(
        private DatabaseQueryExecutorInterface $query,
    ) {
    }

    public function findById(int $id): ?Note
    {
        $row = $this->query->fetchOne(
            'SELECT id, title, body FROM notes WHERE id = ?',
            [$id],
        );

        if ($row === null) {
            return null;
        }

        return new Note(
            title: (string) $row['title'],
            body: (string) $row['body'],
            id: (int) $row['id'],
        );
    }
}
