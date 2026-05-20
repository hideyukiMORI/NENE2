<?php

declare(strict_types=1);

namespace Nene2\Tests\Http;

use Nene2\Http\JsonResponseFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class JsonResponseFactoryTest extends TestCase
{
    private JsonResponseFactory $factory;

    protected function setUp(): void
    {
        $psr17         = new Psr17Factory();
        $this->factory = new JsonResponseFactory($psr17, $psr17);
    }

    public function testCreateReturnsJsonObject(): void
    {
        $response = $this->factory->create(['key' => 'value']);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(['key' => 'value'], $body);
    }

    public function testCreateWithCustomStatus(): void
    {
        $response = $this->factory->create(['id' => 1], 201);
        self::assertSame(201, $response->getStatusCode());
    }

    public function testCreateWithCustomHeaders(): void
    {
        $response = $this->factory->create([], 200, ['X-Custom' => 'yes']);
        self::assertSame('yes', $response->getHeaderLine('X-Custom'));
    }

    public function testCreateListReturnsBareJsonArray(): void
    {
        $items    = [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']];
        $response = $this->factory->createList($items);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertCount(2, $body);
        self::assertSame(1, $body[0]['id']);
    }

    public function testCreateListWithEmptyArray(): void
    {
        $response = $this->factory->createList([]);
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame([], $body);
    }

    public function testCreateListWithCustomStatus(): void
    {
        $response = $this->factory->createList(['item'], 201);
        self::assertSame(201, $response->getStatusCode());
    }

    public function testCreateListWithCustomHeaders(): void
    {
        $response = $this->factory->createList([], 200, ['X-Total-Count' => '0']);
        self::assertSame('0', $response->getHeaderLine('X-Total-Count'));
    }

    public function testCreateEmptyReturns204(): void
    {
        $response = $this->factory->createEmpty();
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }
}
