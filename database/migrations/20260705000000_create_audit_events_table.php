<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Canonical reference audit table for the `Nene2\Audit` module (ADR 0014).
 *
 * This is the convergence target new products should adopt as-is, and existing
 * products should migrate toward. Like `src/Example/` and the notes/tags tables,
 * it is a **reference** shape — outside the public API stability guarantee (ADR
 * 0009): a consumer copies and adapts it rather than depending on it verbatim.
 *
 * Canonical choices:
 * - before/after snapshot columns plus a `metadata_json` receptacle;
 * - `occurred_at` timestamp (filled from the injected clock by `AuditRecorder`);
 * - `id` as a DB-generated auto-increment BIGINT.
 *
 * ULID string ids are supported by the module instead — construct
 * `AuditTableConfig` with `idIsAutoIncrement: false` and change `id` here to a
 * `CHAR(26)` primary key.
 *
 * Append-only: application code must never UPDATE or DELETE these rows.
 */
final class CreateAuditEventsTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('audit_events', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false, 'null' => false])
            ->addColumn('action', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('entity_type', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('entity_id', 'string', ['limit' => 64, 'null' => true, 'default' => null])
            ->addColumn('actor_id', 'string', ['limit' => 64, 'null' => true, 'default' => null])
            ->addColumn('organization_id', 'string', ['limit' => 64, 'null' => true, 'default' => null])
            ->addColumn('before_json', 'text', ['null' => true, 'default' => null])
            ->addColumn('after_json', 'text', ['null' => true, 'default' => null])
            ->addColumn('metadata_json', 'text', ['null' => true, 'default' => null])
            ->addColumn('occurred_at', 'datetime', ['null' => false])
            ->addIndex(['organization_id'])
            ->addIndex(['entity_type', 'entity_id'])
            ->addIndex(['action'])
            ->addIndex(['occurred_at'])
            ->create();
    }
}
