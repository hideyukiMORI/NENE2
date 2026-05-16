<?php

declare(strict_types=1);

namespace Nene2\Tests\Example\Note;

use Nene2\Example\Note\CreateNoteInput;
use Nene2\Example\Note\CreateNoteUseCase;
use Nene2\Example\Note\ListNotesInput;
use Nene2\Example\Note\ListNotesUseCase;
use PHPUnit\Framework\TestCase;

final class ListNotesUseCaseTest extends TestCase
{
    public function testReturnsEmptyListWhenNoNotesExist(): void
    {
        $repository = new InMemoryNoteRepository();
        $useCase = new ListNotesUseCase($repository);

        $output = $useCase->execute(new ListNotesInput());

        self::assertSame([], $output->items);
        self::assertSame(20, $output->limit);
        self::assertSame(0, $output->offset);
    }

    public function testReturnsAllNotesWithinLimit(): void
    {
        $repository = new InMemoryNoteRepository();
        $createUseCase = new CreateNoteUseCase($repository);
        $createUseCase->execute(new CreateNoteInput(title: 'First', body: 'A'));
        $createUseCase->execute(new CreateNoteInput(title: 'Second', body: 'B'));
        $createUseCase->execute(new CreateNoteInput(title: 'Third', body: 'C'));

        $output = (new ListNotesUseCase($repository))->execute(new ListNotesInput(limit: 10, offset: 0));

        self::assertCount(3, $output->items);
        self::assertSame('First', $output->items[0]->title);
        self::assertSame('Third', $output->items[2]->title);
    }

    public function testLimitRestrictsReturnedItems(): void
    {
        $repository = new InMemoryNoteRepository();
        $createUseCase = new CreateNoteUseCase($repository);

        for ($i = 1; $i <= 5; $i++) {
            $createUseCase->execute(new CreateNoteInput(title: "Note {$i}", body: 'Body'));
        }

        $output = (new ListNotesUseCase($repository))->execute(new ListNotesInput(limit: 2, offset: 0));

        self::assertCount(2, $output->items);
    }

    public function testOffsetSkipsItems(): void
    {
        $repository = new InMemoryNoteRepository();
        $createUseCase = new CreateNoteUseCase($repository);
        $createUseCase->execute(new CreateNoteInput(title: 'First', body: 'A'));
        $createUseCase->execute(new CreateNoteInput(title: 'Second', body: 'B'));
        $createUseCase->execute(new CreateNoteInput(title: 'Third', body: 'C'));

        $output = (new ListNotesUseCase($repository))->execute(new ListNotesInput(limit: 10, offset: 1));

        self::assertCount(2, $output->items);
        self::assertSame('Second', $output->items[0]->title);
    }
}
