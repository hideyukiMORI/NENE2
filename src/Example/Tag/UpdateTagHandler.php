<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class UpdateTagHandler
{
    public function __construct(
        private UpdateTagUseCaseInterface $useCase,
        private JsonResponseFactory $response,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $parameters = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $id = (int) ($parameters['id'] ?? 0);

        if ($id <= 0) {
            throw new TagNotFoundException($id);
        }

        $body = (array) json_decode((string) $request->getBody(), associative: true);

        $name = trim((string) ($body['name'] ?? ''));

        if ($name === '') {
            throw new ValidationException([new ValidationError('name', 'Name is required.', 'required')]);
        }

        $output = $this->useCase->execute(new UpdateTagInput(id: $id, name: $name));

        return $this->response->create(['id' => $output->id, 'name' => $output->name]);
    }
}
