<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

interface TagRepositoryInterface
{
    public function findById(int $id): ?Tag;

    /** @return list<Tag> */
    public function findAll(int $limit, int $offset): array;

    public function save(Tag $tag): int;
}
