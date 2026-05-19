<?php

declare(strict_types=1);

namespace Nene2\Tests\Mcp;

use Nene2\Mcp\LocalMcpException;
use Nene2\Mcp\NativeLocalMcpHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NativeLocalMcpHttpClient covering URL construction and header parsing
 * without making real network calls. Real HTTP integration is verified via the
 * local-mcp-server smoke test (manual / CI with Docker Compose).
 */
final class NativeLocalMcpHttpClientTest extends TestCase
{
    public function testHasAuthenticationReturnsTrueWhenTokenProvided(): void
    {
        $client = new NativeLocalMcpHttpClient('my-token');

        self::assertTrue($client->hasAuthentication());
    }

    public function testHasAuthenticationReturnsFalseWhenNoToken(): void
    {
        $client = new NativeLocalMcpHttpClient(null);

        self::assertFalse($client->hasAuthentication());
    }

    public function testHasAuthenticationReturnsFalseForDefaultConstruction(): void
    {
        $client = new NativeLocalMcpHttpClient();

        self::assertFalse($client->hasAuthentication());
    }

    public function testGetThrowsLocalMcpExceptionOnUnreachableUrl(): void
    {
        $client = new NativeLocalMcpHttpClient();

        $this->expectException(LocalMcpException::class);
        $this->expectExceptionMessage('Local API request failed');

        $client->get('http://127.0.0.1:19999', '/health');
    }

    public function testPostThrowsLocalMcpExceptionOnUnreachableUrl(): void
    {
        $client = new NativeLocalMcpHttpClient();

        $this->expectException(LocalMcpException::class);

        $client->post('http://127.0.0.1:19999', '/notes', ['title' => 'test']);
    }

    public function testPutThrowsLocalMcpExceptionOnUnreachableUrl(): void
    {
        $client = new NativeLocalMcpHttpClient();

        $this->expectException(LocalMcpException::class);

        $client->put('http://127.0.0.1:19999', '/notes/1', ['title' => 'updated']);
    }

    public function testPatchThrowsLocalMcpExceptionOnUnreachableUrl(): void
    {
        $client = new NativeLocalMcpHttpClient();

        $this->expectException(LocalMcpException::class);

        $client->patch('http://127.0.0.1:19999', '/notes/1', ['title' => 'patched']);
    }

    public function testDeleteThrowsLocalMcpExceptionOnUnreachableUrl(): void
    {
        $client = new NativeLocalMcpHttpClient();

        $this->expectException(LocalMcpException::class);

        $client->delete('http://127.0.0.1:19999', '/notes/1');
    }
}
