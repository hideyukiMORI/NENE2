<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

final readonly class ListTagsOutput
{
    /** @param list<ListTagItem> $items */
    public function __construct(
        public array $items,
        public int $limit,
        public int $offset,
    ) {
    }
}
