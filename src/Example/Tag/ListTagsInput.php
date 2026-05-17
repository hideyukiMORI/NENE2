<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

final readonly class ListTagsInput
{
    public function __construct(
        public int $limit,
        public int $offset,
    ) {
    }
}
