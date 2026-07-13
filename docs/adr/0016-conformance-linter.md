# ADR 0016: Conformance linter (`tools/conformance.php`)

## Status

accepted

## Context

The 2026-07 backend audit (`_work/reports/2026-07-05/nene-backend-audit.md`) and
the upstreaming design study
(`_work/reports/2026-07-06/upstream-design/04-conformance-linter.md`) found the
same handful of divergences recurring across the fleet: products shipping
hard-coded JWT/development-secret defaults instead of the fail-closed
`GuardedJwtSecretResolver` (ADR 0013), Composer dependencies pinned to unmerged
feature branches, quality gates that never run themselves, and business code
reading the wall clock directly instead of an injected `ClockInterface`. Catching
these by hand on every audit does not scale.

Two constraints shape the response:

- **A distribution channel already exists.** NENE2 ships `tools/validate-*.php`
  validators that consumers invoke as
  `php vendor/hideyukimori/nene2/tools/validate-mcp-tools.php --root=.` from
  `composer check`. Adding one more script reuses that plumbing — no new
  framework, and no PHPStan custom-rule pack (deferred as a later, type-aware
  form).
- **False positives are the failure mode.** A gate that cries wolf gets disabled.
  The audit's own record #16 is the cautionary tale: a static grep declared
  `symfony/http-client` unused when it was pulled in transitively at runtime.
  Any rule that reasons about *absence* of use (dead dependencies, "reinvention"
  of an upstream helper, "no validation here") cannot be made reliable statically.

## Decision

Add **`tools/conformance.php`**, a `--root=`/`getcwd()` CLI matching the existing
validators, backed by unit-testable rule classes under `src/Conformance/`.

Ship **only the four `error`-tier rules** whose detection is high-signal and
low-false-positive, all via token/manifest inspection (never a raw grep, so
comments and docblocks never trip a rule):

- **D1** — hard-coded JWT/default secret literals. Token-scan `src/` for
  `T_CONSTANT_ENCAPSED_STRING` values matching a development-secret shape
  (`*-dev-secret`, `changeme`, `secret-key`, ...), excluding whitespace-bearing
  prose, upper snake-case env-variable *names* (`NENE2_ALLOW_DEV_SECRET`), and
  array-key positions. A literal fed to the fail-closed
  `GuardedJwtSecretResolver`'s dev-secret slot — `fromConfig()`'s second
  argument, or the constructor's `devSecret` parameter (fourth positional, or
  the named argument `devSecret:`, the shape products with a custom secret env
  key use) — directly, or via the constant it initialises in the same file — is
  exempt, since the resolver refuses it in production (this is the design's
  "+ `GuardedJwtSecretResolver` unused" condition, and the canonical 2026-07-05
  fleet fail-close shape). The exemption is narrow: only what actually flows
  into the resolver is exempt — a naked fallback (`getenv('X') ?:
  'acme-dev-secret'`) never reaches it and stays flagged even alongside
  resolver use in the same file.
- **D2** — Composer dependency pinned to a feature branch. Parse `composer.json`
  requires and `composer.lock` package versions; flag `dev-feat/...`-style
  feature-branch pins (mainline `dev-main` / `@dev` are the design's `warn`
  tier and are not flagged here).
- **D3** — the linter is not wired into `composer check`. Parse `scripts.check`
  and require it to invoke `@conformance`, making the tool self-enforcing.
- **D4** — raw current-time reads. Token-scan `src/` for global
  `time()`/`microtime()`, single-argument `date()`/`gmdate()`, and
  `new DateTime[Immutable]('now')`, excluding `ClockInterface` implementations
  (the sanctioned clock) and `date()` calls given an explicit timestamp
  (formatting, not a clock read).

Rules that the design classified as `warn`/`info` (getenv, installer/pagination
reinvention, dead dependencies, "no validation") are **deliberately excluded** —
record #16's lesson is institutionalised: dead-dependency and absence-of-use
checks stay off the gate.

