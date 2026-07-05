<?php

declare(strict_types=1);

namespace Nene2\Audit;

/**
 * How an audit table stores the mutation payload (ADR 0014).
 *
 * The **canonical** model is {@see BeforeAfter}: separate `before` / `after`
 * snapshot columns plus an optional `metadata` column. {@see SinglePayload} is a
 * transition accommodation for products (e.g. NeNe Clear) whose existing table
 * has one JSON `payload` column — the repository folds `before` / `after` /
 * `metadata` into a single object on write and unfolds them on read, so those
 * products can adopt {@see AuditEvent} **without re-migrating** first.
 *
 * `SinglePayload` exists to smooth adoption, not as a permanent shape: the
 * convergence target for every product is the canonical before/after schema.
 *
 * Part of the public API stability guarantee (see ADR 0009 and ADR 0014).
 */
enum AuditPayloadMode
{
    /** Separate `before_json` / `after_json` (+ optional `metadata_json`) columns. Canonical. */
    case BeforeAfter;

    /** One JSON column holding `{before, after, metadata}`. Transition only. */
    case SinglePayload;
}
