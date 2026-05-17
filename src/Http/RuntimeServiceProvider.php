<?php

declare(strict_types=1);

namespace Nene2\Http;

use LogicException;
use Nene2\Auth\BearerTokenMiddleware;
use Nene2\Auth\LocalBearerTokenVerifier;
use Nene2\Config\AppConfig;
use Nene2\Config\ConfigLoader;
use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Database\DatabaseQueryExecutorInterface;
use Nene2\Database\DatabaseTransactionManagerInterface;
use Nene2\Database\PdoConnectionFactory;
use Nene2\Database\PdoDatabaseQueryExecutor;
use Nene2\Database\PdoDatabaseTransactionManager;
use Nene2\DependencyInjection\ContainerBuilder;
use Nene2\DependencyInjection\ServiceProviderInterface;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Example\Note\CreateNoteHandler;
use Nene2\Example\Note\DeleteNoteHandler;
use Nene2\Example\Note\GetNoteByIdHandler;
use Nene2\Example\Note\ListNotesHandler;
use Nene2\Example\Note\NoteNotFoundExceptionHandler;
use Nene2\Example\Note\NoteServiceProvider;
use Nene2\Example\Note\UpdateNoteHandler;
use Nene2\Example\Tag\CreateTagHandler;
use Nene2\Example\Tag\GetTagByIdHandler;
use Nene2\Example\Tag\ListTagsHandler;
use Nene2\Example\Tag\TagNotFoundExceptionHandler;
use Nene2\Example\Tag\TagServiceProvider;
use Nene2\Log\MonologLoggerFactory;
use Nene2\Log\RequestIdHolder;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

