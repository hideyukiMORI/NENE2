# Field Trial 4 Report

**Date:** 2026-05-17
**NENE2 version:** v0.3.0
**Issue:** [#233](https://github.com/hideyukiMORI/NENE2/issues/233)

---

## Trial context

| Item | Notes |
|---|---|
| Sandbox | `/home/xi/docker/nene2-ft4` — fresh directory, no NENE2 source files |
| Starting point | `composer require hideyukimori/nene2:^0.3` |
| PHP runtime | PHP 8.4 via NENE2 Docker image (host PHP is 8.1) |
| Goal | Validate "library consumer" experience distinct from git-clone workflow |

---

## Session summary

### Step 1: Installation

Created `composer.json` with `"hideyukimori/nene2": "^0.3"` and ran `composer install`.

**Result: Installs cleanly.** 16 packages installed (Monolog, Nyholm PSR-7, phpdotenv, PSR interfaces). No errors.

### Step 2: RuntimeContainerFactory path

Copied `public_html/index.php` pattern from NENE2 docs and created `.env` with `DB_ADAPTER=sqlite`. Used `RuntimeContainerFactory(dirname(__DIR__))`.

**Result: Works.** `GET /`, `GET /health`, `GET /examples/ping` all return 200 with correct JSON. Monolog logs appear on stderr with `request_id` in `extra`.

**Friction observed:** `RuntimeServiceProvider` includes `NoteServiceProvider` — the Note CRUD routes (`/examples/notes/*`) are present in the consumer's app without being requested. These are unexpected routes in an external project.

### Step 3: Adding a custom route

Attempted to add a custom `/greet/{name}` route via `RuntimeApplicationFactory`. Not possible: `RuntimeApplicationFactory` is `final readonly` and takes no route-injection parameter. `RuntimeServiceProvider` is also `final`.

**Workaround found:** Bypass `RuntimeApplicationFactory` and directly wire `Router` + `MiddlewareDispatcher` + middleware stack manually (6 classes, ~30 lines).

**Result: Works after manual wiring.** Custom `/health` and `/greet/{name}` endpoints return 200.

### Step 4: Path parameter access

Using `$req->getAttribute('name')` returned empty string. Path parameters are stored under `Router::PARAMETERS_ATTRIBUTE` as an array, not as individual PSR-7 attributes.

**Result: Works after correction.** `$req->getAttribute(Router::PARAMETERS_ATTRIBUTE, [])['name']` returns the correct value.

**Friction observed:** `Router::PARAMETERS_ATTRIBUTE` convention is not documented in `endpoint-scaffold.md` or `client-project-start.md`. A new developer would hit this silently.

---

## Commands run

```text
composer install
# 16 packages installed, including hideyukimori/nene2 v0.3.0

php smoke-test.php
# GET /          → 200  {"name":"NENE2",...}
# GET /health    → 200  {"status":"ok",...}
# GET /examples/ping → 200  {"message":"pong",...}

php custom-route-test.php
# GET /health       → 200  {"status":"ok","app":"ft4"}
# GET /greet/NENE2  → 200  {"message":"Hello, NENE2!"}
# GET /not-found    → 404  Problem Details JSON
```

---

## Outcomes

**What worked well:**

- `composer require` installs cleanly — zero configuration friction
- `RuntimeContainerFactory` + `.env` path works for external project roots
- `Router` + `MiddlewareDispatcher` are independently usable as library components
- Problem Details 404 is returned correctly for unknown routes
- Monolog structured logging with `request_id` works out of the box
- All PSR interfaces are properly separated and accessible

**What was unclear or required discovery:**

1. **Path parameters use `Router::PARAMETERS_ATTRIBUTE`, not direct PSR-7 attributes.** Not documented in `endpoint-scaffold.md` or `client-project-start.md`. A new developer will write `$req->getAttribute('name')` first.

2. **No consumer-facing route extension path.** `RuntimeApplicationFactory` is `final` and not extensible. A consumer who wants custom routes must bypass it and wire `Router` + `MiddlewareDispatcher` manually. No guide exists for this pattern.

3. **`RuntimeServiceProvider` bundles `NoteServiceProvider`.** An external consumer using `RuntimeContainerFactory` gets the Note CRUD endpoints unexpectedly. The `client-project-start.md` guide notes "rename the project boundary" but does not address unwanted example routes.

4. **`FrameworkInfo` hardcodes NENE2 name and description.** `GET /` returns `"name": "NENE2"` in a consumer's app. No override mechanism exists.

5. **No `composer require` path in docs.** `client-project-start.md` describes "start from a clean checkout" — git clone only. A developer who installs via Packagist has no guide.

---

## Follow-up Issues

- **#234** — docs: `endpoint-scaffold.md` に `Router::PARAMETERS_ATTRIBUTE` パスパラメーター取得例を追加する
- **#235** — docs: `client-project-start.md` に `composer require` 起点の最小ワイヤリング手順を追加する
- **#236** — feat: `RuntimeApplicationFactory` に外部ルート注入の仕組みを検討する（Phase 21 候補）

---

## Packagist Field Trial Verdict

`hideyukimori/nene2` は `composer require` でインストールでき、PSR コンポーネントは独立して動作する。ただし「外部消費者がカスタムアプリを素早く立ち上げる」パスはドキュメント化されておらず、発見が必要な状態。#234 と #235 のドキュメント改善で大部分の摩擦は解消できる。
