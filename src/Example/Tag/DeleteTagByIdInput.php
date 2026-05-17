<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

final readonly class DeleteTagByIdInput
{
    public function __construct(
        public int $id,
    ) {
    }
}
