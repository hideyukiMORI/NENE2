# HOWTO: Audit Trail — Recording Who Changed What

> **FT reference**: FT268 (`NENE2-FT/auditlog`) — append-only audit trail: JWT actor extraction, before/after payload snapshots, immutable audit table, unauthenticated audit read gap
>
> **ATK assessment**: ATK-01 through ATK-12 included at the end of this document.

This guide shows how to implement an append-only audit trail in a NENE2 application.
An audit trail records every create, update, and delete operation with the actor (from JWT claims),
the resource, and a payload snapshot. These records are immutable: the API never exposes UPDATE or DELETE
endpoints for the audit table.

---

## Database schema

```sql
-- No FK on actor_id or resource_id:
-- audit records must survive the deletion of the subjects they describe.
CREATE TABLE IF NOT EXISTS audit_log (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id      INTEGER NOT NULL,
    action        TEXT    NOT NULL,   -- 'created' | 'updated' | 'deleted'
    resource_type TEXT    NOT NULL,   -- e.g. 'task', 'order', 'user'
    resource_id   INTEGER NOT NULL,
    occurred_at   TEXT    NOT NULL,
    payload       TEXT    NOT NULL DEFAULT '{}'
);

-- Add indexes for the most common query patterns
CREATE INDEX idx_audit_log_actor_id ON audit_log(actor_id);
CREATE INDEX idx_audit_log_resource ON audit_log(resource_type, resource_id);
```

Key design choices:
- **No FK constraints** — audit records outlive their subjects. If a task is deleted, its audit history must remain.
- **Immutable by design** — never add UPDATE or DELETE SQL paths for this table.
- **`action` as a typed verb** — use past-tense verbs (`created`, `updated`, `deleted`) to make log entries self-describing.

---

## AuditEntry DTO and AuditRepository

```php
final readonly class AuditEntry
{
    public function __construct(
        public int    $id,
        public int    $actorId,
        public string $action,
        public string $resourceType,
        public int    $resourceId,
        public string $occurredAt,
        public string $payload,
    ) {}
}
```

```php
final readonly class AuditRepository
{
    public function __construct(
        private DatabaseQueryExecutorInterface $executor,
    ) {}

    /** @param array<string, mixed> $payload */
    public function record(
        int    $actorId,
        string $action,
        string $resourceType,
        int    $resourceId,
        array  $payload,
    ): AuditEntry {
        $now         = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->executor->execute(
            'INSERT INTO audit_log (actor_id, action, resource_type, resource_id, occurred_at, payload)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$actorId, $action, $resourceType, $resourceId, $now, $payloadJson],
        );

        return $this->findById((int) $this->executor->lastInsertId())
            ?? throw new \RuntimeException('Failed to record audit entry.');
    }

    /** @return list<AuditEntry> */
    public function findByResource(string $resourceType, int $resourceId, int $limit = 50): array
    {
        $rows = $this->executor->fetchAll(
            // ORDER BY id DESC, not occurred_at DESC: second-precision timestamps collide
            // when two operations happen in the same second.
            'SELECT * FROM audit_log
             WHERE resource_type = ? AND resource_id = ?
             ORDER BY id DESC LIMIT ?',
            [$resourceType, $resourceId, $limit],
        );
        return array_map(fn (array $row) => $this->hydrate($row), $rows);
    }
}
```

> **`ORDER BY id DESC` not `occurred_at DESC`:** `occurred_at` is second-precision.
> Two operations in the same second get identical timestamps, making the sort order unpredictable.
> The auto-increment `id` preserves insertion order reliably.

---

## Recording audits in the handler

Record audit events in the handler (UseCase equivalent), not in the Repository.
Recording in the Repository loses the business context ("what operation triggered this?").

### Create — record the initial snapshot

```php
$task = $this->tasks->create($title, $body, $actorId);

// Audit: do NOT include actor_id in payload — it is already in the audit record itself.
$this->audit->record($actorId, 'created', 'task', $task->id, [
    'title'  => $task->title,
    'body'   => $task->body,
    'status' => $task->status,
]);
```

