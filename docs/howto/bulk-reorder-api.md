---
title: "Bulk Reorder (Drag-and-Drop Ordering) API"
category: api-design
tags: [ordering, bulk-update, case-when, idor, atomicity]
difficulty: intermediate
related: [content-pinning, pin-bookmark-ordering, use-transactions]
---

# Bulk Reorder (Drag-and-Drop Ordering) API

A drag-and-drop UI sends the *whole* new order of a list in one request: `[itemC, itemA, itemD, itemB]`. The naive server does one `UPDATE` per item — N round-trips and a half-applied order if one fails.

The right shape is **one transaction** that rewrites every position with server-assigned values, scoped to the owner's board. How it's written depends on one thing: **whether `position` carries a `UNIQUE (board_id, position)` constraint.**

> **Verified gotcha (FT352).** SQLite checks `UNIQUE` **per row** as an `UPDATE` is applied. So *any* statement that swaps positions — even a single `CASE WHEN` over all rows — transiently puts two rows at the same position and fails with `UNIQUE constraint failed: items.board_id, items.position`. A single statement is enough **only when `position` has no `UNIQUE` constraint** (§1). With the constraint, you need a two-phase write inside a transaction (§1.1). The runnable proof is in [`NENE2-examples/reorderlog`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/reorderlog).

**Prerequisite**: A table with an integer `position` column scoped to a parent (`board_id`, `list_id`, …). See [Content pinning](content-pinning.md) for the single-item case.

---

## 1. One statement (no `UNIQUE` constraint on `position`)

The client sends only the *ordered list of ids*. The server derives positions from the array index — it never trusts client-supplied position numbers. When `position` is just an indexed column (no `UNIQUE`), a single statement is enough:

```php
/**
 * @param list<int> $orderedIds  ids in their new display order
 * @return int  number of rows actually updated
 */
public function reorder(int $boardId, array $orderedIds): int
{
    $cases  = '';
    $params = [];
    foreach (array_values($orderedIds) as $position => $id) {
        $cases   .= ' WHEN id = ? THEN ?';
        $params[] = $id;
        $params[] = $position;          // position = array index, not client input
    }

    $placeholders = implode(',', array_fill(0, count($orderedIds), '?'));
    $sql = "UPDATE items
            SET position = CASE{$cases} END
            WHERE board_id = ? AND id IN ({$placeholders})";

    return $this->executor->execute(
        $sql,
        [...$params, $boardId, ...$orderedIds],
    );
}
```

Verified against SQLite — reordering `[1,2,3,4]` to ids `[3,1,4,2]` in one statement:

```
affected = 4
position 0 -> item 3
position 1 -> item 1
position 2 -> item 4
position 3 -> item 2
```

Positions are reassigned `0..n-1` from the array index, so the result is always contiguous regardless of what the client sent.

---

## 1.1. Two-phase write when `position` is `UNIQUE`

If `UNIQUE (board_id, position)` guards your ordering (recommended — it stops duplicate positions at the database level), the single statement above fails the moment it swaps two rows. Shift every position into a collision-free range first, then assign the final values — both steps in **one transaction** so the intermediate state is never observable:

```php
public function reorder(int $boardId, array $orderedIds): void
{
    $this->tx->transactional(function ($executor) use ($boardId, $orderedIds): void {
        // Phase 1: move every position to a unique negative value (no collisions).
        $executor->execute(
            'UPDATE items SET position = -1 - position WHERE board_id = ?',
            [$boardId],
        );

        // Phase 2: assign final positions from the array index.
        $cases = '';
        $params = [];
        foreach ($orderedIds as $position => $id) {
            $cases   .= ' WHEN id = ? THEN ?';
            $params[] = $id;
            $params[] = $position;
        }
        $placeholders = implode(',', array_fill(0, count($orderedIds), '?'));
        $executor->execute(
            "UPDATE items SET position = CASE{$cases} END WHERE board_id = ? AND id IN ({$placeholders})",
            [...$params, $boardId, ...$orderedIds],
        );
    });
}
```

`-1 - position` maps `0,1,2,…` to `-1,-2,-3,…` — distinct values that cannot clash with the final `0..n-1`. See [Use transactions](use-transactions.md) for the `transactional()` rule (instantiate repositories *inside* the callback). `reorderlog`'s `testReorderAdjacentSwapDoesNotCollide` exercises exactly the swap that breaks a single statement.

---

## 2. The affected-row count is your integrity check

`execute()` returns the number of rows matched by `WHERE board_id = ? AND id IN (...)`. Compare it to the request size:

```php
$updated = $this->reorder($boardId, $orderedIds);
if ($updated !== count($orderedIds)) {
    // The client referenced ids that are not in this board (or do not exist).
    throw new ValidationException(/* 'ids' => 'contains items not in this board' */);
}
```

This single check defeats most of the attack surface below: any id that belongs to another board, or does not exist, simply does not match `WHERE`, so the count comes up short and the whole reorder is rejected.

> Wrap the count check and `UPDATE` in `transactional()` if you also mutate related rows; the single `UPDATE` itself is already atomic. See [Use transactions](use-transactions.md).

---

## ATK Assessment — Cracker-Mindset Attack Test

Target: `PUT /boards/{boardId}/order` with body `{ "ids": [...] }`, authenticated, `board_id` scoped to the caller.

