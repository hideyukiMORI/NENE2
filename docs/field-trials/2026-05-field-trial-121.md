# Field Trial Report — FT121: Feature Flags

**Date**: 2026-05-21
**Release**: v1.5.55
**App**: `featureflaglog` (`/home/xi/docker/NENE2-FT/featureflaglog/`)
**Tests**: 21/21 passed
**PHPStan**: level 8, 0 errors
**CS**: clean

## Theme

Implement a DB-backed feature flag system with global enable/disable, rollout percentage, and per-user/tenant targeting with override priority.

## Core design

### Evaluation priority chain

Priority (highest wins):
1. User-level target override (`enabled = true/false`)
2. Tenant-level target override
3. `globally_enabled = 1`
4. `rollout_pct > 0` with deterministic hash bucket
5. Default: `false`

User-level targets act as both early-access grants and kill switches — `enabled = false` blocks a specific user even when the flag is globally on.

### Rollout percentage — deterministic bucket

```php
$bucket = abs(crc32($userId . '.' . $flag->name)) % 100;
return $bucket < $flag->rolloutPct;
```

Stable per (user, flag) pair. The flag name is included in the hash seed so different flags roll out to different users at the same percentage.

### Schema

Two tables: `feature_flags` (name UNIQUE, globally_enabled, rollout_pct) and `flag_targets` (UNIQUE on flag_id + target_type + target_id).

## Test coverage (21 tests)

| Category | Tests |
|---|---|
| Create flag (valid, missing name, duplicate) | 3 |
| Get flag (found, not found) | 2 |
| Toggle globally_enabled (on, off) | 2 |
| Set rollout_pct (valid, out-of-range) | 2 |
| Upsert target (user target, invalid type) | 2 |
| Delete target | 1 |
| Evaluate: disabled flag → false | 1 |
| Evaluate: globally enabled → true | 1 |
| Evaluate: user target overrides global disable | 1 |
| Evaluate: user kill switch overrides global enable | 1 |
| Evaluate: tenant target applied | 1 |
| Evaluate: rollout 100% → true | 1 |
| Evaluate: rollout 0% → false | 1 |
| Evaluate: missing user_id → 422 | 1 |
| Evaluate: non-existent flag → 404 | 1 |

## DX observations

- Separating `globally_enabled` and `rollout_pct` into distinct fields keeps the evaluation logic explicit — no overloaded semantics.
- Upsert pattern for targets avoids client-side check-then-set races and keeps the API idempotent.
- The `FlagEvaluator` class is pure (no DB, no HTTP) and independently testable, though this FT tested it through the full HTTP stack.