### Update — record before/after for diff visibility

```php
$before = $this->tasks->findById($id);
// ... ownership check, validation ...
$after  = $this->tasks->update($id, $title, $body, $status);

$this->audit->record($actorId, 'updated', 'task', $id, [
    'before' => ['title' => $before->title, 'body' => $before->body, 'status' => $before->status],
    'after'  => ['title' => $after->title,  'body' => $after->body,  'status' => $after->status],
]);
```

### Delete — snapshot before deletion

```php
$task = $this->tasks->findById($id);
// ... ownership check ...
$this->tasks->delete($id);

// Record AFTER deletion — the task row is gone, but the audit lives on.
$this->audit->record($actorId, 'deleted', 'task', $id, [
    'title'  => $task->title,
    'status' => $task->status,
]);
```

---

## Actor from JWT claims

Always derive the actor from the verified JWT, never from the request body.

```php
private function actorId(ServerRequestInterface $request): ?int
{
    /** @var array<string, mixed>|null $claims */
    $claims = $request->getAttribute('nene2.auth.claims');

    if (!is_array($claims) || !isset($claims['sub']) || !is_int($claims['sub'])) {
        return null;
    }

    return $claims['sub'];
}
```

`nene2.auth.claims` is set by `BearerTokenMiddleware` after validating the token.
A client cannot supply a fake `actor_id` in the request body and have it recorded.

---

## Sensitive field exclusion

**Never put passwords, tokens, or internal IDs in the payload.**

```php
// ❌ Leaks sensitive data and is redundant
$this->audit->record($actorId, 'created', 'user', $user->id, [
    'email'         => $user->email,
    'password_hash' => $user->passwordHash,  // NEVER include
    'actor_id'      => $actorId,              // redundant
]);

// ✅ Only business-visible attributes
$this->audit->record($actorId, 'created', 'user', $user->id, [
    'email' => $user->email,
    'role'  => $user->role,
]);
```

---

## Immutable audit API — no write endpoints

```php
public function register(Router $router): void
{
    $router->get('/audit', $this->list(...));
    $router->get('/audit/{resource_type}/{resource_id}', $this->byResource(...));
    // POST, PUT, DELETE are intentionally absent
}
```

---

## Ownership check before every write (and before audit)

```php
$task = $this->tasks->findById($id);
if ($task === null) {
    return $this->problems->create($request, 'not-found', 'Task not found.', 404);
}

// Return 404 instead of 403 to avoid confirming the resource exists to unauthorized actors.
if ($task->actorId !== $actorId) {
    return $this->problems->create($request, 'not-found', 'Task not found.', 404);
}

// Only now: modify + audit
```

---

## Query the audit log

```php
// History for a specific resource
GET /audit/task/42

// All events by actor
GET /audit?actor_id=7

// All deletes across resource types
GET /audit?action=deleted

// Paginates safely
GET /audit?limit=20&offset=40
```

---

## Security considerations

| Risk | Mitigation |
|---|---|
| Audit log deletion | No DELETE endpoint. Table-level: deny DELETE permission to the app DB user if possible |
| Actor spoofing | Actor is always from `nene2.auth.claims`, never from the request body |
| Sensitive payload | Exclude passwords, tokens, internal keys from payload explicitly |
| IDOR (cross-user audit reads) | Restrict `GET /audit` to admin roles (combine with RBAC); or filter by actor_id of the requester |
| Timing attack / user enumeration | Use a real pre-computed Argon2id hash as the dummy, not a malformed string |
| `LIMIT -1` DoS | Clamp: `max(1, min((int) $limit, 100))` |

---

## Dummy hash must be a real Argon2id hash

A malformed dummy hash causes `password_verify()` to return `false` immediately (without running the KDF),
creating a ~20,000× timing difference that lets an attacker enumerate valid email addresses.

