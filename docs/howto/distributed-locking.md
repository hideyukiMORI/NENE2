# Distributed Locking

A distributed lock prevents concurrent processes from executing a critical section at the same time. DB-backed locks trade throughput for simplicity — no Redis required, and the same DB that holds your data holds your locks.

## Core concepts

- **Resource**: the name of the thing being locked (e.g., `job:42`, `report:monthly-2026-05`)
- **Owner**: a token that identifies the lock holder — only the owner can release or renew
- **Expiry (TTL)**: locks auto-expire so a crashed owner cannot hold a lock forever
- **Stale lock claim**: an expired lock can be taken over by a new owner

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

The `UNIQUE` constraint on `resource` ensures that only one row per resource exists. Concurrent INSERTs are serialized at the DB level.

## Acquisition logic

```php
public function acquire(string $resource, string $owner, string $expiresAt, string $now): ?LockRecord
{
    $existing = $this->findByResource($resource);

    if ($existing === null) {
        // No lock — INSERT (may fail on race; caller gets null and retries)
        $this->executor->execute(
            'INSERT INTO distributed_locks (resource, owner, expires_at, acquired_at) VALUES (?, ?, ?, ?)',
            [$resource, $owner, $expiresAt, $now],
        );
        return $this->findByResource($resource);
    }

    if ($existing->isExpired($now) || $existing->owner === $owner) {
        // Expired (stale) or same owner re-acquiring — UPDATE to claim
        $this->executor->execute(
            'UPDATE distributed_locks SET owner = ?, expires_at = ?, acquired_at = ? WHERE resource = ?',
            [$owner, $expiresAt, $now, $resource],
        );
        return $this->findByResource($resource);
    }

    // Held by another owner and still valid — cannot acquire
    return null;
}
```

Return value conventions:
- Returns a `LockRecord` on success (`acquired: true` in the API response)
- Returns `null` when the lock is held by another owner (`acquired: false`)

## Owner-enforced release

Only the owner may release. Returning 403 (not 404) when the owner doesn't match tells the caller that the lock exists but they don't hold it:

```php
return match ($result) {
    ReleaseResult::Released  => $this->json->create([], 204),
    ReleaseResult::NotFound  => $this->problems->create($request, 'not-found', 'Lock not found.', 404, ''),
    ReleaseResult::Forbidden => $this->problems->create($request, 'forbidden', 'Owner mismatch.', 403, ''),
};
```

## TTL renewal

Long-running tasks need to extend their lock before it expires. Only the current owner may renew — a renew by a wrong owner returns 409 (not 403) because it signals a state conflict, not a permission denial:

```php
if ($existing->isExpired($now)) {
    return null; // → 409: can't renew an expired lock (someone else may now hold it)
}
if ($existing->owner !== $owner) {
    return null; // → 409: wrong owner
}
// Extend expires_at
```

## Stale lock detection

`LockRecord::isExpired()` compares the current time against `expires_at`:

```php
public function isExpired(string $now): bool
{
    return $now >= $this->expiresAt;
}
```

This means `GET /locks/{resource}` returns 404 for expired locks (treating expired as non-existent), and `POST /locks/{resource}` lets a new owner claim an expired lock.

## Design decisions

**Why not Redis SETNX?**
Redis gives atomic SETNX with TTL in a single command and is the production standard for high-throughput locking. DB-backed locking is simpler to deploy (no additional service), consistent with the rest of your transactional data, and sufficient for low-to-medium contention scenarios (background jobs, report generation, batch processing).

**Why not DELETE+INSERT on re-acquire?**
UPDATE preserves the row ID and is atomic. DELETE+INSERT would create a brief window where no lock row exists, allowing a concurrent process to INSERT and steal the lock.

**Why separate `acquired_at` from `expires_at`?**
`acquired_at` is the timestamp when ownership was last established (useful for audit). `expires_at` changes on renewal. Keeping them separate avoids ambiguity.

**Non-blocking by design**
The lock endpoint returns immediately with `acquired: false` rather than blocking until the lock is available. Callers implement their own retry strategy (exponential backoff, dead-letter queue, etc.) based on their timeout requirements.
