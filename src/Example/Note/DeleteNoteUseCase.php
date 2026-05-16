<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

final readonly class DeleteNoteUseCase implements DeleteNoteUseCaseInterface
{
    public function __construct(
        private NoteRepositoryInterface $notes,
    ) {
    }

    public function execute(DeleteNoteByIdInput $input): void
    {
        $note = $this->notes->findById($input->id);

        if ($note === null) {
            throw new NoteNotFoundException($input->id);
        }

        $this->notes->delete($input->id);
    }
}
