<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

use Nene2\Routing\Router;
use Psr\Http\Message\ServerRequestInterface;

final readonly class NoteRouteRegistrar
{
    public function __construct(
        private GetNoteByIdHandler $getHandler,
        private CreateNoteHandler $createHandler,
        private UpdateNoteHandler $updateHandler,
        private DeleteNoteHandler $deleteHandler,
        private ListNotesHandler $listHandler,
    ) {
    }

    public function __invoke(Router $router): void
    {
        $getHandler = $this->getHandler;
        $createHandler = $this->createHandler;
        $updateHandler = $this->updateHandler;
        $deleteHandler = $this->deleteHandler;
        $listHandler = $this->listHandler;

        $router->get('/examples/notes', static fn (ServerRequestInterface $request) => $listHandler->handle($request));
        $router->get('/examples/notes/{id}', static fn (ServerRequestInterface $request) => $getHandler->handle($request));
        $router->post('/examples/notes', static fn (ServerRequestInterface $request) => $createHandler->handle($request));
        $router->put('/examples/notes/{id}', static fn (ServerRequestInterface $request) => $updateHandler->handle($request));
        $router->delete('/examples/notes/{id}', static fn (ServerRequestInterface $request) => $deleteHandler->handle($request));
    }
}
