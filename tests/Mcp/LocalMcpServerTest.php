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
        self::assertSame(false, $response['result']['isError'] ?? null);
        self::assertSame('request-123', $response['result']['structuredContent']['requestId'] ?? null);
        self::assertSame('ok', $response['result']['structuredContent']['body']['status'] ?? null);
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
    public ?string $baseUrl = null;

    public ?string $path = null;

    public function __construct(
        private readonly LocalMcpHttpResponse $response,
    ) {
    }

    public function get(string $baseUrl, string $path): LocalMcpHttpResponse
    {
        $this->baseUrl = $baseUrl;
        $this->path = $path;

        return $this->response;
    }
}
