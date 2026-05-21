# Field Trial 136 — Access Token Management (tokenlog)

**Date**: 2026-05-21  
**Theme**: パーソナルアクセストークン管理 — 発行・一覧・失効・検証  
**Project**: `NENE2-FT/tokenlog/`  
**NENE2 version**: ^1.5 (released as v1.5.70)  
**Issue**: #776  
**Special**: クラッカー攻撃試験（4FT ごとの試験対象 — FT136）

---

## Overview

This trial implements a personal access token (PAT) system where users issue tokens with scopes (`read`/`write`/`admin`), list their active/revoked tokens, and revoke individual tokens. A public verify endpoint returns valid/invalid status without revealing token existence for unknown tokens.

---

## Implementation Summary

### Schema

Two tables: `users` and `tokens`. Tokens store only the SHA-256 hash of the raw token; the raw value is never persisted.

- `token_hash TEXT NOT NULL UNIQUE` — prevents hash collisions
- `revoked_at TEXT` — nullable; NULL = active
- `CHECK (scope IN ('read', 'write', 'admin'))` — DB-level scope constraint

### API

| Method | Path                               | Status codes      |
|--------|------------------------------------|-------------------|
| POST   | `/users`                           | 201, 422          |
| POST   | `/users/{userId}/tokens`           | 201, 403, 404, 422 |
| GET    | `/users/{userId}/tokens`           | 200, 403, 404     |
| DELETE | `/users/{userId}/tokens/{tokenId}` | 204, 403, 404, 409 |
| POST   | `/tokens/verify`                   | 200, 422          |

### Key design choices

**Token hashing** — `bin2hex(random_bytes(32))` generates the 64-character raw token. Only `hash('sha256', $raw)` is stored. The raw token is returned once at issue time and cannot be recovered.

**TokenScope enum** — `TokenScope::tryFrom($value)` validates scope before DB insertion. Invalid scopes → 422.

**Verify always returns 200** — Unknown and revoked tokens both return `{ "valid": false }` with 200 OK, not 404. This prevents token existence enumeration.

**409 for double-revoke** — `UPDATE ... WHERE revoked_at IS NULL` is a no-op for already-revoked tokens. The handler maps 0 affected rows → 409 Conflict.

**Double ownership check on revoke** — First check: actor matches the userId in the URL. Second check: the tokenId's `user_id` matches userId. This closes ATK-04 (Bob using his own userId path to revoke Alice's token ID).

---

## Bug Found and Fixed

**PHPStan level 8: redundant null comparison after isset()**

Initial code used `isset($arr['revoked_at']) && $arr['revoked_at'] !== null` in hydration methods. PHPStan level 8 correctly identifies that after `isset()` returns true, null is already eliminated from the type — `!== null` is always true.

**Fix**: Changed all nullable timestamp checks to `isset($arr['revoked_at'])` alone.

---

## Test Results

```
29 tests, 70 assertions — all pass
17 normal tests + 12 attack tests
```

---

## Cracker Attack Test (FT136)

### Methodology

Twelve adversarial tests in `tests/Token/AttackTest.php`. Each test sends a crafted request representing a realistic attack vector.

### Findings

| ID | Attack | Description | Expected | Result |
|----|--------|-------------|----------|--------|
| ATK-01 | IDOR | Issue token for another user | 403 | **Pass** |
| ATK-02 | IDOR | List another user's tokens | 403 | **Pass** |
| ATK-03 | IDOR | Revoke token via victim's path | 403 | **Pass** |
| ATK-04 | IDOR | Revoke victim's token via attacker's own path | 403 | **Pass** |
| ATK-05 | Scope escalation | Use `superuser` scope | 422 | **Pass** |
| ATK-06 | Token replay | Use revoked token for verify | valid=false | **Pass** |
| ATK-07 | Brute-force | Random 64-char token | valid=false | **Pass** |
| ATK-08 | SQL injection | `' OR '1'='1` in verify body | valid=false | **Pass** |
| ATK-09 | Header injection | `X-User-Id: admin` | not 201 | **Pass** |
| ATK-10 | Path injection | Negative user ID | 404 | **Pass** |
| ATK-11 | Resource exhaustion | 10KB scope string | 422 | **Pass** |
| ATK-12 | Empty input | Whitespace-only token | 422 | **Pass** |

**Overall: No vulnerabilities found.** All 12 attack tests pass without code changes.

### Notable findings

