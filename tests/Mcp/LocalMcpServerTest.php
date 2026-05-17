<?php

declare(strict_types=1);

namespace Nene2\Tests\Mcp;

use Nene2\Mcp\LocalMcpHttpClientInterface;
use Nene2\Mcp\LocalMcpHttpResponse;
use Nene2\Mcp\LocalMcpServer;
use Nene2\Mcp\LocalMcpToolCatalog;
use PHPUnit\Framework\TestCase;

final class LocalMcpServerTest extends TestCase
{
    public function testInitializeReturnsServerCapabilities(): void
    {
        $server = $this->server();

        $response = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [],
        ]);

        self::assertSame('2.0', $response['jsonrpc'] ?? null);
        self::assertSame(1, $response['id'] ?? null);
        self::assertSame('nene2-local-mcp', $response['result']['serverInfo']['name'] ?? null);
        self::assertSame(false, $response['result']['capabilities']['tools']['listChanged'] ?? null);
    }

    public function testToolsListReturnsCatalogTools(): void
    {
        $server = $this->server();

        $response = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
        ]);

        self::assertSame('getHealth', $response['result']['tools'][1]['name'] ?? null);
        self::assertSame(true, $response['result']['tools'][1]['annotations']['readOnlyHint'] ?? null);
        self::assertInstanceOf(\stdClass::class, $response['result']['tools'][1]['inputSchema']['properties'] ?? null);
    }

    public function testToolsListIncludesWriteToolsWithReadOnlyHintFalse(): void
    {
        $server = $this->server();

        $response = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
        ]);

        $tools = $response['result']['tools'] ?? [];
        $writeTools = array_values(array_filter($tools, static fn (array $t) => $t['annotations']['readOnlyHint'] === false));

        self::assertNotEmpty($writeTools);
        $names = array_column($writeTools, 'name');
        self::assertContains('createExampleNote', $names);
        self::assertContains('updateExampleNoteById', $names);
        self::assertContains('deleteExampleNoteById', $names);
    }

    public function testToolsCallInvokesLocalHttpApiAndReturnsRequestId(): void
    {
        $client = new RecordingLocalMcpHttpClient(new LocalMcpHttpResponse(
            200,
            ['x-request-id' => 'request-123'],
            '{"status":"ok","service":"NENE2"}',
        ));
        $server = $this->server($client);

        $response = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'getHealth',
                'arguments' => [],
            ],
        ]);

        self::assertSame('http://localhost:8080', $client->baseUrl);
        self::assertSame('/health', $client->path);
        self::assertSame('get', $client->lastMethod);
        self::assertSame(false, $response['result']['isError'] ?? null);
        self::assertSame('request-123', $response['result']['structuredContent']['requestId'] ?? null);
        self::assertSame('ok', $response['result']['structuredContent']['body']['status'] ?? null);
    }

    public function testToolsCallWithIdArgumentInterpolatesPath(): void
    {
        $client = new RecordingLocalMcpHttpClient(new LocalMcpHttpResponse(
            200,
            ['x-request-id' => 'req-456'],
            '{"id":5,"title":"Test","body":"Body content"}',
        ));
        $server = $this->server($client);

        $server->handle([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => [
                'name' => 'getExampleNoteById',
                'arguments' => ['id' => 5],
            ],
        ]);

        self::assertSame('get', $client->lastMethod);
        self::assertSame('/examples/notes/5', $client->path);
    }

    public function testToolsCallGetWithQueryArguments(): void
    {
        $client = new RecordingLocalMcpHttpClient(new LocalMcpHttpResponse(
            200,
            [],
            '{"items":[],"limit":10,"offset":5}',
        ));
        $server = $this->server($client);

        $server->handle([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'tools/call',
            'params' => [
                'name' => 'listExampleNotes',
                'arguments' => ['limit' => 10, 'offset' => 5],
            ],
        ]);

        self::assertSame('get', $client->lastMethod);
        self::assertSame('/examples/notes?limit=10&offset=5', $client->path);
    }

    public function testWriteToolWithoutAuthReturnsError(): void
    {
        $client = new RecordingLocalMcpHttpClient(
            new LocalMcpHttpResponse(201, [], '{}'),
            authenticated: false,
        );
        $server = $this->server($client);

        $response = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 10,
            'method' => 'tools/call',
            'params' => [
                'name' => 'createExampleNote',
                'arguments' => ['title' => 'Test', 'body' => 'Body'],
            ],
        ]);

        self::assertIsArray($response);
        self::assertArrayHasKey('error', $response);
        self::assertIsArray($response['error']);
        self::assertStringContainsString('NENE2_LOCAL_JWT_SECRET', $response['error']['message']);
        self::assertNull($client->lastMethod);
    }

    public function testToolsCallPostWriteToolSendsBodyArguments(): void
    {
        $client = new RecordingLocalMcpHttpClient(new LocalMcpHttpResponse(
            201,
            ['x-request-id' => 'req-789'],
            '{"id":1,"title":"New note","body":"Some content"}',
        ));
        $server = $this->server($client);

        $response = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'tools/call',
            'params' => [
                'name' => 'createExampleNote',
                'arguments' => ['title' => 'New note', 'body' => 'Some content'],
            ],
        ]);

        self::assertSame('post', $client->lastMethod);
        self::assertSame('/examples/notes', $client->path);
        self::assertSame(['title' => 'New note', 'body' => 'Some content'], $client->body);
        self::assertSame(false, $response['result']['isError'] ?? null);
    }

    public function testToolsCallPutInterpolatesPathAndSendsBody(): void
    {
        $client = new RecordingLocalMcpHttpClient(new LocalMcpHttpResponse(
            200,
            [],
            '{"id":3,"title":"Updated","body":"New body"}',
        ));
        $server = $this->server($client);

        $server->handle([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/call',
            'params' => [
                'name' => 'updateExampleNoteById',
                'arguments' => ['id' => 3, 'title' => 'Updated', 'body' => 'New body'],
            ],
        ]);

        self::assertSame('put', $client->lastMethod);
        self::assertSame('/examples/notes/3', $client->path);
        self::assertSame(['title' => 'Updated', 'body' => 'New body'], $client->body);
    }

    public function testToolsCallDeleteInterpolatesPath(): void
    {
        $client = new RecordingLocalMcpHttpClient(new LocalMcpHttpResponse(204, [], ''));
        $server = $this->server($client);

        $response = $server->handle([
            'jsonrpc' => '2.0',
            'id' => 8,
            'method' => 'tools/call',
            'params' => [
                'name' => 'deleteExampleNoteById',
                'arguments' => ['id' => 7],
            ],
        ]);

        self::assertSame('delete', $client->lastMethod);
        self::assertSame('/examples/notes/7', $client->path);
        self::assertSame(false, $response['result']['isError'] ?? null);
    }

    public function testNotificationsDoNotReturnResponses(): void
    {
        $server = $this->server();

        self::assertNull($server->handle([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]));
    }

    private function server(?RecordingLocalMcpHttpClient $client = null): LocalMcpServer
    {
        return new LocalMcpServer(
            new LocalMcpToolCatalog(dirname(__DIR__, 2) . '/docs/mcp/tools.json'),
            $client ?? new RecordingLocalMcpHttpClient(new LocalMcpHttpResponse(200, [], '{}')),
            'http://localhost:8080',
        );
    }
}

