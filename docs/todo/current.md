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

## Recently Completed (continued)

- **Phase 60** (#423–#425, #427–#428): FT10 フォローアップ Docs & Fixes — SQLite バリデーション修正 + ドキュメント追記 6 言語（PR #430 マージ済み）
- **Phase 61** (#426, #429): FT10 フォローアップ Feature Improvements — APP_DEBUG 例外詳細露出・PaginationResponse DTO 追加（PR #431 マージ済み）
- **#432**: SQLite スキーマ初期化パターンをドキュメント化（F-6 対応、PR #433 マージ済み）

## Recently Completed (continued)

- **Phase 62 / Field Trial 11** (#434): tasklog（タスク管理 JWT 認証 API）を `composer require hideyukimori/nene2:^1.4` から 0 構築。2ドメイン・7エンドポイント・PHPUnit 12/12・PHPStan level 8・PHP-CS-Fixer 全通過。摩擦 4 件記録（F-1〜F-4）、フォローアップ Issue #440–#443 作成。hideyukiMORI/tasklog PR #1 マージ済み。

## Recently Completed (continued)

- **Phase 63 / FT11 フォローアップ実装**:
  - #440 → PR #445: `BearerTokenMiddleware` に `$excludedPaths`（ブラックリスト）オプション追加
  - #441 → PR #446: `RuntimeApplicationFactory` auth 引数型を `MiddlewareInterface` に緩める
  - #442 → PR #447: `TokenIssuerInterface` を公開 API に追加、ADR 0009 更新
  - #443 → PR #448: `CLAUDE.md` セクション 18 に開発ソース vs 公開パッケージ注記追加

## Next: Phase 64 — JWT 認証 how-to ドキュメント（#442 の how-to 部分）

`docs/howto/add-jwt-authentication.md` を追加し、認証フロー（登録・ログイン・Bearer 保護エンドポイント）の最小構成例を文書化する。6 言語展開も検討。

---

## Backlog: AI-Standard-Tool 方向への強化

### AI 認知・発見性

| 優先度 | タスク | 内容 |
|---|---|---|
| 高 | `llms.txt` 設置 | リポジトリルートに AI クローラー向け宣言ファイルを置く |
| 高 | MCP レジストリ登録 | Smithery 等の MCP ディレクトリに hoplog / NENE2 を登録 |
| 中 | OpenAPI を前面に | README・トップページに OpenAPI スペックへの導線を強化 |
| 中 | フィールドトライアル継続 | AI 実装実績を積み上げて公開する（FT11 以降） |

### 多言語展開（別リポジトリ）

| 優先度 | タスク | 内容 |
|---|---|---|
| 高 | Python 版着手 | `nene2-python` リポジトリを作成し、同じ設計思想で実装 |
| 中 | TypeScript 版検討 | トークン量・工数を見ながら並列稼働を判断 |

## Operating Notes

- Keep this file short.
- Move completed historical detail to milestone files and field-trial reports when it becomes noisy.
- If a task needs review, create or link a GitHub Issue.
- Full phase history is preserved in `docs/roadmap.md` and `docs/milestones/`.
