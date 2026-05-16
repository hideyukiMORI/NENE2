<?php

declare(strict_types=1);

namespace Nene2\Tests\Example\Note;

use Nene2\Example\Note\CreateNoteInput;
use Nene2\Example\Note\CreateNoteUseCase;
use Nene2\Example\Note\NoteNotFoundException;
use Nene2\Example\Note\UpdateNoteInput;
use Nene2\Example\Note\UpdateNoteUseCase;
use PHPUnit\Framework\TestCase;

final class UpdateNoteUseCaseTest extends TestCase
{
    public function testUpdatesExistingNote(): void
    {
        $repository = new InMemoryNoteRepository([]);
        $createUseCase = new CreateNoteUseCase($repository);
        $updateUseCase = new UpdateNoteUseCase($repository);

        $created = $createUseCase->execute(new CreateNoteInput(title: 'Original', body: 'Old body'));
        $output = $updateUseCase->execute(new UpdateNoteInput(id: $created->id, title: 'Updated', body: 'New body'));

        self::assertSame($created->id, $output->id);
        self::assertSame('Updated', $output->title);
        self::assertSame('New body', $output->body);

        $stored = $repository->findById($created->id);
        self::assertNotNull($stored);
        self::assertSame('Updated', $stored->title);
        self::assertSame('New body', $stored->body);
    }

    public function testThrowsNoteNotFoundExceptionWhenNoteIsAbsent(): void
    {
        $repository = new InMemoryNoteRepository([]);
        $updateUseCase = new UpdateNoteUseCase($repository);

        $this->expectException(NoteNotFoundException::class);

        $updateUseCase->execute(new UpdateNoteInput(id: 99, title: 'X', body: 'Y'));
    }
}
