<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

use RuntimeException;

final class NoteNotFoundException extends RuntimeException
{
    public function __construct(int $id)
    {
        parent::__construct("Note with id {$id} was not found.");
    }
}
