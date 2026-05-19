# Milestone: Field Trial 12-B — MCP ファースト（Knowledge Base API）

## 仮説

> MCP ツールを主インターフェースとして設計した場合、
> `docs/mcp/tools.json` の記述から MCP サーバー起動までを
> Claude が迷わず実装できるか。

Field Trial 12-A（tagmark）が見つけた摩擦は主に「ドキュメントと実装の乖離」系だった。
FT12-B では **MCP ツール設計エリア** を集中的に叩く。

## テーマ: MCP Ergonomics

NENE2 の MCP 統合（`docs/mcp/tools.json`・`composer mcp` バリデーション・
ローカル MCP サーバー起動）を、新規プロジェクトで初めて使う際の摩擦を記録する。

## 実装するアプリ: knowledgelog

**ナレッジベース API** — FAQ・ドキュメント管理システム。
`composer require hideyukimori/nene2:^1.4` から 0 構築。

### ドメイン

- **Article**: タイトル・本文・カテゴリ（読み取りは公開・書き込みは API キー保護）
- **Category**: 名前（読み取りは公開・書き込みは API キー保護）

### エンドポイント

| Method | Path | 認証 | MCP ツール |
|---|---|---|---|
| GET | /articles | 不要 | ✅ read |
| GET | /articles/{id} | 不要 | ✅ read |
| GET | /articles?category={id} | 不要 | ✅ read |
| POST | /articles | API キー | ✅ write |
| PUT | /articles/{id} | API キー | ✅ write |
| DELETE | /articles/{id} | API キー | — |
| GET | /categories | 不要 | ✅ read |
| POST | /categories | API キー | ✅ write |

### 注目する摩擦ポイント候補

- `docs/mcp/tools.json` の記述方法（operationId との対応・inputSchema）
- `composer mcp` バリデーションのエラーメッセージ分かりやすさ
- MCP write ツールの認証設定（API キー保護との連動）
- read/write ツールの safety レベル設定（`safety: read` vs `safety: write`）
- `local-mcp-server.php` 起動コマンドの明確さ
- 公開（API キーなし）+ 管理者（API キーあり）の共存パターン

## Phases

### Phase 66 — Field Trial 12-B Execution ✅ 完了 (2026-05-19)

- [x] knowledgelog リポジトリを作成し、`composer require hideyukimori/nene2:^1.4.1` で初期化
- [x] Article / Category ドメインを実装
- [x] API キー保護（WriteApiKeyMiddleware）を実装
- [x] `docs/openapi/openapi.yaml` を作成
- [x] `docs/mcp/tools.json` を作成（7 ツール: 3 read + 4 write）
- [x] `composer mcp` バリデーション通過
- [x] `composer check` 全通過（PHPUnit 10/10・PHPStan level 8・PHP-CS-Fixer）
- [x] 摩擦記録を `docs/field-trial-report.md` に残す（5 件: F-1〜F-5）
- [x] フォローアップ Issue を開く（#459–#463）

## 結果

### テスト

```
PHPUnit 11: 10 tests, 30 assertions — OK
PHPStan level 8: No errors (15 files)
PHP-CS-Fixer: 0 files to fix (17 files)
MCP validation: MCP tool catalog is valid.
```

### 摩擦サマリー

| # | 種別 | 深刻度 | 対応 |
|---|---|---|---|
| F-1 | ApiKeyAuthenticationMiddleware が動的パスを保護できない | 中 | Issue #461 — 後続実装 |
| F-2 | validate-mcp-tools.php が Consumer Project から動かない | 高 | PR #464 で解消（--root オプション追加） |
| F-3 | symfony/yaml が Consumer の vendor に存在しない | 高 | PR #464 で解消（suggest 追加） |
| F-4 | nene2.auth.* 属性名がドキュメントにない | 低 | Issue #462 — 後続ドキュメント |
| F-5 | RuntimeApplicationFactory が Note/Tag に密結合 | 中 | Issue #463 — 後続リファクタ |

## 完了条件

- [x] `composer check` 全通過
- [x] MCP read/write ツールが動作
- [x] 摩擦記録あり
- [x] フォローアップ Issue 作成済み

## 備考

- 実施者: Claude Code（自律実装）
- Issue: #454
- 報告書: `/home/xi/docker/knowledgelog/docs/field-trial-report.md`
- F-2/F-3 対応 PR: #464 (マージ済み)
- 前: FT12-A (#453) — 多対多リレーション (tagmark)
- 次: FT12-C (#455) — 二層アクセス (shoplog)
