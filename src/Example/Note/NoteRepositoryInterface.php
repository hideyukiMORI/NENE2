<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

interface NoteRepositoryInterface
{
    public function findById(int $id): ?Note;

    public function save(Note $note): int;

    public function delete(int $id): void;
}
