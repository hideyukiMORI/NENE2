<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class CreateNoteHandler
{
    /** Matches the `notes.title` VARCHAR(255) column (character count). */
    private const int TITLE_MAX_LENGTH = 255;

    /** Matches the `notes.body` TEXT column (byte length). */
    private const int BODY_MAX_BYTES = 65535;

    public function __construct(
        private CreateNoteUseCaseInterface $useCase,
        private JsonResponseFactory $response,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = JsonRequestBodyParser::parse($request);

        $errors = [];

        $titleRaw = $body['title'] ?? '';
        $bodyRaw = $body['body'] ?? '';

        $title = is_string($titleRaw) ? trim($titleRaw) : '';
        $noteBody = is_string($bodyRaw) ? trim($bodyRaw) : '';

        // Reject non-strings and over-length input with 422 rather than letting
        // an oversized value reach the database and surface as a 500 (title is a
        // VARCHAR(255) character column; body is a TEXT byte column).
        if (!is_string($titleRaw)) {
            $errors[] = new ValidationError('title', 'Title must be a string.', 'invalid');
        } elseif ($title === '') {
            $errors[] = new ValidationError('title', 'Title is required.', 'required');
        } elseif (mb_strlen($title) > self::TITLE_MAX_LENGTH) {
            $errors[] = new ValidationError('title', 'Title must not exceed 255 characters.', 'max_length');
        }

        if (!is_string($bodyRaw)) {
            $errors[] = new ValidationError('body', 'Body must be a string.', 'invalid');
        } elseif ($noteBody === '') {
            $errors[] = new ValidationError('body', 'Body is required.', 'required');
        } elseif (strlen($noteBody) > self::BODY_MAX_BYTES) {
            $errors[] = new ValidationError('body', 'Body must not exceed 65535 bytes.', 'max_length');
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $output = $this->useCase->execute(new CreateNoteInput(title: $title, body: $noteBody));

        return $this->response->create(
            ['id' => $output->id, 'title' => $output->title, 'body' => $output->body],
            201,
            ['Location' => '/examples/notes/' . $output->id],
        );
    }
}
