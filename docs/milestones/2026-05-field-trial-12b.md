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

### Phase 66 — Field Trial 12-B Execution

- [ ] knowledgelog リポジトリを作成し、`composer require hideyukimori/nene2:^1.4` で初期化
- [ ] Article / Category ドメインを実装
- [ ] API キー保護エンドポイントを実装
- [ ] `docs/mcp/tools.json` を作成し、MCP ツールを定義
- [ ] `composer mcp` バリデーション通過
- [ ] MCP サーバー起動・ツール動作確認
- [ ] `composer check` 全通過（PHPUnit・PHPStan level 8・PHP-CS-Fixer）
- [ ] 摩擦記録を `docs/field-trial-report.md` に残す
- [ ] フォローアップ Issue を開く

## 完了条件

- `composer check` 全通過
- MCP read/write ツールが動作
- 摩擦記録あり
- フォローアップ Issue 作成済み

## 備考

- 実施者: Claude Code（自律実装）
- Issue: #454
- 前: FT12-A (#453) — 多対多リレーション (tagmark)
- 次: FT12-C (#455) — 二層アクセス (shoplog)
