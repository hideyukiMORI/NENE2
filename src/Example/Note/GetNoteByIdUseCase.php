<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

final readonly class GetNoteByIdUseCase implements GetNoteByIdUseCaseInterface
{
    public function __construct(
        private NoteRepositoryInterface $notes,
    ) {
    }

    public function execute(GetNoteByIdInput $input): GetNoteByIdOutput
    {
        $note = $this->notes->findById($input->id);

        if ($note === null) {
            throw new NoteNotFoundException($input->id);
        }

        return new GetNoteByIdOutput(
            id: $note->id ?? $input->id,
            title: $note->title,
            body: $note->body,
        );
    }
}
