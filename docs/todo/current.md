# Current TODO

Purpose: keep the current work visible across chats, agents, and local sessions.

## Status

- Latest release: `v1.3.0` (Phase 53 — Packagist 反映済み)
- Field Trial 9 完了 (Phase 56) — #371・#372 フォローアップ済み
- レビュー所見反映 (#407) — FT8 stub 作成・ロードマップ Phase 58–59 追加
- nene2.dev ドメイン取得確認 — #405 クローズ、#409 に残論点を分離
- Current branch: `main` — clean

## Recently Completed

- **Phase 55**: Dependabot PR #324–#333 (10件) マージ
- **Phase 56**: Field Trial 9 — v1.3.0 検証 (PaginationQueryParser / openapi:docs / 400/422 分離)
- **Phase 57**: VitePress i18n リンク修正 (#380); locale http-endpoints.md 同期 (#384, PR #385)
- **#388 / PR #389**: Post-v1.0 ドキュメント整合性クリーンアップ
- **#400 / PR #401**: 5 locale 同期・README・env-vars・roadmap 修正
- **#407**: レビュー所見反映 — FT8 stub・FT10 マイルストーン・ロードマップ Phase 58–59
- **#405**: nene2.dev ドメイン取得確認 → Option A 確定、クローズ

## Next Candidates

- **Phase 58 / Field Trial 10** (#404): v1.3.0 を起点に Note/Tag 以外の新ドメインをスクラッチ実装（ユーザー手作業必須）
- **Phase 59 / type URI 設定化** (#409): `ProblemDetailsResponseFactory` の base URL を `AppConfig` 経由で設定可能にする

## Operating Notes

- Keep this file short.
- Move completed historical detail to milestone files and field-trial reports when it becomes noisy.
- If a task needs review, create or link a GitHub Issue.
- Full phase history is preserved in `docs/roadmap.md` and `docs/milestones/`.
