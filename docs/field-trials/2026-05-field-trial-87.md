# Field Trial 87 — Feature Toggle System (featureflaglog)

**Date:** 2026-05-20
**Project:** `/home/xi/docker/NENE2-FT/featureflaglog/`
**NENE2 version:** 1.5.23
**Theme:** Feature flags with global defaults, per-user overrides, and evaluation priority

---

## What was built

A feature flag system where flags have a global default (enabled/disabled) and individual users can have overrides. Flag evaluation checks the user override first; if none exists, falls back to the global default.

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/flags` | Create a flag; 409 if name already exists |
| GET | `/flags` | List all flags (alphabetical) |
| GET | `/flags/{name}` | Get flag configuration |
| PATCH | `/flags/{name}` | Update global default |
| PUT | `/flags/{name}/users/{userId}` | Set per-user override (upsert) |
| DELETE | `/flags/{name}/users/{userId}` | Remove per-user override |
| GET | `/flags/{name}/evaluate/{userId}` | Evaluate flag for user |

### Schema

```sql
CREATE TABLE IF NOT EXISTS feature_flags (
    name            TEXT    NOT NULL PRIMARY KEY,
    default_enabled INTEGER NOT NULL DEFAULT 0 CHECK(default_enabled IN (0, 1)),
    description     TEXT    NOT NULL DEFAULT '',
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS flag_overrides (
    flag_name TEXT    NOT NULL REFERENCES feature_flags(name),
    user_id   TEXT    NOT NULL,
    enabled   INTEGER NOT NULL CHECK(enabled IN (0, 1)),
    set_at    TEXT    NOT NULL,
    PRIMARY KEY (flag_name, user_id)
);
```

Evaluation priority: `flag_overrides.enabled` (user-specific) → `feature_flags.default_enabled` (global fallback).

---

## Frictions found

### 1. `array_key_exists()` required for boolean field in PATCH

**Severity:** Low (recurring pattern)

PATCH on `default_enabled` must use `array_key_exists('default_enabled', $body)` to distinguish "field absent" from "field = false":

```php
if (!array_key_exists('default_enabled', $body) || !is_bool($body['default_enabled'])) {
    return $this->problems->create(..., 422, ...);
}
```

`isset($body['default_enabled'])` would return `false` when `default_enabled = false`, which is a valid value. This `array_key_exists` vs `isset` distinction for boolean and nullable fields is a recurring pattern (documented in FT21 F-3 PATCH howto).

---

### 2. FK constraint on `flag_overrides.flag_name` works correctly

**Severity:** Low (positive finding)

`flag_overrides.flag_name REFERENCES feature_flags(name)` — SQLite enforces this FK when `PRAGMA foreign_keys = ON`. However, `PdoConnectionFactory` does not enable `PRAGMA foreign_keys` by default for SQLite.

In this FT, the FK is not relied upon for business logic — the repository checks `findByName()` explicitly before inserting an override. The FK constraint is a safety net.

If the FK were the only guard, it would surface as `DatabaseConstraintException` (v1.5.23). But since SQLite FK enforcement is opt-in per connection, relying on it is fragile without explicit PRAGMA setup.

**Implication:** Applications that want SQLite FK enforcement should run `PRAGMA foreign_keys = ON` after connection. This is not documented in NENE2 howtos.

---

### 3. Boolean storage: `INTEGER 0/1` requires explicit cast in hydration

**Severity:** Low

SQLite stores booleans as `INTEGER 0` or `1`. The hydration `(bool) $row['default_enabled']` correctly converts these, but developers need to remember this SQLite-specific behavior. The `CHECK(default_enabled IN (0, 1))` constraint prevents unexpected integer values from being stored.

---

### 4. Upsert for flag overrides (`ON CONFLICT DO UPDATE`) works cleanly

**Severity:** Low (positive finding)

The override upsert uses:
```sql
INSERT INTO flag_overrides (flag_name, user_id, enabled, set_at) VALUES (?, ?, ?, ?)
ON CONFLICT(flag_name, user_id) DO UPDATE SET enabled = excluded.enabled, set_at = excluded.set_at
```

This is identical to the leaderboard upsert (FT76) and works without `DatabaseConstraintException` because the conflict is resolved inline by SQLite. No catch block needed for the upsert case.

---

## Results

| Check | Result |
|-------|--------|
| PHPUnit tests | 20 tests, 29 assertions — OK |
| PHPStan level 8 | No errors (first run) |
| PHP-CS-Fixer | 0 files to fix |

---

## Notes

- Flag names are strings (not UUIDs) — acts as natural key; duplicates return 409 via `DatabaseConstraintException`.
- `evaluate` endpoint enables stateless flag resolution without exposing internal override tables to clients.
- SQLite FK enforcement (PRAGMA) gap could be addressed in a future howto for SQLite FK usage patterns.