```php
// ❌ Malformed — KDF is skipped, returns false in ~0.001ms
$dummyHash = '$argon2id$v=19$m=65536,t=4,p=1$dummysaltdummysaltdummysalt$dummyhashvaluedummyhashvaluedummyh';

// ✅ Real pre-computed hash — KDF runs at full cost (~180ms)
// Generate once: password_hash('dummy-constant-value', PASSWORD_ARGON2ID)
$dummyHash = '$argon2id$v=19$m=65536,t=4,p=1$VkZVLkx3L3FPaVA5NndVSA$vwBHHeAqq1DpGTf7G55ZPAUad+CGLvEJle2m5NA8ulA';
```

> This dummy hash pattern was first documented in [password-hashing.md](password-hashing.md).
> **The same principle applies everywhere `password_verify()` is called on a potentially missing user.**

---

## ATK Assessment (FT268)

Cracker-mindset attack test against `NENE2-FT/auditlog`. The surface: JWT-authenticated task CRUD + unauthenticated audit log read.

### ATK-01 — JWT None Algorithm Attack 🚫 BLOCKED

**Attack**: Forge a JWT with `"alg":"none"` and no signature, arbitrary `sub` claim.
```
Header: {"alg":"none","typ":"JWT"}
Payload: {"sub":1,"email":"admin@x.com","iat":9999999999,"exp":9999999999}
Signature: (empty)
```
**Result**: `LocalBearerTokenVerifier` validates using HMAC-HS256 against the configured secret. Tokens without a valid signature are rejected — `alg:none` is not accepted. → **401 Unauthorized**

---

### ATK-02 — JWT Signature Tampering 🚫 BLOCKED

**Attack**: Take a valid JWT, modify the `sub` field to another user's ID (e.g., `1` → `2`), re-encode without re-signing.
**Result**: HMAC-HS256 signature no longer matches the modified payload. `LocalBearerTokenVerifier` rejects the token. → **401 Unauthorized**

---

### ATK-03 — JWT Expired Token Replay 🚫 BLOCKED

**Attack**: Replay a captured JWT after its `exp` timestamp has passed.
**Result**: `BearerTokenMiddleware` / `LocalBearerTokenVerifier` checks `exp`. Past-expiry tokens are rejected. → **401 Unauthorized**

---

### ATK-04 — IDOR: Access Another User's Task via ID ✅ BLOCKED

**Attack**: Authenticate as User A (sub=1), then call `PUT /tasks/3` where task 3 belongs to User B (sub=2).
**Result**: Task route handler reads `task->actorId` and compares against `actorId` from JWT claims. Mismatch returns → **404 Not Found** (resource existence not confirmed to the attacker).

---

### ATK-05 — IDOR: Delete Another User's Task ✅ BLOCKED

**Attack**: Authenticate as User A, call `DELETE /tasks/7` where task 7 belongs to User B.
**Result**: Same ownership guard as ATK-04. `task->actorId !== $actorId` → **404 Not Found**.

---

### ATK-06 — Actor ID Injection via Request Body ✅ BLOCKED

**Attack**: `POST /tasks` with body `{"title":"Injected","actor_id":999}`.
**Result**: The controller ignores `body['actor_id']` entirely. The audit record uses `actorId` from `nene2.auth.claims['sub']` (JWT). The task is created under the authenticated actor — `actor_id:999` has no effect.

---

### ATK-07 — Unauthenticated Audit Log Read ⚠️ EXPOSED

**Attack**: `GET /audit` with no Authorization header.
**Result**: The audit log read endpoints (`GET /audit`, `GET /audit/{type}/{id}`) are **not protected by `BearerTokenMiddleware`**. The middleware excludes only `/auth/login`; however, the audit route registrar attaches routes without requiring auth. Any unauthenticated caller can read the full audit history of all actors and all resources.

**Impact**: Full disclosure of: who did what, when, to which resource, including before/after payload snapshots. For a multi-tenant app this is a critical information disclosure.

**Recommendation**: Restrict audit endpoints to admin-scoped JWT (e.g., `claims['role'] === 'admin'`), or at minimum require any valid JWT. Add the audit prefix to `BearerTokenMiddleware` protected routes.

---

### ATK-08 — Audit Log Cross-Actor Enumeration via ?actor_id ⚠️ EXPOSED

