# ADR 0013: Guarded JWT Secret Resolution

## Status

accepted

## Context

The HMAC secret used by `LocalBearerTokenVerifier` signs and verifies every operator
and service bearer token. A predictable value is therefore a full authentication
bypass: an attacker who knows the secret can forge a superadmin token. The historical
failure mode was a **silent fallback** to a public, guessable development constant when
the secret was unset — safe locally, catastrophic if it ever shipped to production.

Every downstream product (nene-suite, nene-invoice, nene-field, and ~9 others) had, by
mid-2026, grown its own copy of a "resolve the JWT secret, fail closed in production"
guard. Two shapes emerged:

- **Product shape** (e.g. `nene-invoice`): reject only when `APP_ENV === production` and
  the secret is unset; fall back to a per-product development constant otherwise.
- **Best shape** (`nene-suite`): fall back to the development secret **only** behind an
  explicit `NENE_SUITE_ALLOW_DEV_SECRET` opt-in; otherwise throw in every environment.

Duplicating this security-critical logic per product is a P0 maintenance and
consistency risk: a bug fixed in one copy is not fixed in the others, and the weaker
(production-only) shape silently permits the dev secret in any non-production `APP_ENV`
without an operator opt-in. NENE2 is the correct home for the canonical guard.

### Requirements

- A single, framework-default public API that all products can converge on.
- Fail closed: a misconfiguration must throw, never sign with a guessable secret.
- Preserve the existing per-product development-secret **separation** — each product
  uses its own dev secret so a token minted in one product's dev environment is not
  accepted by another. The framework must not own or share a dev secret.
- Respect the NENE2 rule that raw environment access lives only inside `ConfigLoader`;
  application code (including the resolver) receives typed config.

## Decision

Add `Nene2\Auth\GuardedJwtSecretResolver` (`final readonly`) and
`Nene2\Auth\JwtSecretException` (`extends RuntimeException`) as **public, stable** API
(added to the ADR 0009 surface). The resolver applies a **hybrid development-secret
allowance model**:

1. If the configured secret is **non-empty**, it is used in **every** environment
   (an empty string is treated as "unset").
2. Otherwise, in `AppEnvironment::Production`, resolution **always throws** — the
   development-secret opt-in is intentionally ignored, so production can never sign
   with a development secret (hard fail).
3. Otherwise (`local` / `test`), when the operator has **explicitly opted in**
   (`NENE2_ALLOW_DEV_SECRET`, parsed strictly as `1` / `true` / `yes`) **and** the
   product injected a non-empty development secret, that development secret is used.
4. Otherwise resolution throws `JwtSecretException`.

**The development secret is injected by the product**, not owned by the framework:
`GuardedJwtSecretResolver` takes `?string $devSecret`, where `null` (or empty) disables
the development path entirely. This preserves the existing per-product dev-secret
separation.

**The opt-in flows through typed config.** `ConfigLoader` reads `NENE2_ALLOW_DEV_SECRET`
and parses it with a strict truthy check (only `1` / `true` / `yes`; never throws — any
other value, including a typo, means "opted out"). The parsed boolean is exposed as the
new `AppConfig::$allowDevSecret` field. The resolver is pure — it never reads the
environment. A `GuardedJwtSecretResolver::fromConfig(AppConfig $config, ?string $devSecret): string`
convenience method covers the common `NENE2_LOCAL_JWT_SECRET` path in one call.

The env-name constructor parameters (`secretEnvName`, `optInEnvName`) exist only to
build actionable exception messages, so a product reading the secret under a custom key
(e.g. a serve command's `NENE_SERVE_JWT_SECRET`) can surface the correct variable name.

This PR adds the framework surface only. **Migrating products to remove their own
guards is deliberately out of scope and will be a follow-up PR per product.**

### Rejected alternatives

| Alternative | Why rejected |
|-------------|--------------|
| **`APP_ENV`-only gate (product shape)**: allow the dev secret in any non-production environment without an opt-in | Silently permits the guessable dev secret whenever `APP_ENV` is not exactly `production` (e.g. an unset or mistyped `APP_ENV`). An explicit opt-in is a second, intentional barrier. |
| **Opt-in only, no hard production block**: honour `NENE2_ALLOW_DEV_SECRET` even in production | An operator who sets the opt-in for local work and later promotes the same env file to production would sign production tokens with a public secret. Production must fail closed unconditionally. |
| **Framework owns a shared dev secret constant** | Breaks the existing per-product separation — a dev token from product A would verify against product B. Products inject their own secret. |
| **Resolver reads `getenv()` directly** | Violates the NENE2 boundary rule (raw env only in `ConfigLoader`) and makes the resolver impure and hard to test. The opt-in flows through `AppConfig`. |

The hybrid model is the intersection of the two production shapes: it keeps the
best-shape opt-in **and** adds an unconditional production block, which neither existing
shape had on its own.

## Consequences

**Benefits**

- One audited, tested implementation of a full-auth-bypass-class guard, replacing
  ~11 divergent copies.
- Production can never sign with a development secret, regardless of opt-in state.
- The per-product dev-secret separation is preserved (product-injected secret).
- The opt-in is typed (`AppConfig::$allowDevSecret`), keeping raw env access in
  `ConfigLoader` only.

**Costs / follow-up**

- `AppConfig` gains a constructor parameter. It is appended with a default (`false`),
  so existing positional and named construction is unaffected (non-breaking).
- Products must migrate to the shared resolver in follow-up PRs (one per product) and
  delete their own guards. Until then, both coexist with identical behaviour.
- `GuardedJwtSecretResolver` and `JwtSecretException` join the ADR 0009 stability
  surface and cannot break without a major version bump.

## Related

- Issue: `#1490`
- See also: ADR 0008 (JWT authentication), ADR 0009 (public API scope — updated),
  `CHANGELOG.md`, `docs/reference/environment-variables.md`
- Reference implementations: `nene-suite/src/Http/JwtSecretResolver.php` (opt-in shape),
  `nene-invoice/src/Auth/AuthServiceProvider.php` (product shape)
- Supersedes: none
- Superseded by: none
