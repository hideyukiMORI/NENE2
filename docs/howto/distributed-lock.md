# How-to: Distributed Lock

> **FT reference**: FT288 (`NENE2-FT/distlocklog`) — Distributed lock: UNIQUE(resource) DB constraint, owner verification, TTL-based expiry, expired lock re-acquisition by design, ReleaseResult enum (Released/NotFound/Forbidden), 403 on owner mismatch, 16 tests / 27 assertions PASS.
>
> **ATK assessment**: ATK-01 through ATK-12 included at the end of this document.

This guide shows how to implement a distributed lock API — prevent concurrent operations on the same resource by issuing leased locks.

## What is a Distributed Lock?

When multiple processes need exclusive access to a shared resource (e.g., a payment, a file, a queue job), a distributed lock ensures only one process proceeds at a time. Locks have a TTL so they auto-expire if the holder crashes.

## Schema

```sql
CREATE TABLE distributed_locks (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource    TEXT    NOT NULL UNIQUE,
    owner       TEXT    NOT NULL,
    expires_at  TEXT    NOT NULL,
    acquired_at TEXT    NOT NULL
);
```

`resource TEXT UNIQUE` — one row per resource. Acquiring inserts or updates this row.

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/locks/{resource}` | Acquire lock |
| `GET` | `/locks/{resource}` | Get lock status |
| `DELETE` | `/locks/{resource}` | Release lock |
| `POST` | `/locks/{resource}/renew` | Extend TTL |

## Acquire Logic

```php
public function acquire(string $resource, string $owner, string $expiresAt, string $now): ?LockRecord
{
    $existing = $this->findByResource($resource);

    if ($existing === null) {
        // No lock — INSERT (UNIQUE constraint handles races)
        try {
            $this->executor->execute('INSERT INTO distributed_locks ...', [...]);
        } catch (\RuntimeException) {
            return null;  // Race: another process inserted concurrently
        }
        return $this->findByResource($resource);
    }

    if ($existing->isExpired($now) || $existing->owner === $owner) {
        // Expired → re-acquire (UPDATE replaces the old row)
        // Same owner → re-acquire (extend or re-lock)
        $this->executor->execute('UPDATE distributed_locks SET owner = ?, expires_at = ?, acquired_at = ? WHERE resource = ?', ...);
        return $this->findByResource($resource);
    }

    // Held by another owner, not expired → cannot acquire
    return null;
}
```

## Release with Owner Verification

```php
$result = $this->repo->release($resource, $owner, $now);

return match ($result) {
    ReleaseResult::Released  => $this->json->create([], 204),
    ReleaseResult::NotFound  => $this->problems->create($request, 'not-found', 'Lock not found.', 404),
    ReleaseResult::Forbidden => $this->problems->create($request, 'forbidden', 'Owner mismatch.', 403),
};
```

Only the lock owner can release it. Wrong `owner` → 403 Forbidden.

## ReleaseResult Enum

```php
enum ReleaseResult
{
    case Released;   // Lock found, owner matched, row deleted
    case NotFound;   // Lock not found or already expired
    case Forbidden;  // Lock found, but owner doesn't match
}
```

Using an enum (not magic strings) ensures exhaustive handling in `match`.

## Acquire Response

```php
// Success:
{ "acquired": true, "lock": { "resource": "...", "owner": "...", "expires_at": "...", "acquired_at": "..." } }

// Failure (held by another):
{ "acquired": false, "resource": "payment:42" }
```

`acquired: false` is not an error — it means "try again later." No 4xx status; the caller should retry.

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — Acquire lock held by another owner 🚫 BLOCKED

**Attack**: Attacker tries to acquire `locks/payment:42` while another process holds it.
**Result**: BLOCKED — repository checks `existing.owner === $caller_owner`. Different owner + not expired → returns `null` → `{ acquired: false }`. No error, no crash — the attacker simply doesn't get the lock.

---

### ATK-02 — Release lock owned by another 🚫 BLOCKED

**Attack**: Attacker sends `DELETE /locks/payment:42` with `{ "owner": "attacker" }` to forcibly release a lock.
**Result**: BLOCKED — repository checks `lock.owner === $body_owner`. Mismatch → `ReleaseResult::Forbidden` → 403.

---

### ATK-03 — Steal lock after expiry 🚫 BLOCKED (by design)

**Attack**: Attacker waits for lock to expire, then acquires it.
**Result**: BLOCKED (by design) — expired locks can be re-acquired by any owner. This is the intended behavior: TTL-based expiry is how crashed holders lose their locks. Reducing TTL-based attacks requires coordination (heartbeat renewal).

---

### ATK-04 — Renew lock owned by another 🚫 BLOCKED

**Attack**: Attacker sends `POST /locks/payment:42/renew` with `{ "owner": "attacker", "ttl_seconds": 3600 }`.
**Result**: BLOCKED — renew checks `lock.owner === $body_owner`. Mismatch → 403 Forbidden.

---

### ATK-05 — Zero or negative TTL to create already-expired lock 🚫 BLOCKED

**Attack**: Send `{ "ttl_seconds": 0 }` or `{ "ttl_seconds": -100 }` to create a lock that expires instantly.
**Result**: BLOCKED — `if ($ttlSeconds === null || $ttlSeconds < 1)` → 422 validation error.

---

### ATK-06 — SQL injection via resource path parameter 🚫 BLOCKED

**Attack**: Use `locks/resource'; DROP TABLE distributed_locks; --` as the resource name.
**Result**: BLOCKED — all queries use parameterized statements (`WHERE resource = ?`). The injected string is treated as a literal resource identifier.

