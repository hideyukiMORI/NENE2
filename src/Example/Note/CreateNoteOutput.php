<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

final readonly class CreateNoteOutput
{
    public function __construct(
        public int $id,
        public string $title,
        public string $body,
    ) {
    }
}