final class RecordingLocalMcpHttpClient implements LocalMcpHttpClientInterface
{
    public ?string $lastMethod = null;

    public ?string $baseUrl = null;

    public ?string $path = null;

    /** @var array<string, mixed>|null */
    public ?array $body = null;

    public function __construct(
        private readonly LocalMcpHttpResponse $response,
        private readonly bool $authenticated = true,
    ) {
    }

    public function hasAuthentication(): bool
    {
        return $this->authenticated;
    }

    public function get(string $baseUrl, string $path): LocalMcpHttpResponse
    {
        $this->lastMethod = 'get';
        $this->baseUrl = $baseUrl;
        $this->path = $path;

        return $this->response;
    }

    /** @param array<string, mixed> $body */
    public function post(string $baseUrl, string $path, array $body): LocalMcpHttpResponse
    {
        $this->lastMethod = 'post';
        $this->baseUrl = $baseUrl;
        $this->path = $path;
        $this->body = $body;

        return $this->response;
    }

    /** @param array<string, mixed> $body */
    public function put(string $baseUrl, string $path, array $body): LocalMcpHttpResponse
    {
        $this->lastMethod = 'put';
        $this->baseUrl = $baseUrl;
        $this->path = $path;
        $this->body = $body;

        return $this->response;
    }

    public function delete(string $baseUrl, string $path): LocalMcpHttpResponse
    {
        $this->lastMethod = 'delete';
        $this->baseUrl = $baseUrl;
        $this->path = $path;

        return $this->response;
    }
}