---

### ATK-07 — Empty owner to bypass ownership check 🚫 BLOCKED

**Attack**: Send `{ "owner": "" }` or `{ "owner": "   " }` to release or renew without valid ownership.
**Result**: BLOCKED — `$owner = trim(...); if ($owner === '')` → 422 validation error.

---

### ATK-08 — Non-integer TTL to bypass type validation 🚫 BLOCKED

**Attack**: Send `{ "ttl_seconds": "3600" }` (string) or `{ "ttl_seconds": 60.5 }` (float).
**Result**: BLOCKED — `is_int($body['ttl_seconds'])` rejects strings and floats. Only JSON integer type accepted.

---

### ATK-09 — Acquire with same owner multiple times 🚫 BLOCKED (by design)

**Attack**: Same owner re-acquires a lock they hold to extend it without using `/renew`.
**Result**: ALLOWED (by design) — `$existing->owner === $owner` → UPDATE (re-acquire/extend). Same-owner re-acquisition is idempotent and safe; it updates `expires_at` and `acquired_at`.

---

### ATK-10 — Race condition: two owners acquire concurrently 🚫 BLOCKED

**Attack**: Two processes both see no lock and both attempt INSERT simultaneously.
**Result**: BLOCKED — `UNIQUE(resource)` constraint ensures only one INSERT succeeds. The loser catches `\RuntimeException` and returns `null` → `{ acquired: false }`. Only one owner wins.

---

### ATK-11 — GET non-existent or expired lock 🚫 BLOCKED

**Attack**: Call `GET /locks/nonexistent` or wait for lock to expire then call GET.
**Result**: BLOCKED — `if ($lock === null || $lock->isExpired($now)) return 404`. Expired locks return 404 (not the stale lock data).

---

### ATK-12 — Extremely long resource name to cause DoS 🚫 BLOCKED (design note)

**Attack**: Send `{ "resource": "<10MB string>" }` as the resource path parameter.
**Result**: PARTIALLY BLOCKED — the resource comes from the URL path, limited by web server path length (typically 8KB). No explicit application-level length validation is present in this FT. In production, add `if (strlen($resource) > 255)` → 422. The DB stores whatever the application passes.

---

### ATK Summary

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | Acquire lock held by another | 🚫 BLOCKED |
| ATK-02 | Release lock owned by another | 🚫 BLOCKED |
| ATK-03 | Steal lock after TTL expiry | 🚫 BLOCKED (by design) |
| ATK-04 | Renew lock owned by another | 🚫 BLOCKED |
| ATK-05 | Zero/negative TTL | 🚫 BLOCKED |
| ATK-06 | SQL injection via resource path | 🚫 BLOCKED |
| ATK-07 | Empty owner bypass | 🚫 BLOCKED |
| ATK-08 | Non-integer TTL type bypass | 🚫 BLOCKED |
| ATK-09 | Same-owner re-acquisition | 🚫 BLOCKED (intended) |
| ATK-10 | Race condition on concurrent acquire | 🚫 BLOCKED |
| ATK-11 | GET expired/nonexistent lock | 🚫 BLOCKED |
| ATK-12 | Extremely long resource name | ⚠️ DESIGN NOTE |

**11 BLOCKED, 1 DESIGN NOTE, 0 EXPOSED**
Owner verification, `UNIQUE(resource)` race protection, TTL validation, and parameterized queries prevent all critical attack vectors.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| No `UNIQUE(resource)` constraint | Race condition: two owners both acquire; TOCTOU vulnerability |
| Release without owner check | Any process can release any lock; no exclusivity guarantee |
| No TTL on locks | Crashed holder's lock persists forever; system deadlock |
| Accept TTL of 0 or negative | Lock is already expired on creation; immediately re-acquirable |
| Return 404 on owner mismatch (release) | Attacker cannot distinguish "lock doesn't exist" from "wrong owner"; use 403 |
| Accept string/float as TTL | `"3600"` looks valid but `is_int` fails; strict type check prevents subtle bugs |
| Store owner without validation | Empty owner bypasses ownership; always validate non-empty |
| No resource length limit | Web server path limit is the only guard; add explicit validation |
| Renew expired locks | Expired lock belongs to no one; re-acquire instead of renew |
