---
title: "How-To: Service Status Page API"
category: product
tags: [status-page, incident-management, admin-auth, state-machine]
difficulty: intermediate
related: [state-machine-audit-log, content-approval-workflow]
---

# How-To: Service Status Page API

> **NENE2 Field Trial 185** — Component health tracking, incident lifecycle management,
> admin key protection with `V::secret()` + `hash_equals()`.

---

## What This Trial Proves

A service status page API needs:
1. **Component status tracking** — operational / degraded / partial_outage / major_outage
2. **Incident lifecycle** — investigating → identified → monitoring → resolved
3. **Immutability guard** — resolved incidents cannot be updated (prevent reopening)
4. **Admin key protection** — `V::secret()` enforces constant-time comparison for write operations
5. **Status enum enforcement** — `V::enum()` allowlist prevents unknown value injection

---

## API

| Method | Path | Auth | Description |
|---|---|---|---|
| `GET` | `/components` | — | List all components (public) |
| `POST` | `/components` | X-Admin-Key | Create a component |
| `PATCH` | `/components/{id}` | X-Admin-Key | Update component status |
| `GET` | `/incidents` | — | List incidents (public, `?open=1` for active) |
| `GET` | `/incidents/{id}` | — | Incident detail with update timeline |
| `POST` | `/incidents` | X-Admin-Key | Create an incident |
| `PATCH` | `/incidents/{id}` | X-Admin-Key | Update incident status |
| `POST` | `/incidents/{id}/updates` | X-Admin-Key | Add update message |

---

## Core Pattern: Admin Key Auth with `V::secret()`

```php
// V::secret() checks: $expected !== '' && hash_equals($expected, $actual)
private function requireAdmin(ServerRequestInterface $request): bool
{
    return V::secret($this->adminKey, $request->getHeaderLine('X-Admin-Key'));
}

// Usage in every write handler:
if (!$this->requireAdmin($request)) {
    return $this->responseFactory->create(['error' => 'X-Admin-Key is required.'], 401);
}
```

**Why `V::secret()` not `=== $key`:**
- `===` is short-circuit: timing varies with match length → timing oracle
- `hash_equals()` is constant-time regardless of where strings differ
- The `$expected !== ''` guard prevents accidentally accepting empty keys

---

## Status Enum Enforcement with `V::enum()`

```php
// V::enum(mixed $raw, string $enumClass): ?\BackedEnum
// Passes class name — returns typed enum instance or null

$statusEnum = V::enum($body['status'] ?? null, ComponentStatus::class);

if (!$statusEnum instanceof ComponentStatus) {
    return $this->responseFactory->create(
        ['error' => 'status must be one of: ' . implode(', ', ComponentStatus::values()) . '.'],
        422,
    );
}

// $statusEnum is already the correct typed enum — no ::from() needed
$component = $this->repository->updateComponentStatus($id, $statusEnum);
```

**Why enum enforcement matters:**
- Without it, arbitrary strings reach the DB
- SQL `ORDER BY status` injection vectors are blocked
- The allowlist is the enum's own cases — always in sync

---

## Incident Lifecycle & Transition Guard

```php
enum IncidentStatus: string
{
    case Investigating = 'investigating';
    case Identified    = 'identified';
    case Monitoring    = 'monitoring';
    case Resolved      = 'resolved';

    public function isResolved(): bool
    {
        return $this === self::Resolved;
    }
}
```

**Transition guard in every write handler:**
```php
$incident = $this->repository->findIncidentById($id);

// Resolved incidents are immutable — prevent accidental reopening
if ($incident->status->isResolved()) {
    return $this->responseFactory->create(
        ['error' => 'Resolved incidents cannot be updated.'],
        409,
    );
}
```

**Why 409 (Conflict) not 422 (Unprocessable):**
- The request is syntactically valid
- The conflict is with the resource's current state
- 409 communicates "valid request, wrong timing"

---

## Component Status Values

```php
enum ComponentStatus: string
{
    case Operational   = 'operational';    // all systems go
    case Degraded      = 'degraded';       // reduced performance
    case PartialOutage = 'partial_outage'; // some features unavailable
    case MajorOutage   = 'major_outage';   // complete service failure
}
```

---

## Automatic `resolved_at` Timestamp

```php
public function updateIncidentStatus(int $id, IncidentStatus $status): ?Incident
{
    $now        = $this->now();
    $resolvedAt = $status->isResolved() ? $now : null;

    $stmt = $this->pdo->prepare(
        'UPDATE incidents SET status = :status, resolved_at = :resolved_at, updated_at = :now WHERE id = :id'
    );
    $stmt->execute(['status' => $status->value, 'resolved_at' => $resolvedAt, ...]);
}
```

The `resolved_at` timestamp is server-set — never from the request body.

---

## Integer ID Parsing (No Injection)

```php
private function parseId(ServerRequestInterface $request, string $param): ?int
{
    $raw = Router::param($request, $param);

    // ctype_digit: rejects negatives, floats, strings, path traversal
    if ($raw === null || !ctype_digit($raw)) {
        return null;
    }

    $id = (int) $raw;

    return $id > 0 ? $id : null; // also rejects zero
}
```

---

## Open Incident Filter

```php
// ?open=1 filters out resolved incidents
$openOnly = isset($params['open']) && $params['open'] === '1';

if ($openOnly) {
    $stmt = $pdo->prepare(
        "SELECT * FROM incidents WHERE status != 'resolved' ORDER BY created_at DESC"
    );
} else {
    $stmt = $pdo->query('SELECT * FROM incidents ORDER BY created_at DESC');
}
```

---

## Full Incident Lifecycle Example

```
POST /incidents          → 201 {status: "investigating", impact: "major"}
POST /incidents/1/updates → 201 {message: "Root cause identified."}
PATCH /incidents/1       → 200 {status: "identified"}
PATCH /incidents/1       → 200 {status: "monitoring"}
PATCH /incidents/1       → 200 {status: "resolved", resolved_at: "2026-05-26T..."}
PATCH /incidents/1       → 409 Resolved incidents cannot be updated.
GET /incidents?open=1    → 200 {count: 0}  — resolved no longer shown
```

---

## Test Results

```
46 tests / 93 assertions — all PASS
PHPStan level 8 — no errors
PHP CS Fixer — clean
```

---

## Key Takeaways

| Pattern | Rule |
|---|---|
| Admin key auth | `V::secret()` — constant-time `hash_equals()`, guards empty key |
| Enum validation | `V::enum($raw, EnumClass::class)` — returns typed enum or null |
| Transition guard | Check current state before applying change — 409 for resolved |
| `resolved_at` | Server-set timestamp, never from request body |
| Integer IDs | `ctype_digit()` + `> 0` guard — rejects strings, negatives, zero |
| Public read | No auth for GET endpoints — status pages are meant to be public |
| Immutable history | Incident updates are append-only — no edit/delete |

Full example: [`../NENE2-FT/statuslog/`](https://github.com/hideyukiMORI/NENE2-examples) in the examples repository.
