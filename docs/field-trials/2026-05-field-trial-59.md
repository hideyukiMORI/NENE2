# Field Trial 59 — Audit Log / Change History for Every Mutation (auditlog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/auditlog/`
**NENE2 version**: 1.5.20
**Theme**: Audit log — every PATCH records changed fields (old/new value), who changed it (`X-User-Id` header), and when, into a separate `audit_log` table

## Overview

Built a resource management API to validate the audit log pattern:
- **Resources**: name, status, owner fields; created via `POST /resources`.
- **Audit on PATCH**: every changed field gets its own `audit_log` row — field name, old value, new value, `changed_by` (from `X-User-Id` header or `"anonymous"`), and `changed_at` timestamp.
- **History endpoint**: `GET /resources/{id}/history` returns the full ordered list of audit entries for a resource.
- Unchanged fields (where old == new) are silently skipped — no spurious audit entries.

## Endpoints Implemented

- `POST /resources` — create a resource (name required)
- `PATCH /resources/{id}` — partial update; records audit entries for changed fields only
- `GET /resources/{id}` — fetch current resource state
- `GET /resources/{id}/history` — return all audit log entries for the resource

## Test Results

16 tests, 41 assertions — all pass. PHPStan level 8: no errors. PHP-CS-Fixer: no issues.

---

## Frictions Found

### Friction 1 — `JsonRequestBodyParser` throws 400 for JSON array `[]`, not just empty body (Low)

**Context**: Testing that PATCH with no valid fields returns 422, I initially sent `json_encode([])` = `[]` (a JSON array) as the body. This triggered a 400 from `JsonRequestBodyParser`, not 422 from application logic.

**Root cause**: `JsonRequestBodyParser::parse()` throws `JsonBodyParseException` (→ 400) for any JSON that is not an object. A PHP `[]` encoded to `"[]"` (JSON array) hits this path.

**Fix in test**: Changed to `(object) []` which encodes to `"{}"` (an empty JSON object), which parses successfully and reaches the application validation (→ 422).

**NENE2 status**: The existing hint message in `JsonRequestBodyParser` already says:
> `Hint: in PHP, json_encode([]) produces "[]" (a JSON array). Use json_encode((object)[]) or new stdClass() to produce "{}" (a JSON object).`

The behavior is intentional and documented inline. No NENE2 change needed. Recorded as a learning point for FT authors.

---

## Patterns Validated

### Audit log table schema

```sql
CREATE TABLE IF NOT EXISTS audit_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL,
    field       TEXT NOT NULL,
    old_value   TEXT NOT NULL,
    new_value   TEXT NOT NULL,
    changed_by  TEXT NOT NULL,
    changed_at  TEXT NOT NULL,
    FOREIGN KEY (resource_id) REFERENCES resources (id)
);
```

One row per changed field per PATCH call. Supports multiple changes atomically.

### Repository-level audit diff

```php
public function patch(int $id, array $fields, string $changedBy, string $now): array
{
    $resource = $this->findById($id);
    $mutable  = ['name' => $resource->name, 'status' => $resource->status, 'owner' => $resource->owner];
    $entries  = [];

    foreach ($fields as $field => $newValue) {
        $oldValue = $mutable[$field];
        if ($oldValue === $newValue) { continue; }   // skip no-op changes
        $mutable[$field] = $newValue;
        // INSERT INTO audit_log ...
        $entries[] = new AuditEntry(...);
    }

    if ($entries !== []) {
        // UPDATE resources SET ... WHERE id = ?
    }
    return $entries;
}
```

Only writes to `resources` if at least one field actually changed. No spurious audit entries for unchanged values.

### Anonymous fallback for `X-User-Id`

```php
$changedBy = $request->getHeaderLine('X-User-Id') ?: 'anonymous';
```

Empty or absent header defaults to `"anonymous"`. No auth required — identity is advisory.

---

## NENE2 Changes Required

None. The audit log pattern is a pure application-layer concern. All required primitives (`DatabaseQueryExecutorInterface`, `JsonRequestBodyParser`, `ProblemDetailsResponseFactory`, `Router::PARAMETERS_ATTRIBUTE`) are available in v1.5.20.
