<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

final readonly class Note
{
    public function __construct(
        public string $title,
        public string $body,
        public ?int $id = null,
    ) {
    }
}
