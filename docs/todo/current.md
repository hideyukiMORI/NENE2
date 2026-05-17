# Current TODO

Purpose: keep the current work visible across chats, agents, and local sessions.

## Status

- Current milestone: `docs/milestones/2026-05-field-trial-2.md` (Field Trial 2)
- Latest release: `v0.2.0` (Phase 17 complete)
- Current branch: `main`

## Foundation Completed

- [x] Create GitHub Issue for initial governance docs. `#1`
- [x] Create Issue branch for initial governance docs. `docs/1-initial-governance`
- [x] Add README, AGENTS, workflow, roadmap, milestone, TODO, coding standards, and integration policy docs. `#1`
- [x] Add Cursor rules that summarize the docs. `#1`
- [x] Merge the initial governance PR. `#1`
- [x] Define standard project layout and create initial directories. `#3`
- [x] Add root Composer metadata, PHP runtime policy, PHPUnit, and PHPStan. `#5`
- [x] Add Docker-based PHP development environment and first smoke endpoint. `#7`
- [x] Add initial OpenAPI contract and Swagger UI. `#9`
- [x] Define thin HTML view and optional template engine policy. `#11`
- [x] Define database migration layout and tool selection policy. `#13`
- [x] Define PSR-first HTTP runtime and routing policy. `#15`
- [x] Define PSR-11 DI container and explicit wiring policy. `#16`
- [x] Define typed config and environment policy. `#17`
- [x] Define RFC 9457 Problem Details API error response policy. `#18`
- [x] Define middleware and security baseline policy. `#19`
- [x] Define quality tools and documentation comments policy. `#20`
- [x] Define request validation policy. `#21`
- [x] Define frontend integration and toolchain policy. `#22`
- [x] Define release, versioning, and CI policy. `#23`
- [x] Define ADR operation policy. `#24`
- [x] Define logging and observability policy. `#33`
- [x] Define self-review checklist policy. `#37`
- [x] Define implementation readiness guardrails. `#39`
- [x] Add implementation-start handoff and first task instructions. `#41`
- [x] Implement HTTP runtime foundation. `#43`
- [x] Choose concrete PSR-7 / PSR-17 packages and initial router strategy. `#43`
- [x] Write ADR 0001 for concrete HTTP runtime and router selections. `#43`
- [x] Choose concrete PSR-11 container package or adapter strategy. `#45`
- [x] Write ADR 0002 for concrete container selections. `#45`
- [x] Add minimal PSR-11 container foundation. `#45`
- [x] Implement typed config loader and `.env.example`. `#47`
- [x] Add OpenAPI Problem Details schemas. `#49`
- [x] Add validation error mapping and readonly DTO examples. `#51`
- [x] Add request id, CORS, security headers, and request size middleware skeletons. `#53`
- [x] Add PSR-3 logging adapter and request id log context. `#55`
- [x] Add PHP-CS-Fixer configuration and Composer scripts. `#57`
- [x] Add the first native PHP view renderer and escaping helper. `#59`
- [x] Add release checklist and first `v0.x.y` release preparation. `#61`
- [x] Add Dependabot policy for PHP and GitHub Actions dependencies. `#63`
- [x] Add metrics and error tracking adapter policy details. `#65`
- [x] Add OpenAPI contract validation to `composer check`. `#67`
- [x] Expand the HTTP runtime skeleton beyond the smoke endpoint. `#69`
- [x] Keep self-review checklists updated as new implementation areas are introduced. `#71`
- [x] Add React + TypeScript frontend starter and ESLint / Prettier baseline. `#73`
- [x] Add npm package metadata, Node engines, package lock, and frontend check scripts. `#73`
- [x] Choose and wire the migration runner when the database adapter layer starts. `#75`
- [x] Publish or redirect `https://nene2.dev/problems/*` before public error contracts are stable. `#77`
- [x] Add route path parameters to the internal router. `#79`
- [x] Wire the HTTP runtime through the PSR-11 container foundation. `#81`
- [x] Add the first frontend-to-backend health API integration path. `#83`
- [x] Add first GitHub Actions workflow for backend Composer checks. `#85`
- [x] Add first GitHub Actions workflow for frontend npm checks. `#87`
- [x] Add lightweight OpenAPI runtime contract tests for shipped JSON endpoints. `#89`
- [x] Add typed database config and align Phinx with the config loader. `#91`
- [x] Register typed config services in the runtime container. `#93`
- [x] Add the first database connection adapter boundary. `#95`
- [x] Add a parameterized database query boundary for repository adapters. `#97`
- [x] Define the first database test strategy and focused test command. `#99`
- [x] Add an explicit database transaction boundary. `#101`
- [x] Define MCP tool integration safety policy. `#103`
- [x] Add a read-only MCP tool catalog aligned with OpenAPI. `#105`
- [x] Document safe local AI/MCP development commands. `#107`
- [x] Add request-id based AI debugging guide. `#109`
- [x] Add generated OpenAPI-to-MCP catalog direction. `#111`
- [x] Define API-key and token boundary policy. `#113`
- [x] Add local MCP server integration guidance. `#115`
- [x] Expand OpenAPI runtime contract tests toward schema validation. `#117`
- [x] Add branch protection readiness checklist. `#119`
- [x] Refresh first `v0.1.0` release preparation notes. `#121`

