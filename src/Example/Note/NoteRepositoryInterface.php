<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

interface NoteRepositoryInterface
{
    public function findById(int $id): ?Note;

    /** @return list<Note> */
    public function findAll(int $limit, int $offset): array;

    public function save(Note $note): int;

    public function delete(int $id): void;
}
