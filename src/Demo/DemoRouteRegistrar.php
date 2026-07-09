<?php

declare(strict_types=1);

namespace Nene2\Demo;

use Nene2\Routing\Router;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Registers the demo start route (`GET /demo/{template}` by default) on the
 * application {@see Router} during runtime assembly.
 *
 * The route is public and org-less — it *creates* organizations — so products
 * with tenant-resolution middleware must exempt this path from org resolution.
 * The runtime gate lives inside {@see StartDisposableDemoHandler}
 * ({@see DemoConfig::$demoMode}), so registering the route on an instance with
 * demo mode off is safe: it answers 404.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class DemoRouteRegistrar
{
    public function __construct(
        private StartDisposableDemoHandler $handler,
        private string $path = '/demo/{' . StartDisposableDemoHandler::TEMPLATE_PARAMETER . '}',
    ) {
    }

    public function __invoke(Router $router): void
    {
        $handler = $this->handler;
        $router->get($this->path, static fn (ServerRequestInterface $request) => $handler->handle($request));
    }
}
