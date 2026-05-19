<?php

declare(strict_types=1);

namespace Nene2\Example;

use LogicException;
use Nene2\DependencyInjection\ContainerBuilder;
use Nene2\DependencyInjection\ServiceProviderInterface;
use Nene2\Error\DomainExceptionHandlerInterface;
use Nene2\Example\Note\NoteNotFoundExceptionHandler;
use Nene2\Example\Note\NoteServiceProvider;
use Nene2\Example\Tag\TagNotFoundExceptionHandler;
use Nene2\Example\Tag\TagServiceProvider;
use Psr\Container\ContainerInterface;

/**
 * Registers all built-in NENE2 example services (Note, Tag) and exposes
 * aggregate string-keyed services so framework infrastructure code can wire
 * example routes and exception handlers without importing individual example classes.
 *
 * @internal
 */
final readonly class ExampleServiceProvider implements ServiceProviderInterface
{
    /** Container key for the list of example route registrar callables. */
    public const ROUTE_REGISTRARS = 'nene2.example.route_registrars';

    /** Container key for the list of example domain exception handlers. */
    public const EXCEPTION_HANDLERS = 'nene2.example.exception_handlers';

    public function register(ContainerBuilder $builder): void
    {
        $builder->addProvider(new NoteServiceProvider());
        $builder->addProvider(new TagServiceProvider());

        $builder
            ->set(
                self::ROUTE_REGISTRARS,
                static function (ContainerInterface $container): array {
                    $note = $container->get('nene2.route_registrar.note');
                    $tag  = $container->get('nene2.route_registrar.tag');

                    if (!is_callable($note)) {
                        throw new LogicException('Note route registrar service is invalid.');
                    }

                    if (!is_callable($tag)) {
                        throw new LogicException('Tag route registrar service is invalid.');
                    }

                    return [$note, $tag];
                },
            )
            ->set(
                self::EXCEPTION_HANDLERS,
                static function (ContainerInterface $container): array {
                    $note = $container->get(NoteNotFoundExceptionHandler::class);
                    $tag  = $container->get(TagNotFoundExceptionHandler::class);

                    if (!$note instanceof DomainExceptionHandlerInterface) {
                        throw new LogicException('Note exception handler service is invalid.');
                    }

                    if (!$tag instanceof DomainExceptionHandlerInterface) {
                        throw new LogicException('Tag exception handler service is invalid.');
                    }

                    return [$note, $tag];
                },
            );
    }
}
