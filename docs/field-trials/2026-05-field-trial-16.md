# Field Trial 16 — noteboard: MCP ツール層の実地検証

## Date

2026-05-20

## Baseline

- NENE2 v1.5.0（`hideyukimori/nene2: ^1.4`、Packagist 経由）
- PHP 8.4
- プロジェクト: **noteboard** — シンプルなメモ共有ボード（FT15 と同プロジェクト）
- エンティティ: `Note`（1 ドメイン、JSON API 3 エンドポイント）
- MCP ツール: `listNotes` / `getNoteById`（read）・`createNote`（write）
- テスト: PHPUnit 13、PHPStan level 8、PHP-CS-Fixer、`symfony/yaml`（require-dev）

## Goal

NENE2 の MCP ツール層を Consumer Project 視点で実地検証する。
`docs/mcp/tools.json` 定義 → `composer mcp` バリデーション → `LocalMcpToolCatalog` テスト → `LocalMcpServer` 起動の一連のフローが摩擦なく完結するかを確認する。

---

## Steps Taken

### 1. Consumer Project における MCP セットアップ

CLAUDE.md の「Consumer Project から MCP バリデーターを使う」手順に従いセットアップ。

1. `require-dev` に `symfony/yaml` を追加（`validate-mcp-tools.php` が依存）
2. `composer.json` の `scripts` に `mcp` エントリを追加:
   ```json
   "mcp": "php vendor/hideyukimori/nene2/tools/validate-mcp-tools.php --root=."
   ```
3. `docs/mcp/tools.json` を作成（read 2 件 + write 1 件）
4. `docs/openapi/openapi.yaml` に各エンドポイントを記述

### 2. tools.json 定義

```json
{
  "version": 1,
  "source": "docs/openapi/openapi.yaml",
  "tools": [
    {
      "name": "listNotes",
      "safety": "read",
      "source": { "type": "openapi", "operationId": "listNotes",
                  "method": "GET", "path": "/api/notes" },
      "inputSchema": { "type": "object", "properties": {
        "limit":  { "type": "integer" },
        "offset": { "type": "integer" }
      }},
      "responseSchemaRef": "#/components/schemas/NoteListResponse"
    },
    {
      "name": "createNote",
      "safety": "write",
      "source": { "type": "openapi", "operationId": "createNote",
                  "method": "POST", "path": "/api/notes" },
      "inputSchema": { "type": "object", "required": ["title"],
                       "properties": { "title": { "type": "string" },
                                       "content": { "type": "string" } }},
      "responseSchemaRef": null
    }
  ]
}
```

### 3. `composer mcp` バリデーション

```bash
composer mcp
# → MCP tool catalog is valid.
```

`operationId` と OpenAPI パスの整合・`responseSchemaRef` の `$ref` 解決・`safety` の値チェックが自動的に行われる。

### 4. `McpToolCatalogTest` による単体テスト

Consumer Project で `LocalMcpToolCatalog` を直接使用してカタログの契約を検証するテストを記述した。

```php
final class McpToolCatalogTest extends TestCase
{
    public function testListNotesToolIsPresent(): void
    {
        $catalog = new LocalMcpToolCatalog(dirname(__DIR__) . '/docs/mcp/tools.json');
        $tool = $catalog->find('listNotes');

        self::assertNotNull($tool);
        self::assertSame('read', $tool['safety']);
        self::assertSame('/api/notes', $tool['source']['path']);
    }
}
```

---

## Findings

### F-1: `symfony/yaml` の追加が CLAUDE.md に明記されているが見落としやすい [中]

`validate-mcp-tools.php` は `symfony/yaml` を必要とするが、これは `require-dev` への手動追加が必要。`composer mcp` を最初に実行した際に `Composer\Autoload\ClassLoader` エラーで失敗し、原因特定に 5 分かかった。

**CLAUDE.md** には記載があるが、エラーメッセージがわかりにくい。

**提案**: `validate-mcp-tools.php` に `symfony/yaml` の存在チェックを追加し、未インストールの場合に明確なガイドメッセージを出力する。

---

### F-2: `write` ツールがベアラー認証なしで呼ばれた場合のエラーが明確 [情報]

`createNote`（`safety: write`）を認証なしで呼ぶと、`LocalMcpServer` から以下のエラーが返る。

```
Write tool "createNote" requires bearer authentication.
Set NENE2_LOCAL_JWT_SECRET in the MCP server environment.
```

エラーメッセージが具体的で、対処が即座にわかる。Claude Desktop や MCP Inspector での統合テストでもこのメッセージがそのまま表示されるため、開発体験が良い。

---

### F-3: `responseSchemaRef: null` が write ツールでは正しいが、補足ドキュメントがない [低]

`createNote` のレスポンスは `null` を設定した。実際には 201 Created とレスポンスボディが存在するが、`tools.json` の MCP スキーマでは `responseSchemaRef` が必須でないため `null` を許容している。

ただし `null` の意味（「スキーマ参照なし = 構造化コンテンツを返さない」）が `tools.json` のドキュメントに記載されておらず、意図的 `null` と未実装の区別がつかない。

**提案**: `tools.json` スキーマドキュメントに `responseSchemaRef: null` の意味を明記する。

---

### F-4: `McpToolCatalogTest` が Consumer Project でのゴールデンパス [情報]

`LocalMcpToolCatalog` を Consumer Project のテストで直接使用し、ツール名・safety・パスを検証するパターンが非常に安定していた。`composer mcp` の静的バリデーションとテストの動的検証を組み合わせることで、デプロイ前にカタログの破壊的変更を検出できる。

このテストパターンを `add-mcp-tools.md` howto に組み込むことを推奨する。

---

### F-5: MCP サーバーの起動コマンドが Consumer Project 向けに整備されていない [中]

NENE2 本体の `docs/` には以下の起動コマンドが記述されている。

```bash
docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://app app php tools/local-mcp-server.php
```

しかし Consumer Project では `local-mcp-server.php` は `vendor/hideyukimori/nene2/tools/` にある。Consumer Project での正しい起動コマンドが CLAUDE.md に一行もない。

**提案**: CLAUDE.md の「ローカル MCP サーバー起動」セクションに Consumer Project 向けコマンドを追記する。

```bash
docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://app app \
  php vendor/hideyukimori/nene2/tools/local-mcp-server.php
```

---

## Summary

| 項目 | 結果 |
|---|---|
| `tools.json` 定義 + `composer mcp` | 摩擦小、`symfony/yaml` 追加が必要 △ |
| `write` ツールの認証エラーメッセージ | 明確で開発体験が良い ✓ |
| `McpToolCatalogTest` テストパターン | 安定・推奨パターンとして確立 ✓ |
| Consumer Project MCP サーバー起動 | CLAUDE.md にコマンド不足 △ |
| `responseSchemaRef: null` の意味 | ドキュメント不足 △ |

MCP ツール層は `read` ツールの定義・バリデーション・テストの三点セットが機能している。`write` ツールの認証は直感的に動作する。Consumer Project での起動コマンドとスキーマドキュメントの補完が次の優先改善候補。
