<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

interface UpdateTagUseCaseInterface
{
    public function execute(UpdateTagInput $input): UpdateTagOutput;
}
