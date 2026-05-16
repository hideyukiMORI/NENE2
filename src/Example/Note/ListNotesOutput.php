<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

final readonly class ListNotesOutput
{
    /** @param list<ListNoteItem> $items */
    public function __construct(
        public array $items,
        public int $limit,
        public int $offset,
    ) {
    }
}