Gradual adoption and false-positive relief use three mechanisms:

- **`conformance.baseline.json`** (PHPStan-baseline-shaped) with two arrays:
  a machine-generated `ignore` snapshot (matched by `rule + file + message`, so
  it survives line shifts; regenerated with `--write-baseline`) that captures
  existing drift so only *new* findings fail, and a curated `allow` allowlist
  where **each entry requires a non-empty `reason`** (a missing reason is a hard
  configuration error) so exceptions stay auditable.
- **Inline `// conformance:ignore <rule> <reason>`** for one-off local exceptions.

**2026-07-14 addendum — R-series (README/docs conformance).** Design doc 04's
D5–D12 range is reserved for backend-only drift (getenv, Installer/Pagination
reinvention, ...), so README/docs drift — a distinct axis, first raised by
workspace issue `_work/issues.md#44` ("README統一の後続") and the
`nene-status-badges-unreliable` observation that static maturity badges drift
from reality — gets its own **R1/R2** sequence instead of continuing D-numbering.
Both scan `README.md` only (no findings when it is absent, matching the
tolerant convention `ProjectFiles` already uses for source-scanning rules):
**R1** (`error`) flags a static shields.io `status-`/`phase-`-prefixed badge
(`.../badge/status-...`) — deliberately narrower than a generic badge scan so it
never trips on CI/License/PHP-version/Packagist badges, which are dynamic or
effectively invariant; maturity belongs in a `## Status` section instead.
**R2** (`warn`) flags a missing `## License` section — a license badge alone is
a thin, easy-to-leave-stale pointer, so a heading is recommended, not required.

**2026-07-14 addendum — R3 (private `nene-origin` link, warn).** Workspace
issue `_work/issues.md#44①` found that a public product README can link/path
into the private `nene-origin` repo (e.g. its port registry
`nene-origin/docs/development/local-ports.md`), which 404s for external readers
who have no access to it; the correct home for that content is the product's
own `AGENTS.md`/`CLAUDE.md` port section. **R3** (`warn`) word-bounded-scans
`README.md` for the repo slug `nene-origin` (path, `github.com/...` URL, or
markdown link target). The fleet is already clean, so R3 is a regression guard,
not a fix for an active violation — hence `warn`, not `error`.

## Consequences

- D1 permanently locks in the ADR 0013 fix: a re-introduced default secret fails
  CI. D2 mechanically catches supply-chain drift (the corpus feature-branch pins).
  D3 makes the linter self-sustaining. D4 protects clock determinism (ADR 0009's
  `ClockInterface`).
- NENE2 applies the linter to itself via `composer check`. Its three framework
  time primitives (`ThrottleMiddleware`, `InMemoryRateLimitStorage`,
  `TotpAuthenticator`) are recorded in `conformance.baseline.json` as
  reason-carrying `allow` entries; the D1 detector's own pattern literal uses an
  inline ignore. No other findings.
- Fan-out to the other products (report-only baseline capture, then CI gating of
  new drift) is a separate wave, as is the deferred PHPStan type-aware pack and
  the `warn`-tier rules, which follow each upstream interface as it freezes.
- R1/R2/R3 apply to NENE2's own `README.md` too: no static status badge, a
  `## License` section already present, and no `nene-origin` reference, so all
  three are green with zero new baseline entries.

## Related

- Issue: `#1511`
- PR: `#1512`
- Fan-out fixes (fleet CI pilot, 2026-07-07): `#1514` — consumer autoload path
  (the CLI must require the resolved consumer `vendor/autoload.php`, matching
  `tools/validate-mcp-tools.php`, not NENE2's own nested vendor) and the D1
  `GuardedJwtSecretResolver` guarded-literal exemption above.
- R-series addendum (README/docs conformance, 2026-07-14): Issue `#1551`, PR `#1552`.
- R3 addendum (private `nene-origin` link, warn, 2026-07-14): Issue `#1555`, PR `#1556`.
- Supersedes: none
- Superseded by: none
