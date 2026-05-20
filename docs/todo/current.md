# Current TODO

Purpose: keep the current work visible across chats, agents, and local sessions.

## Status

- Latest release: `v1.5.37`（2026-05-21 リリース済み）
- Current branch: `main` — clean — open Issue なし

## Recently Completed (FT ループ — v1.5.27 〜 v1.5.37)

| FT | テーマ | アプリ | テスト | リリース | 主要対応 |
|---|---|---|---|---|---|
| FT96 | コンテントネゴシエーション | contentlog | 16/16 | v1.5.30 | content-negotiation.md（406 を返さない設計を明文化） |
| FT97 | SQL インジェクション防御 | injectionlog | 19/19 | v1.5.31 | `Router::param()`・`QueryStringParser` デフォルト値引数・sql-injection.md |
| FT98 | ファイルアップロード | uploadlog | 19/19 | v1.5.32 | file-upload.md（base64 オーバーヘッド・MIME 検出・パストラバーサル） |
| FT99 | CSRF 的パターン・冪等性 | csrflog | 15/15 | v1.5.33 | idempotency.md・csrf-and-json-api.md（CORS ≠ CSRF 明文化） |
| FT100 | OFFSET vs カーソルページネーション | pagelog | 15/15 | v1.5.34 | pagination.md（fetch+1・パフォーマンス比較・選択基準） |
| FT101 | ネスト JSON バリデーション | nestedlog | 19/19 | v1.5.35 | nested-json-validation.md（ドット記法・全収集・PHPStan 判別共用体） |
| FT102 | DBトランザクション境界 | txlog | 11/11 | v1.5.36 | transactions.md（コールバック外インジェクト罠・pre-validation パターン） |
| FT103 | マスアサインメント防御 | masslog | 14/14 | v1.5.37 | mass-assignment.md（DTO ホワイトリスト・権限フィールド隔離・レスポンス制御） |

## 次のアクション

- FT ループ継続中（ScheduleWakeup 設定済み）

## Open Issues

なし（FT103 で起票した #708 は v1.5.37 で解消済み）

## Operating Notes

- Keep this file short.
- Move completed historical detail to milestone files and field-trial reports when it becomes noisy.
- FT ループが毎回 main にマージするため、リリースのたびにこのファイルも更新すること。
- Full phase history is preserved in `docs/roadmap.md` and `docs/milestones/`.
