# ADR 0014: Audit Log Framework Module (`Nene2\Audit`)

## Status

accepted

## Context

Every NeNe product that records who changed what needs an audit trail. By
mid-2026 at least five products had grown their own, near-identical copy:
`nene-invoice`, `nene-payout`, `nene-profile`, `nene-vault`, and `nene-clear`
each ship an `AuditRecorder` + `PdoAudit*Repository` + record value object
(`_work/reports/2026-07-05/nene-backend-audit.md` §4-1). The shapes converged on
the same idea but diverged in quality:

- **Good shape** (`nene-invoice`, `nene-payout`): a `ClockInterface`-injected
  recorder writing before/after snapshots, plus a `recorderFactory` that binds
  the recorder to the transaction's executor so the audit row and the business
  mutation commit atomically. `nene-payout` additionally uses ULID string ids.
- **Weak shape** (`nene-profile`): the repository calls `date()` itself (time
  drift, untestable) and appends outside the mutation's transaction (an audit row
  can survive a rolled-back change, or vice versa).
- **Divergent record shape**: `nene-clear` stores a single JSON `payload` column
  in an `audit_events` table instead of separate before/after columns; `nene-vault`
  carries a `metadata` receptacle and an action-string enum.

Duplicating a compliance-relevant subsystem five ways is a maintenance and
correctness risk: a fix in one copy is not a fix in the others, and the weak
shape is a silent integrity bug. NENE2 is the right home for one canonical,
tested implementation.

This is not only a de-duplication play. Products that do **not** yet have an
audit trail (and new products still to come) should be able to adopt auditing as
a first-class framework feature rather than re-deriving it — so the module is
designed as a standard adoption path, not merely a lowest-common-denominator of
the existing five.

### Requirements

- One record value object and one recorder/repository contract all products can
  converge on.
- Atomicity: the audit row must commit in the **same** transaction as the
  mutation it describes (promote the invoice/payout good form to the default).
- Deterministic time: the timestamp comes from the injected clock, never a
  repository-local `date()` (fix the profile anti-pattern structurally).
- Support both auto-increment `BIGINT` ids and ULID string ids without forking
  the type.
- Let an existing product point the module at its **current** table (different
  name, columns, id type, single-payload vs before/after) **without a
  re-migration**, so adoption is one step and schema migration is a later step.
- Respect NENE2 boundaries: raw SQL through `DatabaseQueryExecutorInterface`,
  parameterised queries, `JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE`.

## Decision

Add a `Nene2\Audit` module (see the ADR 0009 surface update) with this split
between stable contract and swappable implementation:

**Stable surface** (no breaking change without a major bump):

- `AuditEvent` (`final readonly` VO) — `action`, `entityType`, `entityId`,
  `actorId`, `organizationId`, `before`, `after`, `metadata`, `occurredAt`, `id`.
  Scalar ids are `string|int|null` (int **or** ULID).
- `AuditRecorderInterface::record(AuditEvent): void`.
- `AuditRecorderFactoryInterface::forExecutor(DatabaseQueryExecutorInterface): AuditRecorderInterface`
  — the transaction-atomic seam.
- `AuditEventRepositoryInterface` — **append-only**: `append` / `query` / `count`.
  No update or delete path exists on the contract; the trail is immutable.
- `AuditQuery` (`final readonly` VO) — common filters plus a sort column/direction
  validated against a **closed whitelist in the constructor**, so an invalid or
  injected sort fails at the boundary and never reaches SQL interpolation.
- `AuditPayloadMode` (enum) — `BeforeAfter` (canonical) / `SinglePayload` (transition).
- `AuditTableConfig` — maps an `AuditEvent` onto a concrete table (name, column
  map, id type, payload mode).

**Outside the stability guarantee** (implementation detail — depend on the
interfaces): `AuditRecorder`, `AuditRecorderFactory`, `PdoAuditEventRepository`,
and `AuditServiceProvider` (a copy-and-adapt reference wiring, analogous to
`src/Example/`).

**Reference schema** (also outside the guarantee, like the notes/tags example):
`database/migrations/20260705000000_create_audit_events_table.php` and
`database/schema/audit_events.sql` — before/after columns, `metadata_json`,
`occurred_at`, and an auto-increment `BIGINT` id.

### The action vocabulary is owned by the product, not the framework

`action` is a **free string**. NENE2 ships **no** action enum. Every product owns
its own vocabulary (e.g. `invoice.issued`, `document.uploaded`) and registers it
in its own terminology registry; the framework only guarantees the string is
stored and queried verbatim. Baking an enum into the framework would either
constrain products or bloat with every product's terms.

### The default recorder fixes time and tenant structurally

