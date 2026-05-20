# Field Trial 84 — Waitlist Queue System (waitlistlog)

**Date:** 2026-05-20
**Project:** `/home/xi/docker/NENE2-FT/waitlistlog/`
**NENE2 version:** 1.5.23
**Theme:** FIFO waitlist with derived position ranking, advance-queue operation, and leave mechanics

---

## What was built

A queue/waitlist API where users join named queues and are served in FIFO order. Position is never stored — it is computed as `COUNT(*) WHERE joined_at <= entry.joined_at AND status = 'waiting'`. The advance operation marks the earliest-joined waiting entry as `served`.

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/queues/{queue}/join` | Join a queue; 409 if already waiting |
| GET | `/queues/{queue}/entries` | List all waiting entries in FIFO order |
| GET | `/queues/{queue}/users/{userId}` | Get a user's current position |
| POST | `/queues/{queue}/advance` | Serve the first waiting entry; 404 if empty |
| DELETE | `/queues/{queue}/users/{userId}` | Leave the queue |

### Schema

```sql
CREATE TABLE IF NOT EXISTS waitlist_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    queue      TEXT    NOT NULL,
    user_id    TEXT    NOT NULL,
    status     TEXT    NOT NULL DEFAULT 'waiting' CHECK(status IN ('waiting', 'served', 'left')),
    note       TEXT    NOT NULL DEFAULT '',
    joined_at  TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE(queue, user_id)
);
```

Position formula: `SELECT COUNT(*) FROM waitlist_entries WHERE queue = ? AND status = 'waiting' AND joined_at <= ?`

---

## Frictions found

### 1. Missing `@return list<T>` PHPDoc triggers PHPStan level 8

**Severity:** Low

`listWaiting(): array` without an iterable value type causes PHPStan level 8 error:
`missingType.iterableValue — Method return type has no value type specified in iterable type array.`

Fix: add `@return list<WaitlistEntry>`. This is a consistent pattern across all FT projects — PHPStan level 8 requires explicit generic annotations on all array return types.

This was caught immediately on first analysis run and is easy to fix, but it is a recurring friction point across every FT. The constraint is PHP's lack of native generics, not a NENE2 issue.

---

### 2. `DatabaseConstraintException` for duplicate-join detection

**Severity:** Low (informational — pattern confirmed again)

Same UNIQUE constraint pattern as FT83 (creditslog idempotency) and FT82 (ratinglog upsert). `join()` catches `DatabaseConstraintException` and returns `null` for a 409 response. Third use of this API in three consecutive FTs — the exception is proving its value.

---

### 3. Position computation requires a correlated count query

**Severity:** Low

Position is not stored — it is computed by counting waiting entries with `joined_at ≤` the target entry's timestamp. This means:
- Join: one extra query after insert
- GetPosition: one extra query after fetchOne
- ListWaiting: positions assigned in PHP from an ordered result set (no N+1)

For the `list` endpoint, PHP-side sequential numbering avoids N+1. For single-entry lookups (`join` and `getPosition`), the extra correlated query is acceptable for this scale.

---

### 4. Soft-status model (waiting/served/left) preserves history

**Severity:** Low (design note)

Entries are never deleted. Users who were served (`advance`) or left (`leave`) remain in the table with updated status. This means:
- Duplicate join after leaving returns 409 (UNIQUE constraint fires on the existing row regardless of status)
- A user who was previously served cannot re-join the same queue with the same `user_id`

If re-joining after leaving is a requirement, the UNIQUE constraint would need to be removed and duplicate detection handled in application code. This was an intentional design choice: simplicity over flexibility.

---

## Results

| Check | Result |
|-------|--------|
| PHPUnit tests | 16 tests, 28 assertions — OK |
| PHPStan level 8 | 1 error (missing `@return list<T>`) → fixed → No errors |
| PHP-CS-Fixer | 0 files to fix |

---

## Notes

- FIFO order guaranteed by `joined_at` timestamp (UTC, same-second ties would be arbitrary — acceptable for a demo).
- Position shifts automatically after advance/leave because it is computed, not stored.
- Queue names are free-form strings — no registration required; a queue springs into existence on first join.
