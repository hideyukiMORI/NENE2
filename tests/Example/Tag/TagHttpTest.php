<?php

declare(strict_types=1);

namespace Nene2\Tests\Example\Tag;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Example\Tag\CreateTagHandler;
use Nene2\Example\Tag\CreateTagUseCase;
use Nene2\Example\Tag\GetTagByIdHandler;
use Nene2\Example\Tag\GetTagByIdUseCase;
use Nene2\Example\Tag\ListTagsHandler;
use Nene2\Example\Tag\ListTagsUseCase;
use Nene2\Example\Tag\Tag;
use Nene2\Example\Tag\TagNotFoundExceptionHandler;
use Nene2\Example\Tag\TagRouteRegistrar;
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class TagHttpTest extends TestCase
{
    private Psr17Factory $factory;
    private InMemoryTagRepository $repository;
    private RequestHandlerInterface $application;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->repository = new InMemoryTagRepository();

        $jsonResponse = new JsonResponseFactory($this->factory, $this->factory);
        $problemDetails = new ProblemDetailsResponseFactory($this->factory, $this->factory);

        $registrar = new TagRouteRegistrar(
            new ListTagsHandler(new ListTagsUseCase($this->repository), $jsonResponse),
            new GetTagByIdHandler(new GetTagByIdUseCase($this->repository), $jsonResponse),
            new CreateTagHandler(new CreateTagUseCase($this->repository), $jsonResponse),
        );

        $this->application = (new RuntimeApplicationFactory(
            $this->factory,
            $this->factory,
            domainExceptionHandlers: [new TagNotFoundExceptionHandler($problemDetails)],
            routeRegistrars: [$registrar],
        ))->create();
    }

    public function testListTagsReturnsEmptyList(): void
    {
        $response = $this->request('GET', '/examples/tags');
        $payload = $this->decode($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $payload['items']);
        self::assertSame(20, $payload['limit']);
        self::assertSame(0, $payload['offset']);
    }

    public function testListTagsReturnsTags(): void
    {
        $this->repository->save(new Tag(name: 'php'));
        $this->repository->save(new Tag(name: 'api'));

        $response = $this->request('GET', '/examples/tags');
        $payload = $this->decode($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $payload['items']);
        self::assertSame('php', $payload['items'][0]['name']);
    }

    public function testGetTagByIdReturnsTag(): void
    {
        $id = $this->repository->save(new Tag(name: 'php'));

        $response = $this->request('GET', "/examples/tags/{$id}");
        $payload = $this->decode($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($id, $payload['id']);
        self::assertSame('php', $payload['name']);
    }

    public function testGetTagByIdReturns404WhenAbsent(): void
    {
        $response = $this->request('GET', '/examples/tags/99');
        $payload = $this->decode($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('Not Found', $payload['title']);
    }

    public function testGetTagByIdReturns404ForInvalidId(): void
    {
        $response = $this->request('GET', '/examples/tags/0');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testCreateTagReturns201(): void
    {
        $response = $this->request('POST', '/examples/tags', ['name' => 'php']);
        $payload = $this->decode($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('php', $payload['name']);
        self::assertStringEndsWith('/examples/tags/' . $payload['id'], $response->getHeaderLine('Location'));
    }

    public function testCreateTagReturns422WhenNameMissing(): void
    {
        $response = $this->request('POST', '/examples/tags', ['name' => '']);
        $payload = $this->decode($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertNotEmpty($payload['errors']);
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $path, ?array $body = null): ResponseInterface
    {
        $request = $this->factory->createServerRequest($method, "https://example.test{$path}");

        if ($body !== null) {
            $stream = $this->factory->createStream((string) json_encode($body));
            $request = $request->withBody($stream);
        }

        return $this->application->handle($request);
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), associative: true);
    }
}
