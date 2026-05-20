# Current TODO

Purpose: keep the current work visible across chats, agents, and local sessions.

## Status

- Latest release: `v1.5.31`（2026-05-20 リリース済み）
- Current branch: `main` — clean — open Issue なし

## Recently Completed (FT ループ — v1.5.27 〜 v1.5.31)

| FT | テーマ | アプリ | テスト | リリース | 主要対応 |
|---|---|---|---|---|---|
| FT93 | Unicode / 絵文字入力 | unicodelog | 22/22 | v1.5.27 | `JSON_UNESCAPED_UNICODE` 追加・validate-unicode-input.md |
| FT94 | JWT 認証エッジケース | authlog | 18/18 | v1.5.28 | `authMiddleware` 命名 howto・`exp` 推奨明記 |
| FT95 | タイムゾーン敏感日付 | schedulelog | 19/19 | v1.5.29 | `QueryStringParser::parse()` 追加・handle-timezones.md |
| FT96 | コンテントネゴシエーション | contentlog | 16/16 | v1.5.30 | content-negotiation.md（406 を返さない設計を明文化） |
| FT97 | SQL インジェクション防御 | injectionlog | 19/19 | v1.5.31 | `Router::param()`・`QueryStringParser` デフォルト値引数・sql-injection.md |

## 次のアクション

- **FT98** — ファイルアップロード（MIME バリデーション・サイズ制限・パストラバーサルリスク）
  - FT ループが自動スケジュール中（ScheduleWakeup 設定済み）

## Open Issues

なし（FT97 で起票した #691 / #692 / #693 は v1.5.31 で解消済み）

## Operating Notes

- Keep this file short.
- Move completed historical detail to milestone files and field-trial reports when it becomes noisy.
- FT ループが毎回 main にマージするため、リリースのたびにこのファイルも更新すること。
- Full phase history is preserved in `docs/roadmap.md` and `docs/milestones/`.
