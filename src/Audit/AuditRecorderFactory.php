<?php

declare(strict_types=1);

namespace Nene2\Audit;

use Nene2\Database\DatabaseQueryExecutorInterface;
use Nene2\Http\ClockInterface;
use Nene2\Http\RequestScopedHolder;

/**
 * Default {@see AuditRecorderFactoryInterface} (ADR 0014).
 *
 * Binds a fresh {@see AuditRecorder} to the executor of the enclosing transaction,
 * so the audit row is written through the same {@see DatabaseQueryExecutorInterface}
 * as the business mutation and the two commit or roll back together. This
 * generalises the invoice/payout `recorderFactory` good form into a framework
 * default.
 *
 * Concrete implementation detail — **outside** the public API stability guarantee
 * (ADR 0009). Depend on {@see AuditRecorderFactoryInterface}.
 */
final readonly class AuditRecorderFactory implements AuditRecorderFactoryInterface
{
    /**
     * @param RequestScopedHolder<string|int>|null $organizationHolder tenant id for events that omit it
     */
    public function __construct(
        private ClockInterface $clock,
        private AuditTableConfig $tableConfig,
        private ?RequestScopedHolder $organizationHolder = null,
    ) {
    }

    public function forExecutor(DatabaseQueryExecutorInterface $executor): AuditRecorderInterface
    {
        return new AuditRecorder(
            new PdoAuditEventRepository($executor, $this->tableConfig),
            $this->clock,
            $this->organizationHolder,
        );
    }
}
