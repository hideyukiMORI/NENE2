<?php

declare(strict_types=1);

namespace Nene2\Tests\Middleware;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Middleware\ApiKeyAuthenticationMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ApiKeyAuthenticationMiddlewareTest extends TestCase
{
    // --- protectedPaths (existing behaviour) ---

    public function testProtectedPathAllowsValidKey(): void
    {
        $factory = new Psr17Factory();
        $mw = $this->make($factory, protectedPaths: ['/admin']);
        $request = $factory
            ->createServerRequest('POST', 'https://example.test/admin')
            ->withHeader('X-NENE2-API-Key', 'secret');

        self::assertSame(200, $mw->process($request, $this->okHandler($factory))->getStatusCode());
    }

    public function testProtectedPathRejectsUnlistedPath(): void
    {
        $factory = new Psr17Factory();
        $mw = $this->make($factory, protectedPaths: ['/admin']);
        $request = $factory->createServerRequest('GET', 'https://example.test/public');

        self::assertSame(200, $mw->process($request, $this->okHandler($factory))->getStatusCode());
    }

    public function testProtectedPathRejectsMissingKey(): void
    {
        $factory = new Psr17Factory();
        $mw = $this->make($factory, protectedPaths: ['/admin']);
        $request = $factory->createServerRequest('POST', 'https://example.test/admin');

        self::assertSame(401, $mw->process($request, $this->okHandler($factory))->getStatusCode());
    }

    // --- protectedPathPrefixes ---

    public function testPrefixProtectsDynamicPath(): void
    {
        $factory = new Psr17Factory();
        $mw = $this->make($factory, protectedPathPrefixes: ['/admin/']);
        $request = $factory->createServerRequest('DELETE', 'https://example.test/admin/users/42');

        self::assertSame(401, $mw->process($request, $this->okHandler($factory))->getStatusCode());
    }

    public function testPrefixPassesThroughNonMatchingPath(): void
    {
        $factory = new Psr17Factory();
        $mw = $this->make($factory, protectedPathPrefixes: ['/admin/']);
        $request = $factory->createServerRequest('GET', 'https://example.test/public/items');

        self::assertSame(200, $mw->process($request, $this->okHandler($factory))->getStatusCode());
    }

    public function testPrefixAllowsValidKey(): void
    {
        $factory = new Psr17Factory();
        $mw = $this->make($factory, protectedPathPrefixes: ['/admin/']);
        $request = $factory
            ->createServerRequest('DELETE', 'https://example.test/admin/users/42')
            ->withHeader('X-NENE2-API-Key', 'secret');

        self::assertSame(200, $mw->process($request, $this->okHandler($factory))->getStatusCode());
    }

    public function testProtectedPathsTakesPrecedenceOverPrefixes(): void
    {
        $factory = new Psr17Factory();
        // /admin is in protectedPaths → exact match only; /admin/users is not in protectedPaths → pass through
        $mw = $this->make($factory, protectedPaths: ['/admin'], protectedPathPrefixes: ['/admin/']);
        $request = $factory->createServerRequest('GET', 'https://example.test/admin/users');

        // protectedPaths mode: /admin/users not in list → passes through
        self::assertSame(200, $mw->process($request, $this->okHandler($factory))->getStatusCode());
    }

    // --- protectedMethods ---

    public function testMethodFilterSkipsSafeMethod(): void
    {
        $factory = new Psr17Factory();
        // Only POST/PUT/DELETE require API key — GET passes through
        $mw = $this->make($factory, protectedMethods: ['POST', 'PUT', 'DELETE']);
        $request = $factory->createServerRequest('GET', 'https://example.test/tags');

        self::assertSame(200, $mw->process($request, $this->okHandler($factory))->getStatusCode());
    }

    public function testMethodFilterProtectsListedMethod(): void
    {
        $factory = new Psr17Factory();
        $mw = $this->make($factory, protectedMethods: ['POST', 'PUT', 'DELETE']);
        $request = $factory->createServerRequest('POST', 'https://example.test/tags');

        self::assertSame(401, $mw->process($request, $this->okHandler($factory))->getStatusCode());
    }

