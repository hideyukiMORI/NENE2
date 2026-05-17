<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

interface GetTagByIdUseCaseInterface
{
    public function execute(GetTagByIdInput $input): GetTagByIdOutput;
}