final readonly class RuntimeServiceProvider implements ServiceProviderInterface
{
    public const PROJECT_ROOT = 'nene2.project_root';

    public function register(ContainerBuilder $builder): void
    {
        $builder->addProvider(new NoteServiceProvider());
        $builder->addProvider(new TagServiceProvider());

        $builder
            ->set(
                ConfigLoader::class,
                static function (ContainerInterface $container): ConfigLoader {
                    $projectRoot = $container->get(self::PROJECT_ROOT);

                    if (!is_string($projectRoot) || $projectRoot === '') {
                        throw new LogicException('Project root service is invalid.');
                    }

                    return new ConfigLoader($projectRoot);
                },
            )
            ->set(
                AppConfig::class,
                static function (ContainerInterface $container): AppConfig {
                    $loader = $container->get(ConfigLoader::class);

                    if (!$loader instanceof ConfigLoader) {
                        throw new LogicException('Config loader service is invalid.');
                    }

                    return $loader->load();
                },
            )
            ->set(
                DatabaseConnectionFactoryInterface::class,
                static function (ContainerInterface $container): DatabaseConnectionFactoryInterface {
                    $config = $container->get(AppConfig::class);

                    if (!$config instanceof AppConfig) {
                        throw new LogicException('Application config service is invalid.');
                    }

                    return new PdoConnectionFactory($config->database);
                },
            )
            ->set(
                DatabaseQueryExecutorInterface::class,
                static function (ContainerInterface $container): DatabaseQueryExecutorInterface {
                    $connectionFactory = $container->get(DatabaseConnectionFactoryInterface::class);

                    if (!$connectionFactory instanceof DatabaseConnectionFactoryInterface) {
                        throw new LogicException('Database connection factory service is invalid.');
                    }

                    return new PdoDatabaseQueryExecutor($connectionFactory);
                },
            )
            ->set(
                DatabaseTransactionManagerInterface::class,
                static function (ContainerInterface $container): DatabaseTransactionManagerInterface {
                    $connectionFactory = $container->get(DatabaseConnectionFactoryInterface::class);

                    if (!$connectionFactory instanceof DatabaseConnectionFactoryInterface) {
                        throw new LogicException('Database connection factory service is invalid.');
                    }

                    return new PdoDatabaseTransactionManager($connectionFactory);
                },
            )
            ->set(Psr17Factory::class, static fn (ContainerInterface $container): Psr17Factory => new Psr17Factory())
            ->set(
                JsonResponseFactory::class,
                static function (ContainerInterface $container): JsonResponseFactory {
                    $responseFactory = $container->get(ResponseFactoryInterface::class);
                    $streamFactory = $container->get(StreamFactoryInterface::class);

                    if (!$responseFactory instanceof ResponseFactoryInterface) {
                        throw new LogicException('Response factory service is invalid.');
                    }

                    if (!$streamFactory instanceof StreamFactoryInterface) {
                        throw new LogicException('Stream factory service is invalid.');
                    }

                    return new JsonResponseFactory($responseFactory, $streamFactory);
                },
            )
            ->set(
                ProblemDetailsResponseFactory::class,
                static function (ContainerInterface $container): ProblemDetailsResponseFactory {
                    $responseFactory = $container->get(ResponseFactoryInterface::class);
                    $streamFactory = $container->get(StreamFactoryInterface::class);

                    if (!$responseFactory instanceof ResponseFactoryInterface) {
                        throw new LogicException('Response factory service is invalid.');
                    }

                    if (!$streamFactory instanceof StreamFactoryInterface) {
                        throw new LogicException('Stream factory service is invalid.');
                    }

                    return new ProblemDetailsResponseFactory($responseFactory, $streamFactory);
                },
            )
            ->set(
                ResponseFactoryInterface::class,
                static function (ContainerInterface $container): ResponseFactoryInterface {
                    $factory = $container->get(Psr17Factory::class);

                    if (!$factory instanceof ResponseFactoryInterface) {
                        throw new LogicException('PSR-17 response factory service is invalid.');
                    }

                    return $factory;
                },
            )
            ->set(
                StreamFactoryInterface::class,
                static function (ContainerInterface $container): StreamFactoryInterface {
                    $factory = $container->get(Psr17Factory::class);

                    if (!$factory instanceof StreamFactoryInterface) {
                        throw new LogicException('PSR-17 stream factory service is invalid.');
                    }

                    return $factory;
                },
            )
            ->set(RequestIdHolder::class, static fn (ContainerInterface $container): RequestIdHolder => new RequestIdHolder())
            ->set(
                LoggerInterface::class,
                static function (ContainerInterface $container): LoggerInterface {
                    $config = $container->get(AppConfig::class);
                    $debug = $config instanceof AppConfig && $config->debug;
                    $holder = $container->get(RequestIdHolder::class);

                    return (new MonologLoggerFactory())->create('nene2', $debug, $holder instanceof RequestIdHolder ? $holder : null);
                },
            )
            ->set(
                RuntimeApplicationFactory::class,
                static function (ContainerInterface $container): RuntimeApplicationFactory {
                    $responseFactory = $container->get(ResponseFactoryInterface::class);
                    $streamFactory = $container->get(StreamFactoryInterface::class);
                    $logger = $container->get(LoggerInterface::class);
                    $config = $container->get(AppConfig::class);

                    if (!$responseFactory instanceof ResponseFactoryInterface) {
                        throw new LogicException('Response factory service is invalid.');
                    }

                    if (!$streamFactory instanceof StreamFactoryInterface) {
                        throw new LogicException('Stream factory service is invalid.');
                    }

                    if (!$logger instanceof LoggerInterface) {
                        throw new LogicException('Logger service is invalid.');
                    }

                    if (!$config instanceof AppConfig) {
                        throw new LogicException('Application config service is invalid.');
                    }

                    $getNoteByIdHandler = $container->get(GetNoteByIdHandler::class);
                    $createNoteHandler = $container->get(CreateNoteHandler::class);
                    $deleteNoteHandler = $container->get(DeleteNoteHandler::class);
                    $listNotesHandler = $container->get(ListNotesHandler::class);
                    $updateNoteHandler = $container->get(UpdateNoteHandler::class);
                    $noteNotFoundHandler = $container->get(NoteNotFoundExceptionHandler::class);
                    $listTagsHandler = $container->get(ListTagsHandler::class);
                    $getTagByIdHandler = $container->get(GetTagByIdHandler::class);
                    $createTagHandler = $container->get(CreateTagHandler::class);
                    $tagNotFoundHandler = $container->get(TagNotFoundExceptionHandler::class);
                    $requestIdHolder = $container->get(RequestIdHolder::class);

                    if (!$getNoteByIdHandler instanceof GetNoteByIdHandler) {
                        throw new LogicException('GetNoteById handler service is invalid.');
                    }

                    if (!$createNoteHandler instanceof CreateNoteHandler) {
                        throw new LogicException('CreateNote handler service is invalid.');
                    }

                    if (!$deleteNoteHandler instanceof DeleteNoteHandler) {
                        throw new LogicException('DeleteNote handler service is invalid.');
                    }

                    if (!$listNotesHandler instanceof ListNotesHandler) {
                        throw new LogicException('ListNotes handler service is invalid.');
                    }

                    if (!$updateNoteHandler instanceof UpdateNoteHandler) {
                        throw new LogicException('UpdateNote handler service is invalid.');
                    }

                    if (!$noteNotFoundHandler instanceof NoteNotFoundExceptionHandler) {
                        throw new LogicException('NoteNotFoundException handler service is invalid.');
                    }

                    if (!$listTagsHandler instanceof ListTagsHandler) {
                        throw new LogicException('ListTags handler service is invalid.');
                    }

                    if (!$getTagByIdHandler instanceof GetTagByIdHandler) {
                        throw new LogicException('GetTagById handler service is invalid.');
                    }

                    if (!$createTagHandler instanceof CreateTagHandler) {
                        throw new LogicException('CreateTag handler service is invalid.');
                    }

                    if (!$tagNotFoundHandler instanceof TagNotFoundExceptionHandler) {
                        throw new LogicException('TagNotFoundException handler service is invalid.');
                    }

                    if (!$requestIdHolder instanceof RequestIdHolder) {
                        throw new LogicException('RequestIdHolder service is invalid.');
                    }

                    $bearerMiddleware = null;

                    if ($config->localJwtSecret !== null) {
                        $problemDetails = $container->get(ProblemDetailsResponseFactory::class);

                        if (!$problemDetails instanceof ProblemDetailsResponseFactory) {
                            throw new LogicException('ProblemDetailsResponseFactory service is invalid.');
                        }

                        $bearerMiddleware = new BearerTokenMiddleware(
                            $problemDetails,
                            new LocalBearerTokenVerifier($config->localJwtSecret),
                            ['/examples/protected'],
                        );
                    }

                    return new RuntimeApplicationFactory($responseFactory, $streamFactory, $logger, $config->machineApiKey, $getNoteByIdHandler, $createNoteHandler, $deleteNoteHandler, [$noteNotFoundHandler, $tagNotFoundHandler], $listNotesHandler, $updateNoteHandler, $requestIdHolder, [], $listTagsHandler, $getTagByIdHandler, $createTagHandler, $bearerMiddleware);
                },
            )
            ->set(
                RequestHandlerInterface::class,
                static function (ContainerInterface $container): RequestHandlerInterface {
                    $factory = $container->get(RuntimeApplicationFactory::class);

                    if (!$factory instanceof RuntimeApplicationFactory) {
                        throw new LogicException('Runtime application factory service is invalid.');
                    }

                    return $factory->create();
                },
            )
            ->set(ResponseEmitter::class, static fn (ContainerInterface $container): ResponseEmitter => new ResponseEmitter());
    }
}