    public function testMethodFilterCombinedWithPrefix(): void
    {
        $factory = new Psr17Factory();
        // GET /admin/users → passes through (method not in list)
        // DELETE /admin/users/1 → protected (method + prefix match)
        $mw = $this->make(
            $factory,
            protectedPathPrefixes: ['/admin/'],
            protectedMethods: ['POST', 'DELETE'],
        );

        $getRequest = $factory->createServerRequest('GET', 'https://example.test/admin/users');
        self::assertSame(200, $mw->process($getRequest, $this->okHandler($factory))->getStatusCode());

        $deleteRequest = $factory->createServerRequest('DELETE', 'https://example.test/admin/users/1');
        self::assertSame(401, $mw->process($deleteRequest, $this->okHandler($factory))->getStatusCode());
    }

    // --- OPTIONS always passes through ---

    public function testOptionsAlwaysPassesThrough(): void
    {
        $factory = new Psr17Factory();
        $mw = $this->make($factory);
        $request = $factory->createServerRequest('OPTIONS', 'https://example.test/anything');

        self::assertSame(200, $mw->process($request, $this->okHandler($factory))->getStatusCode());
    }

    // --- credential_type attribute ---

    public function testSetsCredentialTypeAttribute(): void
    {
        $factory = new Psr17Factory();
        $mw = $this->make($factory, protectedPaths: ['/admin']);

        $capture = new \stdClass();
        $capture->request = null;
        $handler = new class ($factory, $capture) implements RequestHandlerInterface {
            public function __construct(
                private readonly Psr17Factory $factory,
                private readonly \stdClass $capture,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->capture->request = $request;

                return $this->factory->createResponse(200);
            }
        };

        $request = $factory
            ->createServerRequest('POST', 'https://example.test/admin')
            ->withHeader('X-NENE2-API-Key', 'secret');

        $mw->process($request, $handler);

        self::assertNotNull($capture->request);
        self::assertSame('api_key', $capture->request->getAttribute('nene2.auth.credential_type'));
    }

    // --- excludedPaths ---

    public function testExcludedPathPassesThroughWithoutKeyInProtectAllMode(): void
    {
        $factory = new Psr17Factory();
        // No protectedPaths / protectedPathPrefixes → protect-all mode; /health is excluded
        $mw = $this->make($factory, excludedPaths: ['/health', '/']);
        $request = $factory->createServerRequest('GET', 'https://example.test/health');

        self::assertSame(200, $mw->process($request, $this->okHandler($factory))->getStatusCode());
    }

    public function testNonExcludedPathIsStillProtectedInProtectAllMode(): void
    {
        $factory = new Psr17Factory();
        $mw = $this->make($factory, excludedPaths: ['/health']);
        $request = $factory->createServerRequest('GET', 'https://example.test/admin');

        self::assertSame(401, $mw->process($request, $this->okHandler($factory))->getStatusCode());
    }

    public function testExcludedPathTakesPrecedenceOverProtectedPaths(): void
    {
        $factory = new Psr17Factory();
        $mw = $this->make($factory, protectedPaths: ['/health'], excludedPaths: ['/health']);
        $request = $factory->createServerRequest('GET', 'https://example.test/health');

        // excluded wins over protected
        self::assertSame(200, $mw->process($request, $this->okHandler($factory))->getStatusCode());
    }

    /**
     * @param list<string> $protectedPaths
     * @param list<string> $protectedPathPrefixes
     * @param list<string> $protectedMethods
     * @param list<string> $excludedPaths
     */
    private function make(
        Psr17Factory $factory,
        array $protectedPaths = [],
        array $protectedPathPrefixes = [],
        array $protectedMethods = [],
        array $excludedPaths = [],
    ): ApiKeyAuthenticationMiddleware {
        return new ApiKeyAuthenticationMiddleware(
            new ProblemDetailsResponseFactory($factory, $factory),
            'secret',
            $protectedPaths,
            'X-NENE2-API-Key',
            $protectedPathPrefixes,
            $protectedMethods,
            $excludedPaths,
        );
    }

    private function okHandler(Psr17Factory $factory): RequestHandlerInterface
    {
        return new class ($factory) implements RequestHandlerInterface {
            public function __construct(private readonly Psr17Factory $factory)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200);
            }
        };
    }
}
