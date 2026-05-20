# Field Trial 62 — Soft Delete / Trash Bin (softdeletelog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/softdeletelog/`
**NENE2 version**: 1.5.20
**Theme**: Soft delete — `deleted_at` nullable column, hidden from active queries, restorable, permanently purgeable

## Overview

Built a notes API to validate the soft delete pattern:
- **Soft delete**: `DELETE /notes/{id}` sets `deleted_at` timestamp; row is hidden from `GET /notes` (active list) and `GET /notes/{id}`.
- **Trash bin**: `GET /notes/trash` shows only soft-deleted notes.
- **Restore**: `POST /notes/{id}/restore` clears `deleted_at`; note reappears in the active list.
- **Purge**: `DELETE /notes/{id}/purge` permanently deletes a trashed note; only allowed when `deleted_at` is set.

## Endpoints Implemented

- `POST /notes` — create note
- `GET /notes` — active notes only (`deleted_at IS NULL`)
- `GET /notes/trash` — trashed notes only (`deleted_at IS NOT NULL`)
- `GET /notes/{id}` — fetch active note (404 if deleted)
- `DELETE /notes/{id}` — soft delete
- `POST /notes/{id}/restore` — restore from trash
- `DELETE /notes/{id}/purge` — permanent delete (only from trash)

## Test Results

18 tests, 30 assertions — all pass. PHPStan level 8: no errors. PHP-CS-Fixer: no issues.

---

## Frictions Found

### Friction 1 — `JsonResponseFactory::create()` cannot return a bare JSON array (Medium)

**Context**: `GET /notes` and `GET /notes/trash` naturally return a JSON array (`[]`). REST convention: list endpoints return arrays. But `JsonResponseFactory::create()` is typed `array<string, mixed>` — only JSON objects are accepted.

**Reproduction**:
```php
// Causes PHPStan error: list<array<string, mixed>> given, array<string, mixed> expected
return $this->json->create(array_map(fn($n) => $n->toArray(), $notes));
```

**Workaround**: Wrap the list in an envelope object:
```php
return $this->json->create(['items' => array_map(fn($n) => $n->toArray(), $notes)]);
```

**Impact**: All list endpoints require an envelope wrapper, which changes the API shape from idiomatic REST (`[]`) to `{"items": [...]}`. Clients must be aware of this convention.

**Suggested NENE2 fix**: Add a second method `JsonResponseFactory::createList(array $items, int $status = 200)` that accepts `list<mixed>` and encodes it as a top-level JSON array.

---

### Friction 2 — PHPStan catches redundant `isset() && !== null` pattern in hydrate methods (Low)

**Context**: When hydrating a row from `fetchAll()`, I wrote:
```php
isset($row['deleted_at']) && $row['deleted_at'] !== null ? (string) $row['deleted_at'] : null
```
PHPStan (level 8) correctly flags: "Strict comparison using `!== null` will always evaluate to true" — because `isset()` already eliminates null values.

**Fix**: Use `isset()` alone:
```php
isset($row['deleted_at']) ? (string) $row['deleted_at'] : null
```

**NENE2 status**: No change needed. This is a PHP idiom clarification for FT authors.

---

## Patterns Validated

### `deleted_at IS NULL` filter on all active queries

```sql
SELECT * FROM notes WHERE deleted_at IS NULL ORDER BY created_at DESC
SELECT * FROM notes WHERE id = ? AND deleted_at IS NULL
```

The `NULL` check is added consistently at the SQL level — no application-layer filtering.

### Restore clears `deleted_at`

```php
$this->executor->execute('UPDATE notes SET deleted_at = NULL WHERE id = ?', [$id]);
```

NULL assignment in SQL is clean with PDO parameterized queries.

### Purge requires the row to be in the trash first

```php
public function purge(int $id): bool
{
    $note = $this->findById($id, includeTrashed: true);
    if ($note === null || !$note->isDeleted()) {
        return false;  // 404 — either not found or not in trash
    }
    $this->executor->execute('DELETE FROM notes WHERE id = ?', [$id]);
    return true;
}
```

Two-step guard prevents accidentally purging an active note via the purge endpoint.

---

## NENE2 Changes Required

**Issue to file**: `JsonResponseFactory` lacks a `createList()` method for bare JSON array responses. Medium severity — forces an envelope wrapper on all list endpoints.
