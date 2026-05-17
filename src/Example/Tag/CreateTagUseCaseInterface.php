<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

interface CreateTagUseCaseInterface
{
    public function execute(CreateTagInput $input): CreateTagOutput;
}
