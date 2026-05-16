<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

final readonly class UpdateNoteUseCase implements UpdateNoteUseCaseInterface
{
    public function __construct(
        private NoteRepositoryInterface $notes,
    ) {
    }

    public function execute(UpdateNoteInput $input): UpdateNoteOutput
    {
        $note = $this->notes->findById($input->id);

        if ($note === null) {
            throw new NoteNotFoundException($input->id);
        }

        $updated = new Note(title: $input->title, body: $input->body, id: $input->id);
        $this->notes->update($updated);

        return new UpdateNoteOutput(id: $input->id, title: $input->title, body: $input->body);
    }
}
