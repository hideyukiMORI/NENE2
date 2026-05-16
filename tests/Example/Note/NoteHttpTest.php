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
use Nene2\Example\Note\ListNotesHandler;
use Nene2\Example\Note\ListNotesUseCase;
use Nene2\Example\Note\Note;
use Nene2\Example\Note\NoteNotFoundExceptionHandler;
use Nene2\Example\Note\UpdateNoteHandler;
use Nene2\Example\Note\UpdateNoteUseCase;
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
            listNotesHandler: new ListNotesHandler(
                new ListNotesUseCase($this->repository),
                $jsonResponse,
            ),
            updateNoteHandler: new UpdateNoteHandler(
                new UpdateNoteUseCase($this->repository),
                $jsonResponse,
            ),
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

    public function testListNotesReturnsEmptyItems(): void
    {
        $response = $this->application->handle(
            $this->factory->createServerRequest('GET', 'https://example.test/examples/notes'),
        );
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $payload['items']);
        self::assertSame(20, $payload['limit']);
        self::assertSame(0, $payload['offset']);
    }

    public function testListNotesReturnsCreatedNotes(): void
    {
        $this->repository->save(new Note(title: 'First', body: 'A'));
        $this->repository->save(new Note(title: 'Second', body: 'B'));

        $response = $this->application->handle(
            $this->factory->createServerRequest('GET', 'https://example.test/examples/notes'),
        );
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $payload['items']);
        self::assertSame('First', $payload['items'][0]['title']);
    }

    public function testListNotesRespectsLimitAndOffset(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->repository->save(new Note(title: "Note {$i}", body: 'Body'));
        }

        $response = $this->application->handle(
            $this->factory->createServerRequest('GET', 'https://example.test/examples/notes?limit=2&offset=1'),
        );
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $payload['items']);
        self::assertSame('Note 2', $payload['items'][0]['title']);
        self::assertSame(2, $payload['limit']);
        self::assertSame(1, $payload['offset']);
    }

    public function testListNotesReturns422ForInvalidLimit(): void
    {
        $response = $this->application->handle(
            $this->factory->createServerRequest('GET', 'https://example.test/examples/notes?limit=0'),
        );
        $payload = $this->decodeJson($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('https://nene2.dev/problems/validation-failed', $payload['type']);
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

    public function testPutNoteUpdatesNoteAndReturns200(): void
    {
        $id = $this->repository->save(new Note(title: 'Original', body: 'Old body'));

        $body = $this->factory->createStream(json_encode(['title' => 'Updated', 'body' => 'New body'], JSON_THROW_ON_ERROR));
        $response = $this->application->handle(
            $this->factory->createServerRequest('PUT', "https://example.test/examples/notes/{$id}")->withBody($body),
        );
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($id, $payload['id']);
        self::assertSame('Updated', $payload['title']);
        self::assertSame('New body', $payload['body']);

        $stored = $this->repository->findById($id);
        self::assertNotNull($stored);
        self::assertSame('Updated', $stored->title);
    }

    public function testPutNoteReturns404WhenNoteIsAbsent(): void
    {
        $body = $this->factory->createStream(json_encode(['title' => 'X', 'body' => 'Y'], JSON_THROW_ON_ERROR));
        $response = $this->application->handle(
            $this->factory->createServerRequest('PUT', 'https://example.test/examples/notes/99')->withBody($body),
        );
        $payload = $this->decodeJson($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('https://nene2.dev/problems/not-found', $payload['type']);
    }

    public function testPutNoteReturns422WhenTitleIsMissing(): void
    {
        $id = $this->repository->save(new Note(title: 'A', body: 'B'));
        $body = $this->factory->createStream(json_encode(['body' => 'Only body'], JSON_THROW_ON_ERROR));
        $response = $this->application->handle(
            $this->factory->createServerRequest('PUT', "https://example.test/examples/notes/{$id}")->withBody($body),
        );
        $payload = $this->decodeJson($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('https://nene2.dev/problems/validation-failed', $payload['type']);
        $fields = array_column($payload['errors'], 'field');
        self::assertContains('title', $fields);
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