## Next Candidates

- [x] Refresh the foundation milestone with completed implementation scope. `#123`
- [x] Define the next milestone for post-foundation release readiness. `#125`
- [x] Draft concrete `v0.1.0` GitHub Release notes after release readiness is confirmed. `#129`
- [x] Decide whether to enable branch protection settings for `main`. `#127`
- [x] Run and record final `v0.1.0` release verification. `#131`
- [x] Publish the first `v0.1.0` foundation release. `#133`
- [x] Record LLM-assisted delivery starter direction from review notes. `#134`
- [x] Define the post-`v0.1.0` LLM delivery starter milestone. `#136`

## LLM Delivery Starter Candidates

- [x] Implement the first local MCP server for read-only OpenAPI-aligned tools. `#138`
- [x] Add API-key authentication middleware for machine-client requests. `#140`
- [x] Create endpoint scaffold documentation and the first example endpoint workflow. `#142`
- [x] Add Docker Compose real database service verification. `#144`
- [x] Review whether `v0.1.x` should include patch releases for milestone increments. `#146`

## Client Delivery Hardening Candidates

- [x] Close out the LLM Delivery Starter milestone and improve README entry points. `#148`
- [x] Add a client project start guide for adapting the foundation. `#150`
- [x] Document a local MCP client configuration example. `#152`
- [x] Add a protected machine-client smoke workflow. `#154`
- [x] Decide whether to tag `v0.1.1` as a delivery-starter checkpoint. `#156`

## First LLM Field Trial Candidates

- [x] Record the first LLM field-trial direction and next milestone. `#158`
- [x] Add a field-trial report template. `#160`
- [x] Run the first `v0.1.1` client-style field trial. `#164`
- [x] Link public field-trial sandbox from core docs. `#166`
- [x] Convert field-trial friction into focused follow-up Issues. `#167` `#168`
- [x] Resolve friction follow-up: document MCP integer path parameters. `#167`
- [x] Resolve friction follow-up: document Cursor GitHub MCP PAT vs `gh` CLI. `#168`
- [x] Decide whether any repeated field-trial step justifies a helper script or generator. → MCP スモークコマンドをスクリプト化する（`tools/mcp-smoke.sh`）。
- [x] Add local MCP smoke helper script (`tools/mcp-smoke.sh`). `#178`

_Friction follow-ups (docs): Issues `#167` (MCP JSON integers for path params), `#168` (Cursor GitHub MCP PAT vs `gh` CLI)._

## Domain Layer Starter Candidates

- [x] Define the Phase 9 milestone for Domain Layer Starter. `#180`
- [x] Write the domain layer policy doc (`docs/development/domain-layer.md`). `#182`
- [x] Add a minimal UseCase interface and example use case in `src/`. `#184`
- [x] Add a RepositoryInterface convention and example PDO adapter. `#184`
- [x] Add an example handler that delegates to the use case. `#184`
- [x] Add OpenAPI schema entries for the example domain endpoint. `#184`
- [x] Add PHPUnit unit tests for the example use case. `#184`
- [x] Add PHPUnit integration tests for the example PDO adapter. `#184`
- [x] Update `docs/development/endpoint-scaffold.md` to reference domain layer patterns. `#182`
- [x] Update `docs/development/client-project-start.md` to reference the domain layer doc. `#182`
- [x] Update self-review checklists with domain layer checkpoints. `#182`
- [x] Decide whether Phase 9 warrants a `v0.1.2` patch release. `#186` → yes, see `docs/development/release-v0.1.2-prep.md`

## Write Operations Pattern Candidates

- [x] Define the Phase 10 milestone for Write Operations Pattern. `#188`
- [x] Add `post()` and `delete()` to `Router` and `lastInsertId()` to `DatabaseQueryExecutorInterface`. `#190`
- [x] Add write methods to `NoteRepositoryInterface` and `PdoNoteRepository`. `#190`
- [x] Add `CreateNoteUseCase` and `DeleteNoteUseCase` with readonly DTOs. `#190`
- [x] Add `CreateNoteHandler` (201 + Location) and `DeleteNoteHandler` (204). `#190`
- [x] Add body validation with `ValidationException` → 422. `#190`
- [x] Add OpenAPI schemas for POST and DELETE operations. `#190`
- [x] Add PHPUnit unit and integration tests for write paths. `#190`

