# Handoff: NENE2 Implementation Start

Date: 2026-05-04

Purpose: 次のチャットで NENE2 の実装フェーズを開始するための引継ぎ。

## Current State

NENE2 は docs-first の foundation / implementation readiness 準備を完了済み。

現在の `main` は `origin/main` と同期済みで、未コミット差分なしの状態から次作業を開始できる。

## Completed Foundation

完了済みの主要方針:

- project governance / Issue-driven workflow
- standard project layout
- PHP runtime / Composer / PHPUnit / PHPStan
- Docker development runtime
- OpenAPI / Swagger UI
- thin HTML view policy
- database migration layout policy
- PSR-first HTTP runtime policy
- PSR-11 DI policy
- typed configuration policy
- RFC 9457 Problem Details policy
- middleware / security baseline policy
- quality tools and documentation comments policy
- request validation policy
- frontend integration and npm / Node LTS policy
- release / versioning / CI policy
- ADR operation policy
- logging / observability policy
- self-review checklist policy
- implementation readiness guardrails

## Important Latest Decisions

- `https://nene2.dev/problems/{problem-name}` is the canonical Problem Details type URI pattern.
- `nene2.dev` has been acquired by hide and is being configured.
- Public source-of-truth docs, API contracts, OpenAPI text, and public error metadata should be English.
- Cursor rules, local TODO / handoff notes, and AI collaboration notes may use Japanese.
- Concrete package selections must create ADRs in the same PR unless the work is explicitly investigative only.
- Self-review checklists in `docs/review/` should be checked before push / PR.

## Read First

次チャットのリナは、作業開始前にこの順で読むこと。

1. `docs/todo/current.md`
2. `docs/todo/first-task-2026-05-04-http-runtime-foundation.md`
3. `docs/development/package-selection.md`
4. `docs/development/adr.md`
5. `docs/development/http-runtime.md`
6. `docs/development/dependency-injection.md`
7. `docs/development/request-validation.md`
8. `docs/development/api-error-responses.md`
9. `docs/review/docs-policy.md`
10. `docs/review/backend-api.md`

## Next Recommended Work

次は `HTTP runtime foundation` に入る。

最初の作業は、実装前に以下をセットで扱うこと。

- GitHub Issue を作る、または既存 Issue を再利用する
- branch を切る
- PSR-7 / PSR-17 implementation を選ぶ
- router strategy / package を選ぶ
- middleware dispatcher / response emitter の最小方針を決める
- `docs/adr/0001-...md` を作る
- 必要に応じて `composer.json` に dependency を追加する
- `public_html/index.php` の smoke endpoint を HTTP runtime 経由に移行する
- PHPUnit / PHPStan / `composer check` で検証する
- self-review を PR body に記録する

## Suggested First ADR

ADR 0001 の候補:

```text
docs/adr/0001-select-http-runtime-packages.md
```

扱う判断:

- PSR-7 / PSR-17 implementation
- router strategy
- middleware dispatcher approach
- response emitter approach

必ず `docs/development/package-selection.md` の評価基準に沿って比較する。

## Watch Points

- 具体パッケージ選定を ADR なしで進めない。
- framework core を heavy framework に寄せすぎない。
- controller resolver magic を早期導入しない。
- use case に PSR-7 request や raw request array を渡さない。
- public error response に stack trace / SQL / secrets / file path を出さない。
- `docs/review/backend-api.md` と `docs/review/docs-policy.md` を確認する。
- `.github/workflows/*` を変更する場合は、既存ルールに従い AI が push せず、ユーザーへ手動 push を依頼する。

## Verification Baseline

現時点で安定している検証:

```bash
docker compose run --rm app composer check
git diff --check
```

Frontend checks are not available yet because `frontend/package.json` has not been implemented.

## Conversation Context

ユーザー hide は、次チャットで実装フェーズに入る意向。

リナは日本語で柔らかく応答しつつ、コード・公開 docs は既存 language policy に従うこと。
