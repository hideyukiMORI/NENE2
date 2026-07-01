<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class CreateTagHandler
{
    /** Matches the `tags.name` VARCHAR(255) column (character count). */
    private const int NAME_MAX_LENGTH = 255;

    public function __construct(
        private CreateTagUseCaseInterface $useCase,
        private JsonResponseFactory $response,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = JsonRequestBodyParser::parse($request);

        $nameRaw = $body['name'] ?? '';
        $name = is_string($nameRaw) ? trim($nameRaw) : '';

        // Reject non-strings and over-length input with 422 rather than letting an
        // oversized value reach the VARCHAR(255) column and surface as a 500.
        if (!is_string($nameRaw)) {
            throw new ValidationException([new ValidationError('name', 'Name must be a string.', 'invalid')]);
        }

        if ($name === '') {
            throw new ValidationException([new ValidationError('name', 'Name is required.', 'required')]);
        }

        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new ValidationException([new ValidationError('name', 'Name must not exceed 255 characters.', 'max_length')]);
        }

        $output = $this->useCase->execute(new CreateTagInput(name: $name));

        return $this->response->create(
            ['id' => $output->id, 'name' => $output->name],
            201,
            ['Location' => '/examples/tags/' . $output->id],
        );
    }
}
