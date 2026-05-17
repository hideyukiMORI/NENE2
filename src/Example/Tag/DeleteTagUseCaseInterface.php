<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

interface DeleteTagUseCaseInterface
{
    /**
     * @throws TagNotFoundException when no tag matches the given id.
     */
    public function execute(DeleteTagByIdInput $input): void;
}
