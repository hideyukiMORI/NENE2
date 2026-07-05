<?php

declare(strict_types=1);

namespace Nene2\Audit;

/**
 * Records one mutating operation in the audit trail (ADR 0014).
 *
 * A use case calls {@see record()} after a successful create / update / delete.
 * The recorder completes the event (timestamp, organization) and appends it via
 * an {@see AuditEventRepositoryInterface}.
 *
 * To make the audit row commit atomically with the business mutation, obtain a
 * recorder bound to the transaction's executor from an
 * {@see AuditRecorderFactoryInterface::forExecutor()} inside
 * `DatabaseTransactionManagerInterface::transactional()`.
 *
 * Part of the public API stability guarantee (see ADR 0009 and ADR 0014).
 */
interface AuditRecorderInterface
{
    public function record(AuditEvent $event): void;
}
