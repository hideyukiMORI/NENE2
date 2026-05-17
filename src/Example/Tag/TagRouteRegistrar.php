<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

use Nene2\Routing\Router;
use Psr\Http\Message\ServerRequestInterface;

final readonly class TagRouteRegistrar
{
    public function __construct(
        private ListTagsHandler $listHandler,
        private GetTagByIdHandler $getHandler,
        private CreateTagHandler $createHandler,
    ) {
    }

    public function __invoke(Router $router): void
    {
        $listHandler = $this->listHandler;
        $getHandler = $this->getHandler;
        $createHandler = $this->createHandler;

        $router->get('/examples/tags', static fn (ServerRequestInterface $request) => $listHandler->handle($request));
        $router->get('/examples/tags/{id}', static fn (ServerRequestInterface $request) => $getHandler->handle($request));
        $router->post('/examples/tags', static fn (ServerRequestInterface $request) => $createHandler->handle($request));
    }
}
