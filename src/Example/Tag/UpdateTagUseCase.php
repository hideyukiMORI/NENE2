<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

final readonly class UpdateTagUseCase implements UpdateTagUseCaseInterface
{
    public function __construct(
        private TagRepositoryInterface $tags,
    ) {
    }

    public function execute(UpdateTagInput $input): UpdateTagOutput
    {
        $tag = $this->tags->findById($input->id);

        if ($tag === null) {
            throw new TagNotFoundException($input->id);
        }

        $updated = new Tag(name: $input->name, id: $input->id);
        $this->tags->update($updated);

        return new UpdateTagOutput(id: $input->id, name: $input->name);
    }
}
