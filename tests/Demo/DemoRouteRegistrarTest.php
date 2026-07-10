<?php

declare(strict_types=1);

namespace Nene2\Tests\Demo;

use Nene2\Demo\DemoCapacityGuardInterface;
use Nene2\Demo\DemoConfig;
use Nene2\Demo\DemoDataSeederInterface;
use Nene2\Demo\DemoRouteRegistrar;
use Nene2\Demo\DemoSessionSeaterInterface;
use Nene2\Demo\DemoTemplateKeyInterface;
use Nene2\Demo\DisposableOrgProvisionerInterface;
use Nene2\Demo\ProvisionedDemoOrg;
use Nene2\Demo\StartDisposableDemoHandler;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class DemoRouteRegistrarTest extends TestCase
{
    public function testRegistersTheDemoRouteAndPassesTheTemplateParameter(): void
    {
        $router = new Router();
        (new DemoRouteRegistrar($this->makeHandler()))($router);

        $factory = new Psr17Factory();
        $response = $router->handle($factory->createServerRequest('GET', 'https://example.test/demo/kensetsu'));

        // Demo mode is off in this wiring, so reaching the handler's gate (a
        // problem+json 404, not the router's plain not-found) proves both the
        // route registration and the {template} parameter plumbing.
        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Demo mode is not enabled', (string) $response->getBody());
    }

    public function testAcceptsAnyPsr15HandlerSoProductsCanDecorate(): void
    {
        $factory = new Psr17Factory();
        $decorator = new class ($this->makeHandler()) implements RequestHandlerInterface {
            public function __construct(private readonly StartDisposableDemoHandler $inner)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = $this->inner->handle($request);

                return $response->withHeader('X-Decorated', '1');
            }
        };

        $router = new Router();
        (new DemoRouteRegistrar($decorator))($router);

        $response = $router->handle($factory->createServerRequest('GET', 'https://example.test/demo/kensetsu'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('1', $response->getHeaderLine('X-Decorated'), 'the registrar must accept product decorators (ADR 0018)');
    }

    private function makeHandler(): StartDisposableDemoHandler
    {
        $factory = new Psr17Factory();

        $guard = new class () implements DemoCapacityGuardInterface {
            public function assertHasCapacity(): void
            {
            }

            public function assertNotThrottled(ServerRequestInterface $request): void
            {
            }
        };

        $provisioner = new class () implements DisposableOrgProvisionerInterface {
            public function provision(string $slug, string $template): ProvisionedDemoOrg
            {
                return new ProvisionedDemoOrg(1, $slug, 1);
            }
        };

        $seeder = new class () implements DemoDataSeederInterface {
            public function seed(int $orgId, DemoTemplateKeyInterface $template): void
            {
            }
        };

        $seater = new class ($factory) implements DemoSessionSeaterInterface {
            public function __construct(private readonly Psr17Factory $factory)
            {
            }

            public function seatAndRedirect(ServerRequestInterface $request, ProvisionedDemoOrg $org): ResponseInterface
            {
                return $this->factory->createResponse(302);
            }
        };

        return new StartDisposableDemoHandler(
            new DemoConfig(demoMode: false),
            $guard,
            $provisioner,
            $seeder,
            $seater,
            new ProblemDetailsResponseFactory($factory, $factory),
            FakeDemoTemplate::class,
        );
    }
}
