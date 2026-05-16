<?php

declare(strict_types=1);

namespace Nene2\Tests\Example\Note;

use Nene2\Example\Note\CreateNoteInput;
use Nene2\Example\Note\CreateNoteUseCase;
use Nene2\Example\Note\DeleteNoteByIdInput;
use Nene2\Example\Note\DeleteNoteUseCase;
use Nene2\Example\Note\NoteNotFoundException;
use PHPUnit\Framework\TestCase;

final class DeleteNoteUseCaseTest extends TestCase
{
    public function testDeletesExistingNote(): void
    {
        $repository = new InMemoryNoteRepository([]);
        $createUseCase = new CreateNoteUseCase($repository);
        $deleteUseCase = new DeleteNoteUseCase($repository);

        $created = $createUseCase->execute(new CreateNoteInput(title: 'Hello', body: 'World'));
        $deleteUseCase->execute(new DeleteNoteByIdInput($created->id));

        self::assertNull($repository->findById($created->id));
    }

    public function testThrowsNoteNotFoundExceptionWhenNoteIsAbsent(): void
    {
        $repository = new InMemoryNoteRepository([]);
        $deleteUseCase = new DeleteNoteUseCase($repository);

        $this->expectException(NoteNotFoundException::class);

        $deleteUseCase->execute(new DeleteNoteByIdInput(99));
    }
}
