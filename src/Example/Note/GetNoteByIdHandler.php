<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class GetNoteByIdHandler
{
    public function __construct(
        private GetNoteByIdUseCaseInterface $useCase,
        private JsonResponseFactory $response,
        private ProblemDetailsResponseFactory $problemDetails,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $parameters = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $id = (int) ($parameters['id'] ?? 0);

        if ($id <= 0) {
            return $this->problemDetails->create(
                $request,
                'not-found',
                'Not Found',
                404,
                'The requested note was not found.',
            );
        }

        try {
            $output = $this->useCase->execute(new GetNoteByIdInput($id));
        } catch (NoteNotFoundException) {
            return $this->problemDetails->create(
                $request,
                'not-found',
                'Not Found',
                404,
                'The requested note was not found.',
            );
        }

        return $this->response->create([
            'id' => $output->id,
            'title' => $output->title,
            'body' => $output->body,
        ]);
    }
}