**Attack**: `GET /audit?actor_id=2` (or enumerate 1..N) — reads all audit entries for any actor_id.
**Result**: No authorization check on `actor_id` filter. Attacker enumerates all user IDs and retrieves their complete audit history. Chained from ATK-07 (unauthenticated access).
**Recommendation**: If audit is restricted to authenticated users only (not admin), filter by the authenticated user's `sub` — callers cannot query other actors' logs. Admins see all.

---

### ATK-09 — SQL Injection in Audit Search Params 🚫 BLOCKED

**Attack**: `GET /audit?action=deleted';DROP TABLE audit_log;--&resource_type=task`
**Result**: `$action` and `$resourceType` are bound as `?` parameters in the SQL query. No string interpolation. SQLite receives `WHERE action = ?` with the literal injected string as a value — which simply returns 0 rows. Table is safe. → **200 OK (empty)**

---

### ATK-10 — Limit -1 / Large Limit DoS ✅ BLOCKED

**Attack**: `GET /audit?limit=-1` or `GET /audit?limit=99999`.
**Result**: `max(1, min((int) ($q['limit'] ?? 50), 100))` clamps to `[1, 100]`. Negative and oversized limits are silently clamped. → **200 OK (max 100 entries)**

---

### ATK-11 — Login Brute Force (No Rate Limiting) ⚠️ EXPOSED

**Attack**: Rapid sequential `POST /auth/login` attempts with the same email and different passwords.
**Result**: No rate limiting, no lockout, no CAPTCHA. An attacker can iterate passwords indefinitely. The Argon2id KDF slows each attempt to ~180ms, making brute force impractical for strong passwords but still feasible for weak ones.
**Recommendation**: Add `ThrottleMiddleware` on `/auth/login` (e.g., 5 attempts / 15 min per IP). Log failed attempts with request_id for monitoring.

---

### ATK-12 — Arbitrary Status Value Injection ⚠️ EXPOSED

**Attack**: `PUT /tasks/1` with body `{"status":"<script>alert(1)</script>"}` or `{"status":"admin_override"}`.
**Result**: The handler accepts any non-empty string as `status`. The repository writes it verbatim. The task is updated with `status="<script>alert(1)</script>"`. No enum validation, no allowlist.
**Impact**: Stored XSS if the status is rendered in a browser without escaping. Corrupted domain model if business logic assumes status in `{open, closed, in_progress}`.
**Recommendation**: Validate status against an allowlist or a PHP BackedEnum:
```php
$validStatuses = ['open', 'in_progress', 'closed'];
if (!in_array($status, $validStatuses, true)) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'status', 'code' => 'invalid', 'message' => 'status must be one of: open, in_progress, closed']],
    ]);
}
```

---

### ATK Summary

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | JWT `alg:none` | 🚫 BLOCKED |
| ATK-02 | JWT signature tampering | 🚫 BLOCKED |
| ATK-03 | Expired JWT replay | 🚫 BLOCKED |
| ATK-04 | IDOR: access other user's task | ✅ BLOCKED |
| ATK-05 | IDOR: delete other user's task | ✅ BLOCKED |
| ATK-06 | Actor ID injection via body | ✅ BLOCKED |
| ATK-07 | Unauthenticated audit log read | ⚠️ EXPOSED |
| ATK-08 | Cross-actor audit enumeration | ⚠️ EXPOSED |
| ATK-09 | SQL injection in audit search | 🚫 BLOCKED |
| ATK-10 | Limit -1 / oversized limit DoS | ✅ BLOCKED |
| ATK-11 | Login brute force (no rate limit) | ⚠️ EXPOSED |
| ATK-12 | Arbitrary status value injection | ⚠️ EXPOSED |

**9 BLOCKED / SAFE, 4 EXPOSED** (ATK-07, 08 chain from the same unauthenticated audit-read gap).

The critical finding is **ATK-07**: the audit log endpoints have no authentication guard, exposing full actor activity history to any unauthenticated caller. ATK-12 (status allowlist) and ATK-11 (rate limiting) are standard hardening gaps. No SQL injection or JWT forgery vectors were found.
