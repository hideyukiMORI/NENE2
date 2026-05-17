<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

final readonly class GetTagByIdInput
{
    public function __construct(public int $id)
    {
    }
}
