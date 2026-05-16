<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

final readonly class CreateNoteUseCase implements CreateNoteUseCaseInterface
{
    public function __construct(
        private NoteRepositoryInterface $notes,
    ) {
    }

    public function execute(CreateNoteInput $input): CreateNoteOutput
    {
        $id = $this->notes->save(new Note(title: $input->title, body: $input->body));

        return new CreateNoteOutput(id: $id, title: $input->title, body: $input->body);
    }
}
