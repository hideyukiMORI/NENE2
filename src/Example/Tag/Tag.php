<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

final readonly class Tag
{
    public function __construct(
        public string $name,
        public ?int $id = null,
    ) {
    }
}
