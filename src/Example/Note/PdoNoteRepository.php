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

    /** @return list<Note> */
    public function findAll(int $limit, int $offset): array
    {
        $rows = $this->query->fetchAll(
            'SELECT id, title, body FROM notes ORDER BY id ASC LIMIT ? OFFSET ?',
            [$limit, $offset],
        );

        return array_map(
            static fn (array $row) => new Note(
                title: (string) $row['title'],
                body: (string) $row['body'],
                id: (int) $row['id'],
            ),
            $rows,
        );
    }

    public function save(Note $note): int
    {
        $this->query->execute(
            'INSERT INTO notes (title, body) VALUES (?, ?)',
            [$note->title, $note->body],
        );

        return $this->query->lastInsertId();
    }

    public function update(Note $note): void
    {
        $this->query->execute(
            'UPDATE notes SET title = ?, body = ? WHERE id = ?',
            [$note->title, $note->body, $note->id],
        );
    }

    public function delete(int $id): void
    {
        $this->query->execute('DELETE FROM notes WHERE id = ?', [$id]);
    }
}
