<?php

declare(strict_types=1);

namespace Nene2\Tests\Example\Note;

use Nene2\Example\Note\GetNoteByIdInput;
use Nene2\Example\Note\GetNoteByIdUseCase;
use Nene2\Example\Note\Note;
use Nene2\Example\Note\NoteNotFoundException;
use PHPUnit\Framework\TestCase;

final class GetNoteByIdUseCaseTest extends TestCase
{
    public function testReturnsNoteOutput(): void
    {
        $repository = new InMemoryNoteRepository([
            new Note(title: 'Hello', body: 'World', id: 1),
        ]);
        $useCase = new GetNoteByIdUseCase($repository);

        $output = $useCase->execute(new GetNoteByIdInput(1));

        self::assertSame(1, $output->id);
        self::assertSame('Hello', $output->title);
        self::assertSame('World', $output->body);
    }

    public function testThrowsNoteNotFoundExceptionWhenNoteIsAbsent(): void
    {
        $repository = new InMemoryNoteRepository([]);
        $useCase = new GetNoteByIdUseCase($repository);

        $this->expectException(NoteNotFoundException::class);

        $useCase->execute(new GetNoteByIdInput(99));
    }
}
