<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

use Nene2\Routing\Router;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class DeleteNoteHandler
{
    public function __construct(
        private DeleteNoteUseCaseInterface $useCase,
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $parameters = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $id = (int) ($parameters['id'] ?? 0);

        if ($id <= 0) {
            throw new NoteNotFoundException($id);
        }

        $this->useCase->execute(new DeleteNoteByIdInput($id));

        return $this->responseFactory->createResponse(204);
    }
}
