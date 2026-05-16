<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

final readonly class ListNotesInput
{
    public function __construct(
        public int $limit = 20,
        public int $offset = 0,
    ) {
    }
}
