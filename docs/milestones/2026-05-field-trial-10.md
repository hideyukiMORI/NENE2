# Milestone: Field Trial 10 — New Domain Entity from Scratch

## Goal

Validate that the v1.4.0 scaffold workflow is sufficient to build a third domain entity
entirely from scratch — one that does not exist anywhere in the NENE2 repository — and confirm
that `PaginationQueryParser`, `JsonRequestBodyParser`, and the MCP tool path work correctly for
the new entity.

## Theme: Scaffold Ergonomics

All prior field trials exercised features against Note and Tag, both of which ship as reference
implementations in `src/Example/`. FT10 shifts focus: does the documentation alone guide
a developer through adding a new entity, without leaning on the existing examples as a codebase
crutch?

## Phases

### Phase 58 — Field Trial 10 Execution ✅

Project: **hoplog** — クラフトビールテイスティングノート JSON API
(`hideyukimori/nene2: ^1.4`, PHP 8.4, 3 domains, 15 routes)

Key deliverables:

- [x] Choose a new domain entity distinct from Note and Tag → Brewery / Beer / TastingNote
- [x] Follow docs as the only procedural guide
- [x] Implement full CRUD + list with pagination (15 routes)
- [x] Pass `composer check` (PHPUnit 18/18, PHPStan level 8, PHP-CS-Fixer clean)
- [x] Record friction in `docs/field-trials/2026-05-field-trial-10.md`
- [x] Open follow-up Issues for each friction point (#423–#429)

Scope narrowed from original: MCP tool calls and OpenAPI path additions were not included
in the hoplog build (hoplog focuses on core CRUD + test coverage).

### Phase 60 — FT10 Follow-up: High/Medium Priority Docs & Fixes ✅

Address the highest-impact findings from Field Trial 10. (PR #430 merged)

Key deliverables:

- [x] #423: `Router::PARAMETERS_ATTRIBUTE` をハンドラリファレンスに追記（F-2）
- [x] #424: SQLite 環境変数ドキュメント追記（F-4 docs）
- [x] #425: SQLite アダプター時のダミーフィールドバリデーション免除（F-4 design）
- [x] #427: ContainerBuilder 後勝ちルール・ValidationException 例・--allow-risky=yes 追記（F-1, F-7, F-9）
- [x] #428: 推奨 Dockerfile How-to 追加（F-3）

### Phase 61 — FT10 Follow-up: Feature Improvements ✅

Address feature-level findings from Field Trial 10. (PR #431 merged)

- [x] #426: `APP_DEBUG=true` 時に例外メッセージを detail に出力（F-5）
- [x] #429: `PaginationResponse` DTO 追加・`total` フィールド対応（F-8）

## Acceptance Criteria (Phase 58)

- [x] New entity endpoints pass `composer check`
- [x] `PaginationQueryParser` used in the list handler
- [x] `JsonRequestBodyParser` used in write handlers
- [x] Field trial report created with friction log
- [x] Follow-up Issues opened for all 9 friction points

## Notes

- User-executed trial; AI agent assisted with planning and follow-up Issue creation.
- Tracked by Issue #404.
- Field trial report: `docs/field-trials/2026-05-field-trial-10.md`
- Follow-up issues: #423 (F-2), #424 (F-4 docs), #425 (F-4 design), #426 (F-5), #427 (F-1/F-7/F-9), #428 (F-3), #429 (F-8)
