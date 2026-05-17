<?php

declare(strict_types=1);

namespace Nene2\Tests\Example\Tag;

use Nene2\Example\Tag\Tag;
use Nene2\Example\Tag\TagRepositoryInterface;

final class InMemoryTagRepository implements TagRepositoryInterface
{
    /** @var array<int, Tag> */
    private array $tags;

    private int $nextId;

    /** @param list<Tag> $tags */
    public function __construct(array $tags = [])
    {
        $this->tags = [];
        $this->nextId = 1;

        foreach ($tags as $tag) {
            if ($tag->id !== null) {
                $this->tags[$tag->id] = $tag;
                $this->nextId = max($this->nextId, $tag->id + 1);
            }
        }
    }

    public function findById(int $id): ?Tag
    {
        return $this->tags[$id] ?? null;
    }

    /** @return list<Tag> */
    public function findAll(int $limit, int $offset): array
    {
        return array_slice(array_values($this->tags), $offset, $limit);
    }

    public function save(Tag $tag): int
    {
        $id = $this->nextId++;
        $this->tags[$id] = new Tag(name: $tag->name, id: $id);

        return $id;
    }

    public function update(Tag $tag): void
    {
        if ($tag->id !== null) {
            $this->tags[$tag->id] = $tag;
        }
    }

    public function delete(int $id): void
    {
        unset($this->tags[$id]);
    }
}
