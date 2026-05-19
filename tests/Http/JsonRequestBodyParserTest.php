<?php

declare(strict_types=1);

namespace Nene2\Tests\Http;

use Nene2\Http\JsonBodyParseException;
use Nene2\Http\JsonRequestBodyParser;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class JsonRequestBodyParserTest extends TestCase
{
    public function testParsesValidJsonObject(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'https://example.test/')
            ->withBody($factory->createStream('{"title":"Hello","body":"World"}'));

        $result = JsonRequestBodyParser::parse($request);

        self::assertSame(['title' => 'Hello', 'body' => 'World'], $result);
    }

    public function testThrowsOnEmptyBody(): void
    {
        $this->expectException(JsonBodyParseException::class);

        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'https://example.test/')
            ->withBody($factory->createStream(''));

        JsonRequestBodyParser::parse($request);
    }

    public function testThrowsOnInvalidJson(): void
    {
        $this->expectException(JsonBodyParseException::class);

        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'https://example.test/')
            ->withBody($factory->createStream('{not valid json'));

        JsonRequestBodyParser::parse($request);
    }

    public function testThrowsOnJsonString(): void
    {
        $this->expectException(JsonBodyParseException::class);

        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'https://example.test/')
            ->withBody($factory->createStream('"just a string"'));

        JsonRequestBodyParser::parse($request);
    }

    public function testThrowsOnJsonNumber(): void
    {
        $this->expectException(JsonBodyParseException::class);

        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'https://example.test/')
            ->withBody($factory->createStream('42'));

        JsonRequestBodyParser::parse($request);
    }

    public function testThrowsOnJsonNull(): void
    {
        $this->expectException(JsonBodyParseException::class);

        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'https://example.test/')
            ->withBody($factory->createStream('null'));

        JsonRequestBodyParser::parse($request);
    }

    public function testThrowsOnJsonArray(): void
    {
        $this->expectException(JsonBodyParseException::class);

        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'https://example.test/')
            ->withBody($factory->createStream('[1,2,3]'));

        JsonRequestBodyParser::parse($request);
    }

    public function testParsesNestedObjectAsDeepArray(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'https://example.test/')
            ->withBody($factory->createStream('{"user":{"name":"Alice","roles":["admin","editor"]}}'));

        $result = JsonRequestBodyParser::parse($request);

        self::assertSame(['user' => ['name' => 'Alice', 'roles' => ['admin', 'editor']]], $result);
    }
}
