# Current TODO

Purpose: keep the current work visible across chats, agents, and local sessions.

## Status

- Latest release: `v1.4.2`（リリース予定）
- Current branch: `docs/ft14-complete` — FT14 完了 + v1.4.2 リリース準備中

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

- **Phase 64**: `docs/howto/add-jwt-authentication.md` を 6 言語で追加（PR #449 マージ済み）

## Recently Completed (continued)

- **Phase 65 / Field Trial 12-A** (#453): tagmark（ブックマーク + タグ M:N API）を `composer require hideyukimori/nene2:^1.4.1` から 0 構築。3ドメイン・13エンドポイント・PHPUnit 19/19・PHPStan level 8・PHP-CS-Fixer 全通過。摩擦 7 件記録（F-1〜F-7）、F-1/F-2/F-7 は v1.4.1 で解消済み、F-3/F-4/F-5 は howto 修正済み、F-6 は Issue #457 として起票。
- **v1.4.1**: FT11 フォローアップ修正（excludedPaths・MiddlewareInterface・TokenIssuerInterface）＋ FT12-A 発見の howto 誤り（F-3/F-4/F-5）修正。タグ・GitHub Release 作成済み。
- **Phase 66 / Field Trial 12-B** (#454): knowledgelog（ナレッジベース API — Article / Category）を `composer require hideyukimori/nene2:^1.4.1` から 0 構築。8 エンドポイント・MCP 7 ツール・PHPUnit 10/10・PHPStan level 8・PHP-CS-Fixer 全通過。摩擦 5 件記録（F-1〜F-5）、F-2/F-3（高）は PR #464 で解消済み（--root オプション + suggest 追加）、F-1/F-4/F-5 は Issue #459–#463 として起票。
- **Phase 67 / Field Trial 12-C** (#455): shoplog（商品カタログ API — 3 層アクセスモデル）を `composer require hideyukimori/nene2:^1.4.1` から 0 構築。12 エンドポイント・PHPUnit 29/29・PHPStan level 8・PHP-CS-Fixer 全通過。摩擦 6 件記録（F-1〜F-6）、F-2/F-5（高/低）は PR #470 で解消済み（$protectedPathPrefixes 追加・LocalBearerTokenVerifier 公開）、F-1/F-3/F-4/F-6 は Issue #466–#469 として起票。

## FT12 + FT13 完了

FT12-A / FT12-B / FT12-C / FT13 のすべてが完了。主要成果:

| FT | アプリ | テスト | 主要摩擦 | 解消済み |
|---|---|---|---|---|
| 12-A | tagmark (M:N) | 19/19 | F-1〜F-7 | v1.4.1 + PR #464 |
| 12-B | knowledgelog (MCP) | 10/10 | F-1〜F-5 | PR #464 (F-2/F-3) |
| 12-C | shoplog (Multi-Auth) | 29/29 | F-1〜F-6 | PR #470 (F-2/F-5) |
| 13 | eventlog (MySQL+Phinx) | 14/14 | F-1〜F-5 | PR (F-2/F-3/F-4 howto) |

- **Phase 68 / Field Trial 13** (#472): eventlog（イベント管理 API）を `composer require hideyukimori/nene2:^1.4` から 0 構築。10 エンドポイント・MySQL + Phinx マイグレーション（3 テーブル）・PHPUnit 14/14・PHPStan level 8・PHP-CS-Fixer 全通過。摩擦 5 件記録（F-1〜F-5）、F-2/F-3/F-4 は Issue #474–#476 として起票（本 PR で howto 修正）。

## 未解消フィールドトライアル摩擦（open issues）

- Issue #457: M:N 多対多 howto（F-6 from FT12-A）
- Issue #461: ApiKeyAuthenticationMiddleware メソッドベース保護（F-1 from FT12-B/C）
- Issue #462: nene2.auth.* 属性名の公開（F-4 from FT12-B）
- Issue #463: RuntimeApplicationFactory Example 切り離し（F-5 from FT12-B）
- Issue #466: CompositeAuthMiddleware / howto（F-1 from FT12-C）
- Issue #469: PHPStan memory_limit howto（F-6 from FT12-C）
- Issue #474: DB_PASSWORD 環境変数名明示（F-2 from FT13） ← 本 PR で解消
- Issue #475: MySQL FK `signed => false` howto（F-3 from FT13） ← 本 PR で解消
- Issue #476: Phinx 部分失敗後クリーンアップ howto（F-4 from FT13） ← 本 PR で解消

これらを整理して v1.5.0 をリリースするか、継続して追加フィールドトライアルを実施するか検討。

---

## Recently Completed (AI-Standard-Tool)

- **llms.txt 設置** → PR #450: リポジトリルートに llmstxt.org 準拠ファイルを設置
- **Smithery 登録準備** → PR #451: smithery.yaml を追加（`smithery mcp publish` で公開可能な状態）
- **OpenAPI 前面化** → PR #452: README バッジ・raw spec URL・llms.txt OpenAPI セクション追加

## Backlog: AI-Standard-Tool 方向への強化（残タスク）

| 優先度 | タスク | 内容 |
|---|---|---|
| 高 | Smithery 実際の公開 | `smithery auth login && smithery mcp publish` を実行（手動操作が必要） |
| 中 | FT12 フィールドトライアル | 次テーマ（MCP 統合・多対多リレーション・ファイルアップロード等）で実施 |
| 中 | nene2-python JWT 対応 | Python 版にも JWT 認証フローを追加 |
| 低 | TypeScript 版検討 | トークン量・工数を見ながら並列稼働を判断 |

## Operating Notes

- Keep this file short.
- Move completed historical detail to milestone files and field-trial reports when it becomes noisy.
- If a task needs review, create or link a GitHub Issue.
- Full phase history is preserved in `docs/roadmap.md` and `docs/milestones/`.