- **ATK-04 (double IDOR)**: This is the most subtle attack — an attacker uses their own `userId` in the URL path but supplies another user's `tokenId`. The system correctly rejects this via the second ownership check (`token['user_id'] !== $userId`).
- **ATK-08 (SQL injection)**: `' OR '1'='1` is hashed with SHA-256 before the DB query — the resulting hash is unlikely to match any stored token. Prepared statements provide the primary protection.
- **ATK-11 (10KB scope)**: `TokenScope::tryFrom()` returns `null` for any string not exactly matching 'read', 'write', or 'admin' — length doesn't matter; unknown values are always rejected.

---

## Developer Experience (DX) Review

### Persona 1: Junko — Junior PHP Developer (2 years experience)

*"I learned that tokens should never be stored in plaintext — use SHA-256 hash. The howto makes this clear. The 'return token once at issue time' pattern is a concept I hadn't seen before. I also tripped on `isset($x) && $x !== null` — I thought that was more explicit, but PHPStan said it was redundant. Now I understand."*

**Rating**: ★★★★☆ (4/5)  
**Learning**: One-time token return pattern, PHPStan null-after-isset semantics

---

### Persona 2: Tariq — Mid-level Backend Developer (5 years, first time with NENE2)

*"The double ownership check on revoke (URL user match + token user match) is exactly right. I've seen APIs that only check the URL and have ATK-04 style bugs. The 409 for double-revoke is clean — better than silently returning 204 again. TokenScope enum makes the API self-documenting."*

**Rating**: ★★★★★ (5/5)  
**Highlight**: Double ownership check is a pattern I'll reuse

---

### Persona 3: Priya — Senior Developer / Tech Lead

*"SHA-256 is fast — a motivated attacker with a leaked DB can brute-force common tokens quickly. Production should use Argon2id or bcrypt for token hashing, or use opaque tokens long enough (256 bits) that brute-force is impractical. For a FT, SHA-256 is fine. I also note `bin2hex(random_bytes(32))` generates 256 bits of entropy — good."*

**Rating**: ★★★★☆ (4/5)  
**Note**: SHA-256 for token hashing is acceptable; production may want Argon2id for extra protection

---

### Persona 4: Markus — DevOps / Platform Engineer

*"Revoked tokens stay in the table — no cleanup. In production, old revoked tokens accumulate. Need a purge job (e.g., `DELETE FROM tokens WHERE revoked_at < NOW() - INTERVAL 90 DAY`). The `token_hash UNIQUE` constraint means a hash collision (SHA-256) would reject a valid new token — astronomically unlikely but worth noting."*

**Rating**: ★★★★☆ (4/5)  
**Gap**: No token expiry or cleanup for revoked tokens

---

### Persona 5: Amara — QA / Test Engineer

*"12 attack tests, 29 total. ATK-04 is particularly tricky — I'd have missed it in a normal test pass. The double ownership check pattern should be in a checklist. The PHPStan bug (isset + !== null) was caught by static analysis before tests ran — good defense. Missing: token expiry (created_at + TTL validation)."*

**Rating**: ★★★★★ (5/5)  
**Highlight**: ATK-04 double IDOR caught by attack test, not regular tests

---

### Persona 6: Keiko — Non-technical Product Manager

*"This is like GitHub personal access tokens — I use those! The token is shown once, you save it, you can see labels and revoke. Clear. My question: can I set an expiry date? GitHub PATs have expiry options now. Also: can I see the last-used time? That would help me decide which tokens to revoke."*

**Rating**: ★★★☆☆ (3/5)  
**Feature requests**: Token expiry, last-used timestamp — expected gaps at FT scale

---

### DX Summary

| Persona | Rating | Primary concern |
|---------|--------|-----------------|
| Junko (Junior PHP) | ★★★★☆ | One-time token, isset+null PHPStan semantics |
| Tariq (Mid backend) | ★★★★★ | Double ownership check pattern is excellent |
| Priya (Tech Lead) | ★★★★☆ | SHA-256 vs Argon2id for token hashing |
| Markus (DevOps) | ★★★★☆ | No token expiry or revoked token cleanup |
| Amara (QA) | ★★★★★ | ATK-04 double IDOR only caught by attack test |
| Keiko (PM) | ★★★☆☆ | No expiry or last-used timestamp |

**Overall DX**: ★★★★☆ (4.2/5) — Token security posture is solid. The double ownership check and one-time token return are well-implemented patterns. Gaps are operational (expiry, cleanup) rather than security.

---

## Issues Raised for NENE2

None. `TokenScope::tryFrom()`, `isset()` semantics, and PHPStan level 8 all behaved as expected.

---

## Howto

`docs/howto/access-token-management.md`
