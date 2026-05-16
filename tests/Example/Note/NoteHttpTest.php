<?php

declare(strict_types=1);

namespace Nene2\Tests\Example\Note;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Example\Note\CreateNoteHandler;
use Nene2\Example\Note\CreateNoteUseCase;
use Nene2\Example\Note\DeleteNoteHandler;
use Nene2\Example\Note\DeleteNoteUseCase;
use Nene2\Example\Note\GetNoteByIdHandler;
use Nene2\Example\Note\GetNoteByIdUseCase;
use Nene2\Example\Note\Note;
use Nene2\Example\Note\NoteNotFoundExceptionHandler;
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class NoteHttpTest extends TestCase
{
    private Psr17Factory $factory;
    private InMemoryNoteRepository $repository;
    private RequestHandlerInterface $application;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->repository = new InMemoryNoteRepository();

        $jsonResponse = new JsonResponseFactory($this->factory, $this->factory);
        $problemDetails = new ProblemDetailsResponseFactory($this->factory, $this->factory);

        $this->application = (new RuntimeApplicationFactory(
            $this->factory,
            $this->factory,
            getNoteByIdHandler: new GetNoteByIdHandler(
                new GetNoteByIdUseCase($this->repository),
                $jsonResponse,
            ),
            createNoteHandler: new CreateNoteHandler(
                new CreateNoteUseCase($this->repository),
                $jsonResponse,
            ),
            deleteNoteHandler: new DeleteNoteHandler(
                new DeleteNoteUseCase($this->repository),
                $this->factory,
            ),
            domainExceptionHandlers: [new NoteNotFoundExceptionHandler($problemDetails)],
        ))->create();
    }

    public function testGetNoteByIdReturnsNote(): void
    {
        $id = $this->repository->save(new Note(title: 'Hello', body: 'World'));

        $response = $this->application->handle(
            $this->factory->createServerRequest('GET', "https://example.test/examples/notes/{$id}"),
        );
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame($id, $payload['id']);
        self::assertSame('Hello', $payload['title']);
        self::assertSame('World', $payload['body']);
    }

    public function testGetNoteByIdReturns404WhenNoteIsAbsent(): void
    {
        $response = $this->application->handle(
            $this->factory->createServerRequest('GET', 'https://example.test/examples/notes/99'),
        );
        $payload = $this->decodeJson($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('https://nene2.dev/problems/not-found', $payload['type']);
        self::assertSame('Not Found', $payload['title']);
    }

    public function testGetNoteByIdReturns404ForInvalidId(): void
    {
        $response = $this->application->handle(
            $this->factory->createServerRequest('GET', 'https://example.test/examples/notes/0'),
        );
        $payload = $this->decodeJson($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('https://nene2.dev/problems/not-found', $payload['type']);
    }

    public function testPostNoteCreatesNoteAndReturns201WithLocation(): void
    {
        $body = $this->factory->createStream(json_encode(['title' => 'My Note', 'body' => 'Content'], JSON_THROW_ON_ERROR));
        $response = $this->application->handle(
            $this->factory->createServerRequest('POST', 'https://example.test/examples/notes')->withBody($body),
        );
        $payload = $this->decodeJson($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertStringStartsWith('/examples/notes/', $response->getHeaderLine('Location'));
        self::assertSame('My Note', $payload['title']);
        self::assertSame('Content', $payload['body']);
        self::assertIsInt($payload['id']);
    }

    public function testPostNoteReturns422WhenTitleIsMissing(): void
    {
        $body = $this->factory->createStream(json_encode(['body' => 'Content'], JSON_THROW_ON_ERROR));
        $response = $this->application->handle(
            $this->factory->createServerRequest('POST', 'https://example.test/examples/notes')->withBody($body),
        );
        $payload = $this->decodeJson($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('https://nene2.dev/problems/validation-failed', $payload['type']);
        self::assertIsArray($payload['errors']);

        $fields = array_column($payload['errors'], 'field');
        self::assertContains('title', $fields);
    }

    public function testPostNoteReturns422WhenBodyIsMissing(): void
    {
        $body = $this->factory->createStream(json_encode(['title' => 'Hello'], JSON_THROW_ON_ERROR));
        $response = $this->application->handle(
            $this->factory->createServerRequest('POST', 'https://example.test/examples/notes')->withBody($body),
        );
        $payload = $this->decodeJson($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('https://nene2.dev/problems/validation-failed', $payload['type']);

        $fields = array_column($payload['errors'], 'field');
        self::assertContains('body', $fields);
    }

    public function testDeleteNoteReturns204(): void
    {
        $id = $this->repository->save(new Note(title: 'To delete', body: 'Body'));

        $response = $this->application->handle(
            $this->factory->createServerRequest('DELETE', "https://example.test/examples/notes/{$id}"),
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertNull($this->repository->findById($id));
    }

    public function testDeleteNoteReturns404WhenNoteIsAbsent(): void
    {
        $response = $this->application->handle(
            $this->factory->createServerRequest('DELETE', 'https://example.test/examples/notes/99'),
        );
        $payload = $this->decodeJson($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('https://nene2.dev/problems/not-found', $payload['type']);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(ResponseInterface $response): array
    {
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);

        return $payload;
    }
}
