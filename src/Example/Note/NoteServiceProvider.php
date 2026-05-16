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
                    $problemDetails = $c->get(ProblemDetailsResponseFactory::class);

                    if (!$useCase instanceof GetNoteByIdUseCaseInterface) {
                        throw new LogicException('GetNoteById use case service is invalid.');
                    }

                    if (!$response instanceof JsonResponseFactory) {
                        throw new LogicException('JSON response factory service is invalid.');
                    }

                    if (!$problemDetails instanceof ProblemDetailsResponseFactory) {
                        throw new LogicException('Problem details response factory service is invalid.');
                    }

                    return new GetNoteByIdHandler($useCase, $response, $problemDetails);
                },
            );
    }
}
