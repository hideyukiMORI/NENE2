<?php

declare(strict_types=1);

namespace Nene2\Tests\Http;

use Nene2\Config\AppConfig;
use Nene2\Config\ConfigLoader;
use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Database\DatabaseQueryExecutorInterface;
use Nene2\Database\DatabaseTransactionManagerInterface;
use Nene2\Http\ResponseEmitter;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Http\RuntimeContainerFactory;
use Nene2\Http\RuntimeServiceProvider;
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
        self::assertSame(dirname(__DIR__, 2), $container->get(RuntimeServiceProvider::PROJECT_ROOT));
        self::assertInstanceOf(ConfigLoader::class, $container->get(ConfigLoader::class));
        self::assertInstanceOf(AppConfig::class, $container->get(AppConfig::class));
        self::assertInstanceOf(
            DatabaseConnectionFactoryInterface::class,
            $container->get(DatabaseConnectionFactoryInterface::class),
        );
        self::assertInstanceOf(
            DatabaseQueryExecutorInterface::class,
            $container->get(DatabaseQueryExecutorInterface::class),
        );
        self::assertInstanceOf(
            DatabaseTransactionManagerInterface::class,
            $container->get(DatabaseTransactionManagerInterface::class),
        );
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

    public function testRuntimeContainerUsesExplicitProjectRootForConfig(): void
    {
        $container = (new RuntimeContainerFactory($this->emptyProjectRoot()))->create();
        $config = $container->get(AppConfig::class);

        self::assertInstanceOf(AppConfig::class, $config);
        self::assertSame('NENE2', $config->name);
        self::assertSame('nene2', $config->database->name);
    }

    private function emptyProjectRoot(): string
    {
        return sys_get_temp_dir();
    }
}
