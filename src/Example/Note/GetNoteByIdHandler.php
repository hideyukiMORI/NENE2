<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class GetNoteByIdHandler
{
    public function __construct(
        private GetNoteByIdUseCaseInterface $useCase,
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

        $output = $this->useCase->execute(new GetNoteByIdInput($id));

        return $this->response->create([
            'id' => $output->id,
            'title' => $output->title,
            'body' => $output->body,
        ]);
    }
}
