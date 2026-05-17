<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

final readonly class ListTagsUseCase implements ListTagsUseCaseInterface
{
    public function __construct(
        private TagRepositoryInterface $tags,
    ) {
    }

    public function execute(ListTagsInput $input): ListTagsOutput
    {
        $tags = $this->tags->findAll($input->limit, $input->offset);

        $items = array_map(
            static fn (Tag $tag) => new ListTagItem(id: (int) $tag->id, name: $tag->name),
            $tags,
        );

        return new ListTagsOutput(items: $items, limit: $input->limit, offset: $input->offset);
    }
}
