<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

interface ListTagsUseCaseInterface
{
    public function execute(ListTagsInput $input): ListTagsOutput;
}