## Error Handler Systemization Candidates

- [x] Add `DomainExceptionHandlerInterface` to `src/Error/`. `#197`
- [x] Add `list<DomainExceptionHandlerInterface>` to `ErrorHandlerMiddleware`. `#197`
- [x] Add `NoteNotFoundExceptionHandler` and wire it in `RuntimeServiceProvider`. `#197`
- [x] Remove `ProblemDetailsResponseFactory` from `GetNoteByIdHandler` and `DeleteNoteHandler`. `#197`
- [x] Add MySQL write operations verification and `compose.yaml` DB env defaults. `#194`
- [x] Add `docs/development/setup.md` getting-started guide. `#196`
- [x] Update `docs/development/docker.md` with MySQL service section. `#196`

## Test Coverage Hardening Candidates (Phase 12)

- [x] Define Phase 12 milestone and update roadmap. `#200`
- [x] Add HTTP-level tests for Note endpoints (GET/POST/DELETE × success + error paths). `#201`

## Completed Phases

- [x] **Phase 13**: Collection endpoint — `GET /examples/notes` with list use case and OpenAPI schema. `#204`
- [x] **Phase 14**: Monolog structured logging (stderr JSON, debug/warning level via APP_DEBUG). `#206`

## Current Phase

- [x] **Phase 15**: Field Trial 2 milestone doc + v0.1.3 tag. `#208`
- [x] **Phase 16**: v0.2.0 readiness — ADR 0006 (src/Example + Packagist)、CHANGELOG.md 作成. `#210`

## Completed

- [x] **Phase 17**: `PUT /examples/notes/{id}` で Note full CRUD + v0.2.0 タグ. `#212`
- [x] **Field Trial 2**: v0.2.0 観察レポート + フリクション Issue 作成. `#215`
- [x] **Phase 18**: Monolog RequestIdProcessor — `X-Request-Id` を全ログレコードに付与. `#216`
- [x] **docs**: README に src/Example/Note/ を正規ドメイン層例として明記. `#217`

## Current Phase

- [x] **Phase 19**: v0.3.0 readiness — ADR 0007・Packagist 基準確認・v0.3.0 タグ. `#222`
- [x] **Field Trial 3**: v0.3.0 観察レポート + Packagist Go 判断. `#225`

## Next Candidates

- [x] **Packagist 登録前準備**: `composer.json` type `"library"` + README インストール手順. `#226`
- [x] docs(setup): RequestIdProcessor 動作確認手順. `#227`
- [x] feat(mcp): Note write operations MCP ツール追加 — catalog + LocalMcpServer 実装. `#228`
- [x] Packagist 登録: `hideyukimori/nene2` 公開済み (`composer require hideyukimori/nene2`)

## Phase 20: Packagist Field Trial

