<?php

declare(strict_types=1);

namespace Nene2\Audit;

/**
 * One recorded mutating operation in the audit trail.
 *
 * This is the framework-canonical audit record shape (ADR 0014). It is a
 * transport value object: use cases construct it and hand it to an
 * {@see AuditRecorderInterface}; repositories map it to and from storage.
 *
 * Design notes:
 *
 * - **`action` is a free string owned by the product.** NENE2 ships no action
 *   enum — every product defines its own vocabulary (e.g. `invoice.issued`,
 *   `document.uploaded`) and registers it in its own terminology registry. The
 *   framework only guarantees that the string is stored and queryable verbatim.
 * - **Scalar ids are `string|int|null`** so both auto-increment `BIGINT` ids and
 *   ULID string ids are supported without a second type. `null` means "not set"
 *   (e.g. no actor for a system event, or an unresolved `entityId`).
 * - **`before` / `after`** are sanitized snapshots (no secrets). `before` is
 *   null for creates; `after` is null for deletes.
 * - **`metadata`** is the receptacle for cross-cutting context (`source`,
 *   `request_id`, `ip`, …). Products decide the keys; the framework stores it as
 *   JSON.
 * - **`occurredAt`** is an optional caller-supplied instant. When null, the
 *   default {@see AuditRecorder} fills it from the injected {@see \Nene2\Http\ClockInterface}
 *   so timestamps are deterministic in tests and never drift from every other "now".
 *
 * Part of the public API stability guarantee (see ADR 0009 and ADR 0014).
 */
final readonly class AuditEvent
{
    /**
     * @param array<string, mixed>|null $before   sanitized snapshot before the change (null for create)
     * @param array<string, mixed>|null $after    sanitized snapshot after the change (null for delete)
     * @param array<string, mixed>|null $metadata cross-cutting context (source / request_id / ip / …)
     */
    public function __construct(
        public string $action,
        public string $entityType,
        public string|int|null $entityId = null,
        public string|int|null $actorId = null,
        public string|int|null $organizationId = null,
        public ?array $before = null,
        public ?array $after = null,
        public ?array $metadata = null,
        public ?string $occurredAt = null,
        public string|int|null $id = null,
    ) {
    }

    /**
     * Returns a copy of this event with the timestamp and organization filled in.
     *
     * Used by {@see AuditRecorder} to complete an event before appending it,
     * without mutating the caller's value object.
     *
     * @param array<string, mixed>|null $metadata
     */
    public function completed(
        string $occurredAt,
        string|int|null $organizationId,
        ?array $metadata = null,
    ): self {
        return new self(
            action: $this->action,
            entityType: $this->entityType,
            entityId: $this->entityId,
            actorId: $this->actorId,
            organizationId: $organizationId,
            before: $this->before,
            after: $this->after,
            metadata: $metadata ?? $this->metadata,
            occurredAt: $occurredAt,
            id: $this->id,
        );
    }
}
