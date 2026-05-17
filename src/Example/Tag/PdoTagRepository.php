<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

use Nene2\Database\DatabaseQueryExecutorInterface;

final readonly class PdoTagRepository implements TagRepositoryInterface
{
    public function __construct(
        private DatabaseQueryExecutorInterface $query,
    ) {
    }

    public function findById(int $id): ?Tag
    {
        $row = $this->query->fetchOne(
            'SELECT id, name FROM tags WHERE id = ?',
            [$id],
        );

        if ($row === null) {
            return null;
        }

        return new Tag(name: (string) $row['name'], id: (int) $row['id']);
    }

    /** @return list<Tag> */
    public function findAll(int $limit, int $offset): array
    {
        $rows = $this->query->fetchAll(
            'SELECT id, name FROM tags ORDER BY id ASC LIMIT ? OFFSET ?',
            [$limit, $offset],
        );

        return array_map(
            static fn (array $row) => new Tag(name: (string) $row['name'], id: (int) $row['id']),
            $rows,
        );
    }

    public function save(Tag $tag): int
    {
        $this->query->execute('INSERT INTO tags (name) VALUES (?)', [$tag->name]);

        return $this->query->lastInsertId();
    }

    public function update(Tag $tag): void
    {
        $this->query->execute('UPDATE tags SET name = ? WHERE id = ?', [$tag->name, $tag->id]);
    }

    public function delete(int $id): void
    {
        $this->query->execute('DELETE FROM tags WHERE id = ?', [$id]);
    }
}
