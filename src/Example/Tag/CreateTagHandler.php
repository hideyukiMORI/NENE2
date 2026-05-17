<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

use Nene2\Http\JsonResponseFactory;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class CreateTagHandler
{
    public function __construct(
        private CreateTagUseCaseInterface $useCase,
        private JsonResponseFactory $response,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) json_decode((string) $request->getBody(), associative: true);

        $name = trim((string) ($body['name'] ?? ''));

        if ($name === '') {
            throw new ValidationException([new ValidationError('name', 'Name is required.', 'required')]);
        }

        $output = $this->useCase->execute(new CreateTagInput(name: $name));

        return $this->response->create(
            ['id' => $output->id, 'name' => $output->name],
            201,
            ['Location' => '/examples/tags/' . $output->id],
        );
    }
}
