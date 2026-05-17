<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

final readonly class CreateTagUseCase implements CreateTagUseCaseInterface
{
    public function __construct(
        private TagRepositoryInterface $tags,
    ) {
    }

    public function execute(CreateTagInput $input): CreateTagOutput
    {
        $id = $this->tags->save(new Tag(name: $input->name));

        return new CreateTagOutput(id: $id, name: $input->name);
    }
}
