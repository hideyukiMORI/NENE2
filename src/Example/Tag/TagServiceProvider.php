<?php

declare(strict_types=1);

namespace Nene2\Example\Tag;

use LogicException;
use Nene2\Database\DatabaseQueryExecutorInterface;
use Nene2\DependencyInjection\ContainerBuilder;
use Nene2\DependencyInjection\ServiceProviderInterface;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Psr\Container\ContainerInterface;

final readonly class TagServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $builder): void
    {
        $builder
            ->set(
                TagRepositoryInterface::class,
                static function (ContainerInterface $c): TagRepositoryInterface {
                    $query = $c->get(DatabaseQueryExecutorInterface::class);

                    if (!$query instanceof DatabaseQueryExecutorInterface) {
                        throw new LogicException('Database query executor service is invalid.');
                    }

                    return new PdoTagRepository($query);
                },
            )
            ->set(
                ListTagsUseCaseInterface::class,
                static function (ContainerInterface $c): ListTagsUseCaseInterface {
                    $repository = $c->get(TagRepositoryInterface::class);

                    if (!$repository instanceof TagRepositoryInterface) {
                        throw new LogicException('Tag repository service is invalid.');
                    }

                    return new ListTagsUseCase($repository);
                },
            )
            ->set(
                ListTagsHandler::class,
                static function (ContainerInterface $c): ListTagsHandler {
                    $useCase = $c->get(ListTagsUseCaseInterface::class);
                    $response = $c->get(JsonResponseFactory::class);

                    if (!$useCase instanceof ListTagsUseCaseInterface) {
                        throw new LogicException('ListTags use case service is invalid.');
                    }

                    if (!$response instanceof JsonResponseFactory) {
                        throw new LogicException('JSON response factory service is invalid.');
                    }

                    return new ListTagsHandler($useCase, $response);
                },
            )
            ->set(
                GetTagByIdUseCaseInterface::class,
                static function (ContainerInterface $c): GetTagByIdUseCaseInterface {
                    $repository = $c->get(TagRepositoryInterface::class);

                    if (!$repository instanceof TagRepositoryInterface) {
                        throw new LogicException('Tag repository service is invalid.');
                    }

                    return new GetTagByIdUseCase($repository);
                },
            )
            ->set(
                GetTagByIdHandler::class,
                static function (ContainerInterface $c): GetTagByIdHandler {
                    $useCase = $c->get(GetTagByIdUseCaseInterface::class);
                    $response = $c->get(JsonResponseFactory::class);

                    if (!$useCase instanceof GetTagByIdUseCaseInterface) {
                        throw new LogicException('GetTagById use case service is invalid.');
                    }

                    if (!$response instanceof JsonResponseFactory) {
                        throw new LogicException('JSON response factory service is invalid.');
                    }

                    return new GetTagByIdHandler($useCase, $response);
                },
            )
            ->set(
                CreateTagUseCaseInterface::class,
                static function (ContainerInterface $c): CreateTagUseCaseInterface {
                    $repository = $c->get(TagRepositoryInterface::class);

                    if (!$repository instanceof TagRepositoryInterface) {
                        throw new LogicException('Tag repository service is invalid.');
                    }

                    return new CreateTagUseCase($repository);
                },
            )
            ->set(
                CreateTagHandler::class,
                static function (ContainerInterface $c): CreateTagHandler {
                    $useCase = $c->get(CreateTagUseCaseInterface::class);
                    $response = $c->get(JsonResponseFactory::class);

                    if (!$useCase instanceof CreateTagUseCaseInterface) {
                        throw new LogicException('CreateTag use case service is invalid.');
                    }

                    if (!$response instanceof JsonResponseFactory) {
                        throw new LogicException('JSON response factory service is invalid.');
                    }

                    return new CreateTagHandler($useCase, $response);
                },
            )
            ->set(
                TagNotFoundExceptionHandler::class,
                static function (ContainerInterface $c): TagNotFoundExceptionHandler {
                    $problemDetails = $c->get(ProblemDetailsResponseFactory::class);

                    if (!$problemDetails instanceof ProblemDetailsResponseFactory) {
                        throw new LogicException('Problem details response factory service is invalid.');
                    }

                    return new TagNotFoundExceptionHandler($problemDetails);
                },
            )
            ->set(
                'nene2.route_registrar.tag',
                static function (ContainerInterface $c): TagRouteRegistrar {
                    $list = $c->get(ListTagsHandler::class);
                    $get = $c->get(GetTagByIdHandler::class);
                    $create = $c->get(CreateTagHandler::class);

                    if (!$list instanceof ListTagsHandler) {
                        throw new LogicException('ListTags handler service is invalid.');
                    }

                    if (!$get instanceof GetTagByIdHandler) {
                        throw new LogicException('GetTagById handler service is invalid.');
                    }

                    if (!$create instanceof CreateTagHandler) {
                        throw new LogicException('CreateTag handler service is invalid.');
                    }

                    return new TagRouteRegistrar($list, $get, $create);
                },
            );
    }
}
