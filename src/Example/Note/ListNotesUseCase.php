<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

final readonly class ListNotesUseCase implements ListNotesUseCaseInterface
{
    public function __construct(
        private NoteRepositoryInterface $notes,
    ) {
    }

    public function execute(ListNotesInput $input): ListNotesOutput
    {
        $notes = $this->notes->findAll($input->limit, $input->offset);

        $items = array_map(
            static fn (Note $note) => new ListNoteItem(
                id: (int) $note->id,
                title: $note->title,
                body: $note->body,
            ),
            $notes,
        );

        return new ListNotesOutput(
            items: $items,
            limit: $input->limit,
            offset: $input->offset,
        );
    }
}
