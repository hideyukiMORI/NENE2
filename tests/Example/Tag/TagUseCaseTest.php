<?php

declare(strict_types=1);

namespace Nene2\Tests\Example\Tag;

use Nene2\Example\Tag\CreateTagInput;
use Nene2\Example\Tag\CreateTagUseCase;
use Nene2\Example\Tag\DeleteTagByIdInput;
use Nene2\Example\Tag\DeleteTagUseCase;
use Nene2\Example\Tag\GetTagByIdInput;
use Nene2\Example\Tag\GetTagByIdUseCase;
use Nene2\Example\Tag\ListTagsInput;
use Nene2\Example\Tag\ListTagsUseCase;
use Nene2\Example\Tag\Tag;
use Nene2\Example\Tag\TagNotFoundException;
use Nene2\Example\Tag\UpdateTagInput;
use Nene2\Example\Tag\UpdateTagUseCase;
use PHPUnit\Framework\TestCase;

final class TagUseCaseTest extends TestCase
{
    public function testCreateTagReturnsOutputWithNewId(): void
    {
        $repository = new InMemoryTagRepository([]);
        $useCase = new CreateTagUseCase($repository);

        $output = $useCase->execute(new CreateTagInput(name: 'php'));

        self::assertSame(1, $output->id);
        self::assertSame('php', $output->name);
    }

    public function testGetTagByIdReturnsTag(): void
    {
        $repository = new InMemoryTagRepository([new Tag(name: 'php', id: 1)]);
        $useCase = new GetTagByIdUseCase($repository);

        $output = $useCase->execute(new GetTagByIdInput(1));

        self::assertSame(1, $output->id);
        self::assertSame('php', $output->name);
    }

    public function testGetTagByIdThrowsWhenAbsent(): void
    {
        $repository = new InMemoryTagRepository([]);
        $useCase = new GetTagByIdUseCase($repository);

        $this->expectException(TagNotFoundException::class);

        $useCase->execute(new GetTagByIdInput(99));
    }

    public function testListTagsReturnsItems(): void
    {
        $repository = new InMemoryTagRepository([
            new Tag(name: 'php', id: 1),
            new Tag(name: 'api', id: 2),
        ]);
        $useCase = new ListTagsUseCase($repository);

        $output = $useCase->execute(new ListTagsInput(limit: 10, offset: 0));

        self::assertCount(2, $output->items);
        self::assertSame('php', $output->items[0]->name);
        self::assertSame('api', $output->items[1]->name);
    }

    public function testListTagsRespectsOffset(): void
    {
        $repository = new InMemoryTagRepository([
            new Tag(name: 'php', id: 1),
            new Tag(name: 'api', id: 2),
            new Tag(name: 'mcp', id: 3),
        ]);
        $useCase = new ListTagsUseCase($repository);

        $output = $useCase->execute(new ListTagsInput(limit: 10, offset: 1));

        self::assertCount(2, $output->items);
        self::assertSame('api', $output->items[0]->name);
    }

    public function testUpdateTagChangesName(): void
    {
        $repository = new InMemoryTagRepository([]);
        $createUseCase = new CreateTagUseCase($repository);
        $updateUseCase = new UpdateTagUseCase($repository);

        $created = $createUseCase->execute(new CreateTagInput(name: 'php'));
        $output = $updateUseCase->execute(new UpdateTagInput(id: $created->id, name: 'php8'));

        self::assertSame($created->id, $output->id);
        self::assertSame('php8', $output->name);

        $stored = $repository->findById($created->id);
        self::assertNotNull($stored);
        self::assertSame('php8', $stored->name);
    }

    public function testUpdateTagThrowsWhenAbsent(): void
    {
        $repository = new InMemoryTagRepository([]);
        $updateUseCase = new UpdateTagUseCase($repository);

        $this->expectException(TagNotFoundException::class);

        $updateUseCase->execute(new UpdateTagInput(id: 99, name: 'php8'));
    }

    public function testDeleteTagRemovesTag(): void
    {
        $repository = new InMemoryTagRepository([]);
        $createUseCase = new CreateTagUseCase($repository);
        $deleteUseCase = new DeleteTagUseCase($repository);

        $created = $createUseCase->execute(new CreateTagInput(name: 'php'));
        $deleteUseCase->execute(new DeleteTagByIdInput($created->id));

        self::assertNull($repository->findById($created->id));
    }

    public function testDeleteTagThrowsWhenAbsent(): void
    {
        $repository = new InMemoryTagRepository([]);
        $deleteUseCase = new DeleteTagUseCase($repository);

        $this->expectException(TagNotFoundException::class);

        $deleteUseCase->execute(new DeleteTagByIdInput(99));
    }
}
