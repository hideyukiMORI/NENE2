<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

final readonly class DeleteTagUseCase implements DeleteTagUseCaseInterface
{
    public function __construct(
        private TagRepositoryInterface $tags,
    ) {
    }

    public function execute(DeleteTagByIdInput $input): void
    {
        $tag = $this->tags->findById($input->id);

        if ($tag === null) {
            throw new TagNotFoundException($input->id);
        }

        $this->tags->delete($input->id);
    }
}
