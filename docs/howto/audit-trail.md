# HOWTO: Audit Trail — Recording Who Changed What

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
