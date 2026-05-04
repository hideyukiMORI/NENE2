# First Task Instruction: HTTP Runtime Foundation

Date: 2026-05-04

Purpose: 次チャットで最初に着手する実装タスクの指示書。

## Goal

NENE2 を docs-only foundation から、最小 HTTP runtime skeleton が動く状態へ進める。

最初の成果は、現在の smoke endpoint を PSR-first HTTP runtime 経由に移行すること。

## Start Conditions

作業開始前に確認すること:

- `git status --short --branch` が clean であること
- `main` が `origin/main` と同期していること
- `docs/todo/handoff-2026-05-04-implementation-start.md` を読んでいること
- `docs/development/package-selection.md` を読んでいること
- `docs/development/adr.md` を読んでいること
- `docs/review/docs-policy.md` と `docs/review/backend-api.md` を確認すること

## Issue and Branch

最初に GitHub Issue を作る。

推奨 Issue title:

```text
feat: HTTP runtime foundation を実装する
```

Issue body に含めること:

- PSR-7 / PSR-17 implementation selection
- router strategy
- middleware dispatcher / response emitter minimum
- ADR 0001 creation
- smoke endpoint migration
- PHPUnit / PHPStan verification

Branch name example:

```text
feat/{issue-number}-http-runtime-foundation
```

## Required ADR

この作業では ADR を必ず作る。

Suggested path:

```text
docs/adr/0001-select-http-runtime-packages.md
```

ADR must cover:

- selected PSR-7 / PSR-17 package
- selected router package or internal router strategy
- middleware dispatcher approach
- response emitter approach
- alternatives considered
- why the chosen set fits NENE2

Use `docs/adr/0000-template.md`.

## Package Selection

Evaluate candidates using `docs/development/package-selection.md`.

Recommended comparison areas:

- standards compliance
- active maintenance
- dependency footprint
- runtime weight
- testability
- static analysis friendliness
- documentation quality
- replacement strategy

Do not choose a heavy framework runtime as the default HTTP foundation.

## Likely Implementation Scope

Keep the first implementation small.

Expected implementation areas:

```text
src/Http/
src/Routing/
src/Middleware/
src/Error/
public_html/index.php
tests/
composer.json
composer.lock
docs/adr/
docs/todo/current.md
```

Possible first classes or concepts:

- server request factory bootstrap
- response factory helper
- simple route table
- route result or matched handler
- middleware dispatcher
- response emitter
- Problem Details error mapping placeholder

Only add abstractions that are needed to move the smoke endpoint behind the runtime.

## Smoke Endpoint Target

Current behavior should remain simple:

- `GET /` returns JSON smoke response
- `public_html/openapi.php` continues serving OpenAPI YAML
- `public_html/docs/` continues loading Swagger UI

The goal is not to build a full framework in one PR. The goal is to prove the runtime path:

```text
front controller
-> PSR-7 request
-> middleware / error boundary
-> router / handler
-> PSR-7 response
-> emitter
```

## Non-Goals

Do not include in the first runtime PR unless absolutely necessary:

- full controller resolver
- attribute routing
- autowiring
- full DI container integration
- runtime OpenAPI validation
- authentication system
- rate limiting
- frontend starter
- database adapter

## Verification

Run:

```bash
docker compose run --rm app composer check
git diff --check
```

If new Composer dependencies are added, also run:

```bash
docker compose run --rm app composer validate
```

If Docker is unavailable, report that clearly and include the commands for hide to run.

## Self-Review

Before PR:

- `docs/review/docs-policy.md`
- `docs/review/backend-api.md`
- `docs/review/middleware-security.md` if middleware behavior changes

PR body should include:

```text
## Self-review
- `docs/review/docs-policy.md`
- `docs/review/backend-api.md`
```

## Completion Criteria

The task is complete when:

- Issue is created and linked
- implementation branch is used
- ADR 0001 is added
- selected packages are documented
- smoke endpoint works through the new runtime
- tests are added or updated
- `composer check` passes
- PR is created, merged, and Issue is closed
- local `main` is clean and synced
