<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

final readonly class CreateTagInput
{
    public function __construct(public string $name)
    {
    }
}
