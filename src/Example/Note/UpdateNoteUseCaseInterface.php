<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

interface UpdateNoteUseCaseInterface
{
    public function execute(UpdateNoteInput $input): UpdateNoteOutput;
}
