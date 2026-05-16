<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

final readonly class GetNoteByIdInput
{
    public function __construct(
        public int $id,
    ) {
    }
}
