<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

final readonly class CreateNoteInput
{
    public function __construct(
        public string $title,
        public string $body,
    ) {
    }
}
