# How-to: Feature Flag API

> **FT reference**: FT313 (`NENE2-FT/flaglog`) — Feature flag management: per-environment flags, rollout_percent gradual rollout, per-user overrides, evaluate endpoint with override resolution, snake_case key validation, 18 tests / 29 assertions PASS.

This guide shows how to build a feature flag system that supports per-environment configuration, gradual rollouts by percentage, and per-user overrides.

## Schema

```sql
CREATE TABLE feature_flags (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    key             TEXT    NOT NULL,
    environment     TEXT    NOT NULL DEFAULT 'production',
    enabled         INTEGER NOT NULL DEFAULT 0,
    rollout_percent INTEGER NOT NULL DEFAULT 100,
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL,
    UNIQUE (key, environment)
);

CREATE TABLE flag_overrides (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    flag_key   TEXT    NOT NULL,
    environment TEXT   NOT NULL DEFAULT 'production',
    user_id    TEXT    NOT NULL,
    enabled    INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (flag_key, environment, user_id)
);
```

`key` must match `^[a-z][a-z0-9_]*$` (snake_case). `rollout_percent` is 0–100.

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `PUT` | `/flags/{key}` | Create or update a flag |
| `GET` | `/flags` | List all flags (optional `?environment=`) |
| `GET` | `/flags/{key}/evaluate` | Evaluate flag for a user (`?user_id=`) |
| `PUT` | `/flags/{key}/overrides/{userId}` | Set per-user override |
| `DELETE` | `/flags/{key}/overrides/{userId}` | Remove per-user override |

## Flag Upsert — PUT /flags/{key}

```php
// Request body
{
    "enabled": true,
    "rollout_percent": 50,   // optional, default 100
    "environment": "staging" // optional, default "production"
}

// Response 200
{
    "key": "dark_mode",
    "enabled": true,
    "rollout_percent": 50,
    "environment": "staging",
    "created_at": "...",
    "updated_at": "..."
}
```

The same endpoint creates or updates (UPSERT by `key + environment`). Sending `PUT` twice with different values updates the flag.

## Key Validation

```php
// Valid keys (snake_case: a-z, 0-9, underscore, starts with letter)
dark_mode, beta_ui, new_feature_v2

// Invalid — returns 422
Dark-Mode   // uppercase + hyphen
123flag     // starts with digit
my flag     // space
```

```php
if (!preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
    throw new ValidationException([
        ['field' => 'key', 'message' => 'Key must be snake_case.', 'code' => 'invalid-format'],
    ]);
}
```

## Rollout Percent Validation

```php
if ($rolloutPercent < 0 || $rolloutPercent > 100) {
    throw new ValidationException([
        ['field' => 'rollout_percent', 'message' => 'Must be 0–100.', 'code' => 'out-of-range'],
    ]);
}
```

## Per-Environment Flags

```php
// Same key, different environments
PUT /flags/beta_ui  {"enabled": true,  "environment": "staging"}
PUT /flags/beta_ui  {"enabled": false, "environment": "production"}

// List by environment
GET /flags?environment=staging     → [{"key": "beta_ui", "enabled": true, ...}]
GET /flags?environment=production  → [{"key": "beta_ui", "enabled": false, ...}]
```

## Evaluate — Rollout + Override

```
GET /flags/{key}/evaluate?user_id={userId}
```

Resolution order:
1. **Override wins**: if a `flag_overrides` row exists for `(key, environment, user_id)` → use override value
2. **Flag disabled**: if `enabled = false` → return `false` regardless of rollout
3. **Rollout check**: hash `user_id` deterministically → compare to `rollout_percent`

```php
// 1. Check override
$override = $this->repo->findOverride($key, $environment, $userId);
if ($override !== null) {
    return new EvaluateResult(enabled: $override->enabled, override: $override->enabled);
}

// 2. Flag disabled
if (!$flag->enabled) {
    return new EvaluateResult(enabled: false, override: null);
}

// 3. Rollout percent
$hash = abs(crc32($userId)) % 100;
$enabled = $hash < $flag->rolloutPercent;
return new EvaluateResult(enabled: $enabled, override: null);
```

Response:
```json
{"enabled": true, "override": null}   // rollout decision
{"enabled": true, "override": true}   // override enabled
{"enabled": false, "override": false} // override disabled
```

## Per-User Overrides

```php
// Enable for a specific user (even if flag is off / rollout 0%)
PUT /flags/beta_feature/overrides/alice  {"enabled": true}

// Disable for a specific user (even if flag is on / rollout 100%)
PUT /flags/global_flag/overrides/bob  {"enabled": false}

// Remove override — reverts to global flag + rollout logic
DELETE /flags/my_flag/overrides/alice
```

Override requires `enabled` field (boolean). Missing field → 422.
Override on non-existent flag → 404.
Delete non-existent override → 404.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Allow arbitrary key format (e.g. hyphens, uppercase) | Inconsistent keys across teams; hard to grep/reference in code |
| Rollout percent > 100 | Logic error; 110% rollout means always enabled even when intended as gradual |
| No environment separation | staging flags bleed into production; canary deploys break |
| Evaluate without `user_id` check | `crc32(null)` or empty string gives deterministic but wrong bucketing |
| Return 200 for evaluate on missing flag | Caller assumes flag exists; silently treats as disabled instead of raising alert |
| Global flag state in memory/cache without TTL | Stale flags after rollout percent change; changes don't propagate |