- [x] composer require からの新規プロジェクト構築を実施する. `#233`
- [x] 観察レポートを `docs/field-trials/` に記録する (`docs/field-trials/2026-05-field-trial-4.md`). `#233`
- [x] 摩擦を follow-up Issue に変換する (#234 #235 #236). `#233`

## Phase 20 フォローアップ

- [x] docs(scaffold): `Router::PARAMETERS_ATTRIBUTE` 取得例を追加する. `#234`
- [x] docs(client-start): `composer require` 起点ワイヤリング手順を追加する. `#235`
- [x] feat: `RuntimeApplicationFactory` に `$routeRegistrars` を追加して外部ルート注入を可能にする. `#236`

## Phase 21: v0.4.0 リリース

- [x] chore(release): v0.4.0 CHANGELOG 確定・todo 更新. `#240`

## Phase 21: Field Trial 5 — MCP Write Tools

- [x] マイルストーン定義・ロードマップ追記. `#242`
- [x] ローカル MCP サーバーを起動し write tools を操作する. `#244`
- [x] 観察レポートを `docs/field-trials/2026-05-field-trial-5.md` に記録する. `#244`
- [x] 摩擦を follow-up Issue に変換する (#245 #246). `#244`

## Phase 21 フォローアップ

- [x] docs(mcp-smoke): write 操作の DB 前提条件を `mcp-smoke.sh` に追記する. `#245`
- [x] docs(local-mcp): write 操作の DB 前提条件をローカル MCP サーバーガイドに追記する. `#246`

## Phase 22: Diátaxis ドキュメント整備

- [x] マイルストーン定義・ロードマップ追記. `#249`
- [x] Tutorial: 最初の API を動かす (`docs/tutorial/first-api.md`). `#251`
- [x] HOWTO: カスタムルートを追加する (`docs/howto/add-custom-route.md`). `#253`
- [x] HOWTO: DB 付きエンドポイントを追加する (`docs/howto/add-database-endpoint.md`). `#254`
- [x] `docs/` インデックス更新 (`docs/README.md`). `#256`
- [x] VitePress ドキュメントサイト構築 (`npm run docs:build`). `#258`
- [x] i18n: 日本語・フランス語・中国語・ブラジルポルトガル語・ドイツ語翻訳追加. `#260`
- [x] theme: ダークモードをリッチなテックデザインに刷新（深い青黒背景・グロー・ドットグリッド）. `#262`
- [x] fix(i18n): 言語切り替え時の 404 修正 — 絶対パス・locale link・404ページ. `#264`
- [x] feat(i18n): 全 9 リファレンスページを 5 言語（ja/fr/zh/pt-br/de）に翻訳する. `#266`
- [x] ci: GitHub Pages に VitePress ドキュメントを自動デプロイする. `#268`

## Phase 23: CI Hardening + Node.js Upgrade

- [x] docs(roadmap): Phase 23+ マイルストーン追加・ロードマップ更新. `#270`
- [x] ci: `actions/setup-node` を Node.js 22 LTS に更新し Node.js 20 deprecation を解消する.
- [x] ci: バックエンド `composer check` を実行する `backend.yml` ワークフローを追加する.
- [x] ci: フロントエンド `npm run check` を実行する `frontend.yml` ワークフローを追加する.

## Phase 24: Diátaxis Explanation Pages

- [x] docs(explanation): `docs/explanation/why-psr.md` — PSR 選択理由. `#274`
- [x] docs(explanation): `docs/explanation/why-explicit-wiring.md` — 明示的 DI の理由. `#274`
- [x] docs(explanation): `docs/explanation/why-problem-details.md` — RFC 9457 選択理由. `#274`
- [x] docs(explanation): `docs/explanation/why-mcp.md` — MCP 境界の理由. `#274`
- [x] docs(vitepress): Explanation セクションを nav/sidebar に追加する. `#274`

## Phase 25: 2 つ目のドメインエンティティ例

- [x] feat(example): `src/Example/Tag/` — 2 つ目のドメインエンティティ例を追加する. `#276`
- [x] feat(example): `GET/POST /examples/tags` および `GET /examples/tags/{id}` エンドポイント追加. `#276`
- [ ] docs(howto): 多エンティティパターンを HOWTO または Explanation に追記する.

## Phase 26: 本番デプロイガイド

- [x] docs(howto): `docs/howto/deploy-production.md` — Docker 本番イメージ・env 管理・シークレット注入. `#278`
- [x] docs(howto): Nginx / Caddy リバースプロキシ設定例. `#278`
- [x] docs(howto): 本番セキュリティチェックリスト（デバッグ無効・`APP_ENV=production`・dev エンドポイント削除）. `#278`
- [x] chore: `nene2.dev` Problem Details type URI プレースホルダーの方針を決定する（Option A/B をガイドに明記）. `#278`

## Phase 27: フロントエンドスターターコンテンツ

- [x] feat(frontend): API 接続済みコンポーネント（`NoteList`、`NoteForm`）を追加する. `#280`
- [x] feat(frontend): `frontend/src/api/notes.ts` に Note エンドポイント向け型付き fetch ラッパーを追加する. `#280`
- [x] feat(frontend): `npm run dev` で Note データが表示される動作デモを完成させる. `#280`

## Phase 28: 認証拡張

- [x] docs(adr): ADR 0008 — JWT 認証方向（ライブラリ選定・トークン検証境界・PSR-15 ミドルウェア配置）. `#282`
- [x] feat(auth): `BearerTokenMiddleware` スタブと `TokenVerifierInterface` を追加する. `#282`
- [x] docs(auth): `authentication-boundary.md` を JWT セクションで拡張する. `#282`

## Phase 29: v0.5.0 リリース準備

- [x] docs(roadmap): Phase 29-32 をロードマップに追加する. `#284`
- [x] chore(changelog): v0.5.0 セクション（Phase 23-28）を追記する. `#284`
- [ ] chore(release): `v0.5.0` タグを打つ. `#284`

## Operating Notes

- Keep this file short.
- Move completed historical detail to milestone files when it becomes noisy.
- If a task needs review, create or link a GitHub Issue.
