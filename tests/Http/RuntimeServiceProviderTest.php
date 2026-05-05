<?php

declare(strict_types=1);

namespace Nene2\Tests\Http;

use Nene2\Http\ResponseEmitter;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Http\RuntimeContainerFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

final class RuntimeServiceProviderTest extends TestCase
{
    public function testRuntimeContainerRegistersHttpServices(): void
    {
        $container = (new RuntimeContainerFactory())->create();

        self::assertInstanceOf(Psr17Factory::class, $container->get(Psr17Factory::class));
        self::assertInstanceOf(ResponseFactoryInterface::class, $container->get(ResponseFactoryInterface::class));
        self::assertInstanceOf(StreamFactoryInterface::class, $container->get(StreamFactoryInterface::class));
        self::assertInstanceOf(LoggerInterface::class, $container->get(LoggerInterface::class));
        self::assertInstanceOf(RuntimeApplicationFactory::class, $container->get(RuntimeApplicationFactory::class));
        self::assertInstanceOf(RequestHandlerInterface::class, $container->get(RequestHandlerInterface::class));
        self::assertInstanceOf(ResponseEmitter::class, $container->get(ResponseEmitter::class));
    }

    public function testRuntimeRequestHandlerServesHealthEndpoint(): void
    {
        $container = (new RuntimeContainerFactory())->create();
        $factory = $container->get(Psr17Factory::class);
        $application = $container->get(RequestHandlerInterface::class);

        self::assertInstanceOf(Psr17Factory::class, $factory);
        self::assertInstanceOf(RequestHandlerInterface::class, $application);

        $response = $application->handle($factory->createServerRequest('GET', 'https://example.test/health'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }
}
