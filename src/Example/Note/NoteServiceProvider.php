<?php

declare(strict_types=1);

namespace Nene2\Example\Note;

use LogicException;
use Nene2\Database\DatabaseQueryExecutorInterface;
use Nene2\DependencyInjection\ContainerBuilder;
use Nene2\DependencyInjection\ServiceProviderInterface;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;

final readonly class NoteServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $builder): void
    {
        $builder
            ->set(
                NoteRepositoryInterface::class,
                static function (ContainerInterface $c): NoteRepositoryInterface {
                    $query = $c->get(DatabaseQueryExecutorInterface::class);

                    if (!$query instanceof DatabaseQueryExecutorInterface) {
                        throw new LogicException('Database query executor service is invalid.');
                    }

                    return new PdoNoteRepository($query);
                },
            )
            ->set(
                GetNoteByIdUseCaseInterface::class,
                static function (ContainerInterface $c): GetNoteByIdUseCaseInterface {
                    $repository = $c->get(NoteRepositoryInterface::class);

                    if (!$repository instanceof NoteRepositoryInterface) {
                        throw new LogicException('Note repository service is invalid.');
                    }

                    return new GetNoteByIdUseCase($repository);
                },
            )
            ->set(
                GetNoteByIdHandler::class,
                static function (ContainerInterface $c): GetNoteByIdHandler {
                    $useCase = $c->get(GetNoteByIdUseCaseInterface::class);
                    $response = $c->get(JsonResponseFactory::class);

                    if (!$useCase instanceof GetNoteByIdUseCaseInterface) {
                        throw new LogicException('GetNoteById use case service is invalid.');
                    }

                    if (!$response instanceof JsonResponseFactory) {
                        throw new LogicException('JSON response factory service is invalid.');
                    }

                    return new GetNoteByIdHandler($useCase, $response);
                },
            )
            ->set(
                CreateNoteUseCaseInterface::class,
                static function (ContainerInterface $c): CreateNoteUseCaseInterface {
                    $repository = $c->get(NoteRepositoryInterface::class);

                    if (!$repository instanceof NoteRepositoryInterface) {
                        throw new LogicException('Note repository service is invalid.');
                    }

                    return new CreateNoteUseCase($repository);
                },
            )
            ->set(
                CreateNoteHandler::class,
                static function (ContainerInterface $c): CreateNoteHandler {
                    $useCase = $c->get(CreateNoteUseCaseInterface::class);
                    $response = $c->get(JsonResponseFactory::class);

                    if (!$useCase instanceof CreateNoteUseCaseInterface) {
                        throw new LogicException('CreateNote use case service is invalid.');
                    }

                    if (!$response instanceof JsonResponseFactory) {
                        throw new LogicException('JSON response factory service is invalid.');
                    }

                    return new CreateNoteHandler($useCase, $response);
                },
            )
            ->set(
                DeleteNoteUseCaseInterface::class,
                static function (ContainerInterface $c): DeleteNoteUseCaseInterface {
                    $repository = $c->get(NoteRepositoryInterface::class);

                    if (!$repository instanceof NoteRepositoryInterface) {
                        throw new LogicException('Note repository service is invalid.');
                    }

                    return new DeleteNoteUseCase($repository);
                },
            )
            ->set(
                DeleteNoteHandler::class,
                static function (ContainerInterface $c): DeleteNoteHandler {
                    $useCase = $c->get(DeleteNoteUseCaseInterface::class);
                    $responseFactory = $c->get(ResponseFactoryInterface::class);

                    if (!$useCase instanceof DeleteNoteUseCaseInterface) {
                        throw new LogicException('DeleteNote use case service is invalid.');
                    }

                    if (!$responseFactory instanceof ResponseFactoryInterface) {
                        throw new LogicException('Response factory service is invalid.');
                    }

                    return new DeleteNoteHandler($useCase, $responseFactory);
                },
            )
            ->set(
                NoteNotFoundExceptionHandler::class,
                static function (ContainerInterface $c): NoteNotFoundExceptionHandler {
                    $problemDetails = $c->get(ProblemDetailsResponseFactory::class);

                    if (!$problemDetails instanceof ProblemDetailsResponseFactory) {
                        throw new LogicException('Problem details response factory service is invalid.');
                    }

                    return new NoteNotFoundExceptionHandler($problemDetails);
                },
            );
    }
}
