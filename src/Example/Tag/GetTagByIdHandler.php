<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class GetTagByIdHandler
{
    public function __construct(
        private GetTagByIdUseCaseInterface $useCase,
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

        $output = $this->useCase->execute(new GetTagByIdInput($id));

        return $this->response->create(['id' => $output->id, 'name' => $output->name]);
    }
}
