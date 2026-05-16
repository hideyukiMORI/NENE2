# ADR 0007 — PUT vs PATCH Policy for Resource Updates

**Status:** Accepted  
**Date:** 2026-05-17  
**Issue:** [#218](https://github.com/hideyukiMORI/NENE2/issues/218)

---

## Context

Phase 17 added `PUT /examples/notes/{id}`, which performs a **full replacement** of the resource
(all fields must be supplied). Field Trial 2 flagged the absence of a documented policy on whether
NENE2 endpoints should use PUT, PATCH, or both for update operations.

Without a policy, contributors may implement partial updates inconsistently, leading to:
- endpoints that silently ignore omitted fields (incorrect PUT semantics)
- endpoints that require all fields for PATCH (incorrect PATCH semantics)
- no guidance on which verb to add when a new update use case arises

---

## Decision

**Use PUT for full replacement; add PATCH only when partial update semantics are explicitly needed.**

### PUT (full replacement)
- Client must supply all mutable fields.
- Missing fields are treated as absent/null — they do not preserve the previous value.
- Appropriate when the entire resource state is being set in one operation.
- Example: `PUT /examples/notes/{id}` with `{title, body}` required.

### PATCH (partial update)
- Client supplies only the fields to change.
- Omitted fields preserve their current value.
- Use when the client has no reliable way to supply the full current state,
  or when bandwidth or atomicity matters.
- Not a default: add PATCH only when a concrete use case requires it.
- When added, document which fields are patchable in the OpenAPI schema.

### No mixed semantics
- A PUT endpoint must not silently preserve omitted fields.
- A PATCH endpoint must not require all fields.
- If an endpoint has mixed behavior, it should be refactored before shipping.

---

## Consequences

- The current `PUT /examples/notes/{id}` is compliant with this policy (all fields required).
- Future update endpoints default to PUT unless partial semantics are explicitly justified.
- PATCH implementations must use JSON Merge Patch (RFC 7396) or document their partial update
  contract in the OpenAPI spec under `requestBody`.
- OpenAPI schemas for PATCH requests should mark all properties as non-required.
