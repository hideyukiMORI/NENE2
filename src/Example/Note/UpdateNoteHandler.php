<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class UpdateNoteHandler
{
    public function __construct(
        private UpdateNoteUseCaseInterface $useCase,
        private JsonResponseFactory $response,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $parameters = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $id = (int) ($parameters['id'] ?? 0);

        if ($id <= 0) {
            throw new NoteNotFoundException($id);
        }

        $body = (array) json_decode((string) $request->getBody(), associative: true);

        $errors = [];

        $title = trim((string) ($body['title'] ?? ''));
        $noteBody = trim((string) ($body['body'] ?? ''));

        if ($title === '') {
            $errors[] = new ValidationError('title', 'Title is required.', 'required');
        }

        if ($noteBody === '') {
            $errors[] = new ValidationError('body', 'Body is required.', 'required');
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $output = $this->useCase->execute(new UpdateNoteInput(id: $id, title: $title, body: $noteBody));

        return $this->response->create(['id' => $output->id, 'title' => $output->title, 'body' => $output->body]);
    }
}
