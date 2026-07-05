<?php

declare(strict_types=1);

namespace Nene2\Audit;

use LogicException;
use Nene2\Database\DatabaseQueryExecutorInterface;
use Nene2\DependencyInjection\ContainerBuilder;
use Nene2\DependencyInjection\ServiceProviderInterface;
use Nene2\Http\ClockInterface;
use Psr\Container\ContainerInterface;

/**
 * Reference wiring for the audit module — copy and adapt, do not depend on.
 *
 * This provider shows the consumption types: given a product-registered
 * {@see AuditTableConfig} plus the framework's {@see ClockInterface} and
 * {@see DatabaseQueryExecutorInterface}, it wires the append-only
 * {@see AuditEventRepositoryInterface}, the transaction-atomic
 * {@see AuditRecorderFactoryInterface} (for mutating use cases inside
 * `transactional()`), and a default non-transactional {@see AuditRecorderInterface}
 * (for read-side or already-in-transaction callers).
 *
 * A product that scopes audit reads/writes to a tenant registers its own
 * `RequestScopedHolder<string|int>` and passes it as the third argument of
 * {@see AuditRecorder} / {@see AuditRecorderFactory} — omitted here to keep the
 * sample framework-generic (organizationId then comes only from the event).
 *
 * **Reference implementation — outside the public API stability guarantee**
 * (analogous to `src/Example/`, ADR 0009). Its shape may change without a major
 * version bump; the stable surface is the interfaces, VOs, and {@see AuditTableConfig}.
 *
 * @internal
 */
final readonly class AuditServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $builder): void
    {
        $builder
            ->set(
                AuditEventRepositoryInterface::class,
                static function (ContainerInterface $container): AuditEventRepositoryInterface {
                    return new PdoAuditEventRepository(
                        self::executor($container),
                        self::tableConfig($container),
                    );
                },
            )
            ->set(
                AuditRecorderFactoryInterface::class,
                static function (ContainerInterface $container): AuditRecorderFactoryInterface {
                    return new AuditRecorderFactory(
                        self::clock($container),
                        self::tableConfig($container),
                    );
                },
            )
            ->set(
                AuditRecorderInterface::class,
                static function (ContainerInterface $container): AuditRecorderInterface {
                    $repository = $container->get(AuditEventRepositoryInterface::class);

                    if (!$repository instanceof AuditEventRepositoryInterface) {
                        throw new LogicException('Audit event repository service is invalid.');
                    }

                    return new AuditRecorder($repository, self::clock($container));
                },
            );
    }

    private static function executor(ContainerInterface $container): DatabaseQueryExecutorInterface
    {
        $executor = $container->get(DatabaseQueryExecutorInterface::class);

        if (!$executor instanceof DatabaseQueryExecutorInterface) {
            throw new LogicException('Database query executor service is invalid.');
        }

        return $executor;
    }

    private static function clock(ContainerInterface $container): ClockInterface
    {
        $clock = $container->get(ClockInterface::class);

        if (!$clock instanceof ClockInterface) {
            throw new LogicException('Clock service is invalid.');
        }

        return $clock;
    }

    private static function tableConfig(ContainerInterface $container): AuditTableConfig
    {
        $config = $container->get(AuditTableConfig::class);

        if (!$config instanceof AuditTableConfig) {
            throw new LogicException('Audit table config service is invalid.');
        }

        return $config;
    }
}
