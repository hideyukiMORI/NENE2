<?php

declare(strict_types=1);

namespace Nene2\Audit;

use Nene2\Database\DatabaseQueryExecutorInterface;

/**
 * Builds an {@see AuditRecorderInterface} bound to a specific query executor.
 *
 * A mutating use case runs inside `DatabaseTransactionManagerInterface::transactional()`,
 * which hands the closure a transaction-scoped {@see DatabaseQueryExecutorInterface}.
 * Passing that executor here yields a recorder whose repository writes through the
 * **same** transaction, so the audit row and the business mutation commit (or roll
 * back) atomically — the invoice/payout good-form pattern, promoted to a framework
 * default (ADR 0014).
 *
 * ```php
 * return $this->tx->transactional(function (DatabaseQueryExecutorInterface $exec) use (...): Entity {
 *     $repo   = ($this->entityRepoFactory)($exec);
 *     $entity = $repo->save(...);
 *     $this->auditFactory->forExecutor($exec)->record(new AuditEvent(...));
 *     return $entity;
 * });
 * ```
 *
 * Part of the public API stability guarantee (see ADR 0009 and ADR 0014).
 */
interface AuditRecorderFactoryInterface
{
    public function forExecutor(DatabaseQueryExecutorInterface $executor): AuditRecorderInterface;
}
