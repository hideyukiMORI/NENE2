<?php

declare(strict_types=1);

namespace Nene2\Audit;

use InvalidArgumentException;

/**
 * Maps an {@see AuditEvent} onto a concrete audit table (ADR 0014).
 *
 * This is the seam that lets an existing product point {@see PdoAuditEventRepository}
 * at its **current** table — different table name, column names, id type, and
 * payload shape — **without a re-migration**, so a product can delete its
 * hand-rolled audit code and adopt the framework module in one step and migrate
 * its schema later.
 *
 * The variation axes it absorbs are:
 *
 * - **table name** (`audit_logs` vs `audit_events` vs …);
 * - **column names** for every field (id / action / entity / actor / organization /
 *   timestamp / payload / metadata);
 * - **id type** via {@see $idIsAutoIncrement}: `true` = DB-generated `BIGINT`
 *   (id omitted from INSERT), `false` = caller-supplied ULID string (id written);
 * - **payload shape** via {@see AuditPayloadMode}: canonical before/after columns
 *   vs a single JSON payload column.
 *
 * The **canonical** configuration ({@see canonical()}) is the convergence target:
 * new products should adopt it as-is, and products on a transition config should
 * migrate toward it. The non-canonical knobs exist to buy time, not to be a
 * permanent home (施主要件: clear も canonical に収斂).
 *
 * Part of the public API stability guarantee (see ADR 0009 and ADR 0014).
 */
final readonly class AuditTableConfig
{
    /**
     * @param string      $organizationColumn column holding the tenant id
     * @param ?string     $metadataColumn     column holding metadata JSON, or null if the table has none
     * @param ?string     $beforeColumn       before-snapshot column (required for {@see AuditPayloadMode::BeforeAfter})
     * @param ?string     $afterColumn        after-snapshot column (required for {@see AuditPayloadMode::BeforeAfter})
     * @param ?string     $payloadColumn      single JSON payload column (required for {@see AuditPayloadMode::SinglePayload})
     * @param bool        $idIsAutoIncrement  true = DB-generated numeric id (omitted from INSERT); false = caller-supplied id (written)
     */
    public function __construct(
        public string $table,
        public AuditPayloadMode $mode,
        public string $idColumn = 'id',
        public string $actionColumn = 'action',
        public string $entityTypeColumn = 'entity_type',
        public string $entityIdColumn = 'entity_id',
        public string $actorColumn = 'actor_id',
        public string $organizationColumn = 'organization_id',
        public string $occurredAtColumn = 'occurred_at',
        public ?string $metadataColumn = 'metadata_json',
        public ?string $beforeColumn = 'before_json',
        public ?string $afterColumn = 'after_json',
        public ?string $payloadColumn = null,
        public bool $idIsAutoIncrement = true,
    ) {
        if ($mode === AuditPayloadMode::BeforeAfter) {
            if ($beforeColumn === null || $afterColumn === null) {
                throw new InvalidArgumentException(
                    'AuditPayloadMode::BeforeAfter requires both beforeColumn and afterColumn.',
                );
            }
        } elseif ($payloadColumn === null) {
            throw new InvalidArgumentException(
                'AuditPayloadMode::SinglePayload requires payloadColumn.',
            );
        }
    }

    /**
     * The framework-canonical table shape (the convergence target).
     *
     * Before/after snapshot columns, a `metadata_json` column, an `occurred_at`
     * timestamp, and a DB-generated auto-increment `BIGINT` id. Matches the
     * reference migration `database/migrations/*_create_audit_events_table` and
     * `database/schema/audit_events.sql`.
     *
     * Pass `idIsAutoIncrement: false` on the constructor (not here) if your
     * canonical table uses ULID ids instead.
     */
    public static function canonical(string $table = 'audit_events'): self
    {
        return new self(
            table: $table,
            mode: AuditPayloadMode::BeforeAfter,
            idColumn: 'id',
            actionColumn: 'action',
            entityTypeColumn: 'entity_type',
            entityIdColumn: 'entity_id',
            actorColumn: 'actor_id',
            organizationColumn: 'organization_id',
            occurredAtColumn: 'occurred_at',
            metadataColumn: 'metadata_json',
            beforeColumn: 'before_json',
            afterColumn: 'after_json',
            payloadColumn: null,
            idIsAutoIncrement: true,
        );
    }

    /**
     * Translates a whitelisted logical sort field ({@see AuditQuery::SORT_COLUMNS})
     * to this table's physical column name. The input is always one of the closed
     * set validated by {@see AuditQuery}, so the result is safe to splice into SQL.
     */
    public function physicalSortColumn(string $logicalColumn): string
    {
        return match ($logicalColumn) {
            'occurred_at' => $this->occurredAtColumn,
            'action' => $this->actionColumn,
            'entity_type' => $this->entityTypeColumn,
            default => $this->idColumn,
        };
    }
}
