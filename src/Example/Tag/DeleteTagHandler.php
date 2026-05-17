<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

use Nene2\Routing\Router;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class DeleteTagHandler
{
    public function __construct(
        private DeleteTagUseCaseInterface $useCase,
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $parameters = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $id = (int) ($parameters['id'] ?? 0);

        if ($id <= 0) {
            throw new TagNotFoundException($id);
        }

        $this->useCase->execute(new DeleteTagByIdInput($id));

        return $this->responseFactory->createResponse(204);
    }
}
