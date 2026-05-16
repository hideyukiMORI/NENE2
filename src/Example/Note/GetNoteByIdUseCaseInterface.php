<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

interface GetNoteByIdUseCaseInterface
{
    /**
     * @throws NoteNotFoundException when no note matches the given id.
     */
    public function execute(GetNoteByIdInput $input): GetNoteByIdOutput;
}
