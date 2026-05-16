<?php

declare(strict_types=1);

namespace Nene2\Tests\Example\Note;

use Nene2\Example\Note\CreateNoteInput;
use Nene2\Example\Note\CreateNoteUseCase;
use PHPUnit\Framework\TestCase;

final class CreateNoteUseCaseTest extends TestCase
{
    public function testReturnsOutputWithNewId(): void
    {
        $repository = new InMemoryNoteRepository([]);
        $useCase = new CreateNoteUseCase($repository);

        $output = $useCase->execute(new CreateNoteInput(title: 'Hello', body: 'World'));

        self::assertSame(1, $output->id);
        self::assertSame('Hello', $output->title);
        self::assertSame('World', $output->body);
    }

    public function testAssignsSequentialIds(): void
    {
        $repository = new InMemoryNoteRepository([]);
        $useCase = new CreateNoteUseCase($repository);

        $first = $useCase->execute(new CreateNoteInput(title: 'First', body: 'Body'));
        $second = $useCase->execute(new CreateNoteInput(title: 'Second', body: 'Body'));

        self::assertSame(1, $first->id);
        self::assertSame(2, $second->id);
    }
}
