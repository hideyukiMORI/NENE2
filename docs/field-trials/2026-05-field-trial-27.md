# Field Trial 27 — Movie/TV Watchlist API (watchlog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/watchlog/`
**NENE2 version**: 1.5.10
**Theme**: Soft deletion (archive/restore), PHP enums as domain types, status transition validation, action endpoints

## What was built

A movie/TV show watchlist API with:

- `GET /watch` — list active entries (`?include_archived=true` to include archived, `?status=`, `?media_type=` filters)
- `POST /watch` — create with title and media_type (`movie` | `tv`); starts at `want-to-watch`
- `GET /watch/{id}` — fetch by ID
- `PATCH /watch/{id}/status` — transition status with validation, optional rating and note
- `POST /watch/{id}/archive` — soft-archive (sets `archived_at`)
- `POST /watch/{id}/restore` — restore (clears `archived_at`)
- `DELETE /watch/{id}` — hard delete

Status machine: `want-to-watch` → `watching` | `dropped`; `watching` → `completed` | `dropped` | `want-to-watch`; `completed`/`dropped` → `want-to-watch`.

31/31 tests pass. PHPStan level 8 clean. PHP-CS-Fixer clean.

## Frictions found

### 1. PHP enum values validated by `WatchStatus::tryFrom()` — no framework enum validator (Low)

**Description**: Validating that a query parameter or body field is a valid enum value requires calling `Enum::tryFrom()` and checking for `null`. There is no framework helper to convert a raw string to an enum with a typed `ValidationError` on failure.

**Severity**: Low

**Reproduction**:
```php
$statusRaw = QueryStringParser::string($request, 'status');
$status    = $statusRaw !== null ? WatchStatus::tryFrom($statusRaw) : null;

if ($statusRaw !== null && $status === null) {
    $errors[] = new ValidationError('status', 'Invalid status value.', 'invalid_value');
}
```

This pattern is repeated for every enum field (status, media_type in list; status, rating in update). **Positive finding**: PHP 8.1+ backed enums make this clean and explicit — no string-comparison errors possible.

---

### 2. PHPStan: `const array` with typed offsets — `?? []` redundant (Low)

**Description**: When a `const array` has typed keys covering all enum values, PHPStan correctly infers that offset access always exists and flags `?? []` as redundant (`nullCoalesce.offset`).

**Severity**: Low (PHPStan correctness, easy fix)

**Reproduction**:
```php
private const array TRANSITIONS = [
    'want-to-watch' => ['watching', 'dropped'],
    'watching'      => ['completed', 'dropped', 'want-to-watch'],
    'completed'     => ['want-to-watch'],
    'dropped'       => ['want-to-watch'],
];

// PHPStan error: "?? [] always exists and is not nullable"
$allowed = self::TRANSITIONS[$existing->status->value] ?? [];

// Fix: remove the fallback
$allowed = self::TRANSITIONS[$existing->status->value];
```

**Fix**: PHPStan correctly identifies that the key always exists when enum values map 1:1 with the const array keys. Remove the `?? []` fallback.

---

### 3. Action endpoints (`/archive`, `/restore`) vs PATCH — no framework guidance (Medium)

**Description**: When building action endpoints that don't fit CRUD semantics (archive, restore, publish, approve), the choice between `POST /resource/{id}/action` vs `PATCH /resource/{id}` with an action field is not documented. Both work in NENE2, but the pattern is not established.

**Severity**: Medium

**Pattern used** (POST action endpoint):
```php
$router->post('/watch/{id}/archive', $this->archive(...));
$router->post('/watch/{id}/restore', $this->restore(...));
```

Response: `200 OK` with the updated resource body.

**Positive finding**: Action endpoints map cleanly to route handlers. The `POST /resource/{id}/action` pattern reads naturally. The router supports arbitrary nesting. No friction in implementation — only documentation gap.

**Potential fix**: Document the POST action endpoint pattern in `add-custom-route.md` or a new `implement-action-endpoints.md` howto.

---

### 4. Soft deletion with `include_archived` boolean query param — works cleanly (Positive)

**Description**: `QueryStringParser::bool()` correctly handles `?include_archived=true/false`. Default `false` filters out archived rows using `WHERE archived_at IS NULL`.

**Pattern used**:
```php
$includeArchived = QueryStringParser::bool($request, 'include_archived') ?? false;
// In SQL: if (!$includeArchived) { $sql .= ' AND archived_at IS NULL'; }
```

No friction. `QueryStringParser::bool()` returning `null` for absent keys and the `?? false` default is clean and explicit.

## Tests

31 tests, 52 assertions — all pass.

Coverage: list (empty, active-only by default, include_archived, filter by status, filter by media_type, invalid enum values, pagination), create (201, missing title, missing/invalid media_type), show (200, 404), status transitions (want→watching, watching→completed+rating, watching→dropped, invalid want→completed, with note, invalid rating, missing status, 404), archive (sets archived_at, excluded from default list, 404), restore (clears archived_at, appears in list), delete (204, 404), infrastructure (security headers + X-Request-Id, unknown route 404 problem details).
