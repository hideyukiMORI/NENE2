<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

final readonly class GetTagByIdUseCase implements GetTagByIdUseCaseInterface
{
    public function __construct(
        private TagRepositoryInterface $tags,
    ) {
    }

    public function execute(GetTagByIdInput $input): GetTagByIdOutput
    {
        $tag = $this->tags->findById($input->id);

        if ($tag === null) {
            throw new TagNotFoundException($input->id);
        }

        return new GetTagByIdOutput(id: (int) $tag->id, name: $tag->name);
    }
}
