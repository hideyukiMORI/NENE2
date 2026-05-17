<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

use RuntimeException;

final class TagNotFoundException extends RuntimeException
{
    public function __construct(int $id)
    {
        parent::__construct("Tag with id {$id} was not found.");
    }
}