### ATK-01 — Reorder a board you do not own (IDOR) 🚫 BLOCKED

**Attack**: Send a valid `ids` array but a `boardId` belonging to another user.
**Result**: BLOCKED — ownership is checked before the query (`board.owner_id === caller`), returning `404`; even if skipped, `WHERE board_id = ?` matches no rows the caller's ids belong to, so the affected count is 0 and the request is rejected.

---

### ATK-02 — Smuggle a foreign item into the order 🚫 BLOCKED

**Attack**: Include an `id` from a different board to move/leak it.
**Result**: BLOCKED — `WHERE board_id = ? AND id IN (...)` excludes the foreign id; affected count < request size → `422`, no partial write.

---

### ATK-03 — Partial order (omit ids to create gaps) 🚫 BLOCKED

**Attack**: Send only half the board's ids to leave the rest at stale positions.
**Result**: BLOCKED — the handler requires the submitted set to equal the board's current id set (count + membership), rejecting incomplete payloads.

---

### ATK-04 — Inject explicit position numbers 🚫 BLOCKED

**Attack**: Send `{ "ids": [...], "positions": [99, -1, ...] }` hoping the server honours them.
**Result**: BLOCKED — the server ignores any client position; `position` is the array index. Extra body fields are dropped by the readonly DTO.

---

### ATK-05 — SQL injection via id / position 🚫 BLOCKED

**Attack**: `ids: ["1); DROP TABLE items;--", ...]`.
**Result**: BLOCKED — every id and position is a bound parameter; the `CASE`/`IN` placeholders are generated by count, never by string concatenation.

---

### ATK-06 — Duplicate ids to corrupt positions 🚫 BLOCKED

**Attack**: `ids: [5, 5, 5]` so one row gets several `CASE` arms.
**Result**: BLOCKED — the DTO validates id uniqueness; SQLite would in any case apply the last matching `WHEN`, and the count check (`distinct ids` vs board size) fails first.

---

### ATK-07 — Oversized payload (DoS) 🚫 BLOCKED

**Attack**: Post 1,000,000 ids to build a giant `CASE`.
**Result**: BLOCKED — `RequestSizeLimitMiddleware` caps the body, and the handler rejects arrays larger than the board's row count.

---

### ATK-08 — Non-integer / negative ids 🚫 BLOCKED

**Attack**: `ids: ["abc", -1, 1.5]`.
**Result**: BLOCKED — DTO validation coerces/validates each entry as a positive integer (`422` on failure) before any SQL runs.

---

### ATK-09 — Concurrent reorder race 🚫 BLOCKED

**Attack**: Fire two reorders simultaneously to interleave positions.
**Result**: BLOCKED — each reorder runs in one transaction; the last writer wins with a fully-consistent `0..n-1` ordering, never an interleaved mix. The two-phase write (§1.1) keeps the intermediate state inside the transaction, so a concurrent reader never sees a partial or colliding order.

---

### ATK-10 — Position overflow / non-contiguous result 🚫 BLOCKED

**Attack**: Hope repeated reorders drift positions to huge or sparse values.
**Result**: BLOCKED — every reorder rewrites positions from `0`, so the column is always dense and bounded by the row count.

---

### ATK-11 — Empty order to wipe positions 🚫 BLOCKED

**Attack**: `ids: []`.
**Result**: BLOCKED — empty arrays fail validation (`min 1`), and an empty `IN ()` would be a syntax error that never executes.

---

### ATK-12 — Cross-tenant board id enumeration 🚫 BLOCKED

**Attack**: Iterate `boardId` to discover which exist via differing responses.
**Result**: BLOCKED — unknown and unowned boards both return an identical `404`; no count or timing oracle distinguishes them.

---

### ATK Summary

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | Reorder unowned board (IDOR) | 🚫 BLOCKED |
| ATK-02 | Smuggle foreign item | 🚫 BLOCKED |
| ATK-03 | Partial order / gaps | 🚫 BLOCKED |
| ATK-04 | Inject explicit positions | 🚫 BLOCKED |
| ATK-05 | SQL injection | 🚫 BLOCKED |
| ATK-06 | Duplicate ids | 🚫 BLOCKED |
| ATK-07 | Oversized payload | 🚫 BLOCKED |
| ATK-08 | Non-integer / negative ids | 🚫 BLOCKED |
| ATK-09 | Concurrent reorder race | 🚫 BLOCKED |
| ATK-10 | Position overflow / sparsity | 🚫 BLOCKED |
| ATK-11 | Empty order | 🚫 BLOCKED |
| ATK-12 | Board id enumeration | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED.** No critical findings. The combination of *server-assigned positions* (array index, never client input) and the *affected-count / id-set integrity check* against a board-scoped `WHERE` closes the reorder surface. The one *correctness* trap (not a security finding) is the `UNIQUE (board_id, position)` constraint: it makes a single `CASE` statement fail on any swap, so use the two-phase transactional write of §1.1 — verified in [`NENE2-examples/reorderlog`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/reorderlog).

---

## Related

- [Content pinning](content-pinning.md) — single-item position management
- [Pin / bookmark ordering](pin-bookmark-ordering.md) — per-user ordering
- [Use transactions](use-transactions.md) — wrap multi-table reorders atomically
