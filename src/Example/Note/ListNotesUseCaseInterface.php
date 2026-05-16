<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

interface ListNotesUseCaseInterface
{
    public function execute(ListNotesInput $input): ListNotesOutput;
}
