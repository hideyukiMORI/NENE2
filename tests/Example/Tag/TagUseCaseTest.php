<?php

declare(strict_types=1);

namespace Nene2\Tests\Example\Tag;

use Nene2\Example\Tag\CreateTagInput;
use Nene2\Example\Tag\CreateTagUseCase;
use Nene2\Example\Tag\GetTagByIdInput;
use Nene2\Example\Tag\GetTagByIdUseCase;
use Nene2\Example\Tag\ListTagsInput;
use Nene2\Example\Tag\ListTagsUseCase;
use Nene2\Example\Tag\Tag;
use Nene2\Example\Tag\TagNotFoundException;
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
}