`AuditRecorder` fills `occurredAt` from the injected `ClockInterface` when the
caller left it null, and fills `organizationId` from an optional
`RequestScopedHolder` when absent. This removes both the profile `date()` drift
and the "forgot to pass the tenant" class of bug. `AuditRecorderFactory.forExecutor()`
builds a recorder whose repository writes through the transaction's executor, so
audit and mutation are one atomic unit.

### Canonical is the convergence target; transition configs are temporary

`AuditTableConfig::canonical()` is the shape new products should adopt as-is. The
non-canonical knobs — `SinglePayload` mode, custom column names, `idIsAutoIncrement:
false` — exist so an existing product (notably `nene-clear`'s single-`payload`
`audit_events`) can adopt the module **without re-migrating first**. They are an
adoption ramp, **not** a permanent home: the stated end state is that every
product — including clear — converges on the canonical before/after schema via a
schema migration, not that config compatibility is maintained indefinitely.

### Schema ownership is the union of the existing shapes (A ∪ B)

The canonical table is the **union** of what the good-form products need (A:
before/after, actor/org/action/entity, ULID-or-int id) and what the divergent
ones carry (B: a `metadata` receptacle, an `occurred_at` name, single-payload
folding). Rather than pick one product's table, the canonical shape carries every
field any product legitimately needs, and `SinglePayload` mode lets a product
whose physical table is narrower still round-trip through the same VO.

### Standard adoption path for new products

A product with no audit trail adopts by: (1) applying the reference migration (or
its own equivalent), (2) registering `AuditTableConfig::canonical()`, (3) wiring
`AuditRecorderFactoryInterface` (copy `AuditServiceProvider`), and (4) calling
`forExecutor($exec)->record(new AuditEvent(...))` inside each mutating use case's
`transactional()`. No per-product recorder/repository code is written.

### Scope (v1)

record + repository + query only. **List routes, CSV export, and actor-email
joins are intentionally not upstreamed** — they are product-specific read
concerns and stay in each product's read layer. Migrating the five existing
products off their hand-rolled code is a **separate PR per product**; this PR adds
the framework surface only.

### Rejected alternatives

| Alternative | Why rejected |
|-------------|--------------|
| **Ship an `AuditAction` enum in the framework** | Action vocabularies are product-owned and unbounded; a framework enum would either constrain products or accrete every product's terms. Free string + per-product registry keeps ownership correct. |
| **Canonical-only, no `AuditTableConfig`/`SinglePayload`** | Forces every existing product to re-migrate before it can delete its duplicate code. The config seam makes adoption a one-step change and defers the migration. |
| **Keep config compatibility forever (no convergence)** | Leaves the fleet permanently forked across two table shapes. Canonical is declared the convergence target so transition configs are a ramp, not a fork. |
| **Upstream list routes / CSV export / actor-email join too** | These differ per product (envelope shape, columns, join tables) and belong to the read layer. Upstreaming them would import product-specific decisions into the framework. |
| **Recorder owns the transaction** | The mutation's use case owns the transaction; the recorder must join it, not open its own. `forExecutor()` binds to the caller's executor instead. |

## Consequences

**Benefits**

- One tested, atomic, clock-driven audit implementation replaces five divergent
  copies; the profile drift/non-atomicity bug cannot recur under the default.
- New products adopt auditing as a first-class feature with no bespoke code.
- Existing products can adopt without a re-migration (config seam) and converge on
  the canonical schema later.
- The append-only contract and whitelisted sort make the compliance and
  injection properties structural, not per-product discipline.

**Costs / follow-up**

- Five products must migrate to the module and delete their own audit code — one
  follow-up PR each (out of scope here).
- `nene-clear` additionally needs a schema migration to reach canonical (its
  `SinglePayload` config is a transition state, not the end state).
- The reference migration/schema and `AuditServiceProvider` are reference code
  (outside the stability guarantee); consumers copy and adapt them.

## Related

- Issue: `#1494`
- See also: ADR 0009 (public API scope — updated with the `Nene2\Audit` row),
  ADR 0012 (sanctioned test DB wiring, used by the module's tests), `CHANGELOG.md`
- Audit source: `_work/reports/2026-07-05/nene-backend-audit.md` §4-1
- Reference implementations: `nene-invoice/src/Audit/` and `nene-payout/src/Audit/`
  (good form — clock injection, before/after, transaction-atomic recorder factory,
  ULID ids); `nene-profile/src/Audit/PdoAuditLogRepository.php` (the `date()` /
  non-atomic anti-pattern this module fixes); `nene-vault/src/Audit/AuditAction.php`
  (product-owned action enum); `nene-clear/src/Audit/AuditEvent.php` (single-payload
  variant that `SinglePayload` mode accommodates)
- Supersedes: none
- Superseded by: none
