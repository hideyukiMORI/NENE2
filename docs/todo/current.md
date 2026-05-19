# Current TODO

Purpose: keep the current work visible across chats, agents, and local sessions.

## Status

- Latest release: `v1.4.0`
- Current branch: `main` — clean

## Recently Completed

- **Phase 56**: Field Trial 9 — v1.3.0 検証
- **#407**: レビュー所見反映 — FT8 stub・FT10 マイルストーン・ロードマップ Phase 58–59
- **#409**: ProblemDetailsResponseFactory base URL 設定化
- **#413**: #409 実装後のドキュメント整合（deploy-production 6言語・ADR 0009・env-vars 6言語・CLAUDE.md）
- **v1.4.0**: タグ・GitHub Release 作成、Packagist 反映確認
- **#417 #418**: FrameworkInfo::VERSION 定数追加・LocalMcpServer バージョン修正・安定 API 全クラス PHPDoc 追加 (#419)
- **Phase 58 / Field Trial 10** (#404): hoplog（クラフトビールテイスティングノート API）を `composer require hideyukimori/nene2:^1.4` から 0 構築。3ドメイン・15ルート・PHPStan level 8・全テスト通過。摩擦 9 件記録、フォローアップ Issue #423–#429 作成。PR #422 マージ済み。

## Next: Phase 60 — FT10 Follow-up Docs & Fixes（高・中優先度）

ドキュメント追記とバグ修正のみで解消できる摩擦を対応する。

| Issue | 内容 | 深刻度 |
|---|---|---|
| #423 | `Router::PARAMETERS_ATTRIBUTE` をハンドラリファレンスに追記 | 高 |
| #424 | SQLite 環境変数ドキュメント追記 | 高 |
| #425 | SQLite アダプター時のダミーフィールドバリデーション免除 | 高 |
| #427 | ContainerBuilder 後勝ちルール・ValidationException 例・--allow-risky=yes 追記 | 中/低 |
| #428 | php:8.4-cli 推奨 Dockerfile How-to 追加 | 中 |

## After Phase 60: Phase 61 — FT10 Follow-up Feature Improvements

| Issue | 内容 | 深刻度 |
|---|---|---|
| #426 | `APP_DEBUG=true` 時に例外メッセージを detail / ログに出力 | 高 |
| #429 | ページネーションレスポンスの `total` フィールド対応 | 中 |

## Operating Notes

- Keep this file short.
- Move completed historical detail to milestone files and field-trial reports when it becomes noisy.
- If a task needs review, create or link a GitHub Issue.
- Full phase history is preserved in `docs/roadmap.md` and `docs/milestones/`.
