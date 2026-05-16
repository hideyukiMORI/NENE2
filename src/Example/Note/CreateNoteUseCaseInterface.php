<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

interface CreateNoteUseCaseInterface
{
    public function execute(CreateNoteInput $input): CreateNoteOutput;
}
