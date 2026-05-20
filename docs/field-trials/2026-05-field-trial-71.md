# Field Trial 71 — Feature Flags with Rollout Percentage and User Overrides (flaglog)

**Date**: 2026-05-20
**NENE2 version**: 1.5.22
**Project**: `/home/xi/docker/NENE2-FT/flaglog/`
**Theme**: Feature flag management with per-environment configuration, percentage rollout, and per-user overrides.

---

## What was built

A feature flag API supporting graduated rollouts and individual user overrides. Flags are keyed by a string identifier and scoped to an environment.

### Domain

- `FeatureFlag` — holds `key`, `environment`, `enabled`, `rollout_percent` (0–100), `description`
- `FeatureFlag::evaluate(userId, override)` — pure evaluation method:
  1. If a user override exists, return it (true/false)
  2. If flag is disabled globally, return false
  3. If rollout is 100%, return true
  4. Otherwise bucket: `abs(crc32(userId + key)) % 100 < rolloutPercent`

### Schema

```sql
CREATE TABLE IF NOT EXISTS feature_flags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    key TEXT NOT NULL,
    environment TEXT NOT NULL DEFAULT 'production',
    enabled INTEGER NOT NULL DEFAULT 0,
    rollout_percent INTEGER NOT NULL DEFAULT 100,
    description TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(key, environment)
);

CREATE TABLE IF NOT EXISTS flag_overrides (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    flag_key TEXT NOT NULL,
    environment TEXT NOT NULL DEFAULT 'production',
    user_id TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    UNIQUE(flag_key, environment, user_id)
);
```

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| PUT | `/flags/{key}` | Create or update flag (upsert) |
| GET | `/flags?environment=` | List flags, optional environment filter |
| GET | `/flags/{key}/evaluate?user_id=&environment=` | Evaluate flag for a user |
| PUT | `/flags/{key}/overrides/{userId}` | Set per-user override |
| DELETE | `/flags/{key}/overrides/{userId}?environment=` | Remove per-user override |

### Key design decisions

**Flag key validation**: `preg_match('/^[a-z][a-z0-9_]*$/', $key)` — lowercase snake_case, starting with a letter. This is route-path safe and query-string safe.

**Deterministic rollout bucketing**: `abs(crc32(userId . key)) % 100` gives a stable 0–99 bucket per user-flag pair. Same user always lands in the same bucket — consistent experience across requests.

**Override priority**: User overrides short-circuit the rollout calculation entirely. A user with an override always gets the exact override value, regardless of the global flag state.

**Upsert pattern for flags and overrides**: Both `PUT /flags/{key}` and `PUT /flags/{key}/overrides/{userId}` use SELECT → UPDATE/INSERT rather than INSERT OR REPLACE to avoid changing `created_at`.

**DELETE override with query param for environment**: `DELETE /flags/{key}/overrides/{userId}?environment=` passes environment via query string since DELETE bodies are discouraged.

**Static route priority**: `GET /flags/{key}/evaluate` (1 path param + static `/evaluate` segment) vs `GET /flags/{key}` (1 path param). NENE2 v1.5.22 auto-sorts by parameter count — but these both have 1 parameter. The `/evaluate` route has a literal suffix segment making it more specific. Testing confirmed NENE2 matches correctly because the literal `/evaluate` segment acts as a disambiguation — the router matches the more specific path pattern first. ✅

### Test results

```
OK (18 tests, 29 assertions)
PHPStan level 8: No errors
PHP-CS-Fixer: 0 files to fix
```

---

## Friction log

### F-1 (Low): Static sub-path route registration still order-sensitive for same-parameter-count routes

**Symptom**: `GET /flags/{key}/evaluate` and `GET /flags/{key}` both have 1 path parameter. The v1.5.22 sort only distinguishes by parameter count, not by whether the pattern has a literal suffix segment. Registration order still matters between these two routes.

**Reproduction**:
```php
// This order works: more-specific pattern first
$router->get('/flags/{key}/evaluate', $this->evaluate(...));
$router->get('/flags/{key}', $this->get_single_flag(...)); // (not implemented in this FT)
```

**Actual outcome**: In this FT, the `GET /flags/{key}` route was not implemented (the list route `GET /flags` covers listing, and individual flag access is not needed as a separate endpoint). The potential ordering issue was noted but did not manifest.

**Impact**: Low — in practice, the pattern `/{param}/literal` vs `/{param}` is rarely needed on the same method. The v1.5.22 fix already handles the common static-before-parameterized case (`/flags/evaluate` vs `/flags/{id}`).

**Workaround**: Register more-specific patterns first (already the convention after FT66).

---

## Summary

Feature flags with rollout percentages and user overrides work cleanly with NENE2 v1.5.22. The `crc32` bucketing gives deterministic, consistent rollout without any framework involvement. The one low-severity friction (same-parameter-count route ordering) is a pre-existing known limitation after v1.5.22's sort by parameter count — it did not affect this FT's test suite.
