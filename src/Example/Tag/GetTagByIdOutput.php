<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

final readonly class GetTagByIdOutput
{
    public function __construct(
        public int $id,
        public string $name,
    ) {
    }
}
