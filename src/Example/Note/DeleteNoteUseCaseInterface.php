<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

interface DeleteNoteUseCaseInterface
{
    /**
     * @throws NoteNotFoundException when no note matches the given id.
     */
    public function execute(DeleteNoteByIdInput $input): void;
}
