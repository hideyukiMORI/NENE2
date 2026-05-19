# MCP ツールを追加する

このガイドでは、NENE2 アプリケーションの API エンドポイントを MCP ツールとして公開し、AI アシスタント（Claude、Cursor など）がモデルコンテキストプロトコル経由でAPIを呼び出せるようにする方法を説明します。

**前提条件**: ルートが 1 つ以上あり、`docs/openapi/openapi.yaml` ファイルがある動作する NENE2 アプリケーションがあること。まだの場合は [カスタムルートを追加する](./add-custom-route.md) から始めてください。

---

## 概要

NENE2 はローカル MCP サーバー（`LocalMcpServer`）を提供します。JSON-RPC MCP メッセージを API への HTTP 呼び出しに変換します。ツールカタログ（`docs/mcp/tools.json`）は、どのエンドポイントを MCP ツールとして公開するか、各ツールの安全性レベルを宣言します。

```
AI アシスタント → JSON-RPC (stdio) → LocalMcpServer → HTTP → NENE2 アプリ
```

カタログは `composer mcp` で OpenAPI スペックと照合して検証されます。

---

## 1. バリデータースクリプトを追加する

`composer.json` に追加します:

```json
{
  "require-dev": {
    "symfony/yaml": "^7.0"
  },
  "scripts": {
    "mcp": "php vendor/hideyukimori/nene2/tools/validate-mcp-tools.php --root=."
  }
}
```

dev 依存をインストールします:

```bash
composer require --dev symfony/yaml
```

---

## 2. ツールカタログを作成する

`docs/mcp/tools.json` を作成します。`tools` の各エントリが 1 つの API エンドポイントに対応します。

```json
{
  "version": 1,
  "source": "docs/openapi/openapi.yaml",
  "tools": [
    {
      "name": "listNotes",
      "title": "ノート一覧",
      "description": "データベースから全ノートを返します。",
      "safety": "read",
      "source": {
        "type": "openapi",
        "operationId": "listNotes",
        "method": "GET",
        "path": "/notes"
      },
      "inputSchema": {
        "type": "object",
        "additionalProperties": false,
        "properties": {
          "limit":  { "type": "integer", "description": "返す最大件数。" },
          "offset": { "type": "integer", "description": "スキップする件数。" }
        }
      },
      "responseSchemaRef": "#/components/schemas/NoteListResponse"
    }
  ]
}
```

### ツールフィールド

| フィールド | 必須 | 説明 |
|---|---|---|
| `name` | Yes | ユニークなキャメルケース識別子 |
| `title` | Yes | 人間向けラベル |
| `description` | Yes | AI アシスタントに目的を説明するテキスト |
| `safety` | Yes | `read` / `write` / `admin` / `destructive` |
| `source.operationId` | Yes | OpenAPI スペックの `operationId` と一致する必要あり |
| `source.method` | Yes | HTTP メソッド（大文字小文字不問、内部的に大文字で保存） |
| `source.path` | Yes | パスパラメーターを `{param}` 形式で含む URL パス |
| `inputSchema` | Yes | ツール引数の JSON Schema |
| `responseSchemaRef` | No | OpenAPI コンポーネントスキーマへの `$ref`、または `null` |

### 安全性レベル

| レベル | 意味 |
|---|---|
| `read` | 副作用なしで呼び出し可能（GET リクエスト） |
| `write` | データを作成または変更（POST / PUT / PATCH） |
| `admin` | 管理アクション — 慎重に使用 |
| `destructive` | データを永久削除 — 明示的な確認が必要 |

まず `read` ツールのみから始め、認証が整ったら `write` ツールを追加してください。

---

## 3. カタログを検証する

```bash
composer mcp
```

バリデーターは以下を検証します:

- 各ツールの `operationId` が OpenAPI スペックに存在する
- 各ツールのパスが OpenAPI パス定義と一致する
- `safety` フィールドが 4 つの許可された値のいずれかである
- `responseSchemaRef`（null でない場合）が実在するコンポーネントスキーマに解決する

MCP サーバーを起動する前にエラーをすべて修正してください。

---

## 4. 書き込みツールを追加する

```json
{
  "name": "createNote",
  "title": "ノートを作成",
  "description": "新しいノートを作成します。",
  "safety": "write",
  "source": {
    "type": "openapi",
    "operationId": "createNote",
    "method": "POST",
    "path": "/notes"
  },
  "inputSchema": {
    "type": "object",
    "additionalProperties": false,
    "required": ["title", "content"],
    "properties": {
      "title":   { "type": "string", "description": "ノートのタイトル。" },
      "content": { "type": "string", "description": "ノートの本文。" }
    }
  },
  "responseSchemaRef": null
}
```

---

## 5. JWT で書き込みツールを保護する

`LocalMcpServer` は `write`、`admin`、`destructive` ツール呼び出しごとに `Authorization: Bearer <token>` ヘッダーを確認します。環境変数を設定します:

```dotenv
NENE2_LOCAL_JWT_SECRET=your-local-secret
```

この変数がない場合、書き込みツール呼び出しは MCP エラーを返し、リクエストを転送しません。

アプリケーション側でも対応するエンドポイントを `BearerTokenMiddleware` で保護します:

```php
use Nene2\Auth\BearerTokenMiddleware;
use Nene2\Auth\LocalBearerTokenVerifier;

$secret = getenv('NENE2_LOCAL_JWT_SECRET') ?: null;

$authMiddleware = $secret !== null
    ? new BearerTokenMiddleware(
        $problemDetails,
        new LocalBearerTokenVerifier($secret),
        excludedPaths: ['/notes'],
        protectedPathPrefixes: ['/notes/'],
    )
    : null;
```

---

## 6. MCP サーバーを起動する

```bash
NENE2_LOCAL_API_BASE_URL=http://localhost:8080 \
NENE2_LOCAL_JWT_SECRET=your-local-secret \
php vendor/hideyukimori/nene2/tools/local-mcp-server.php
```

サーバーは MCP stdio トランスポートを使って `stdin` から読み取り `stdout` に書き込みます。

---

## 7. Claude Code または Claude Desktop を設定する

### Claude Code (`~/.claude/claude_code_config.json`)

```json
{
  "mcpServers": {
    "my-app": {
      "command": "/path/to/my-app/mcp-server.sh"
    }
  }
}
```

### Claude Desktop (`claude_desktop_config.json`)

```json
{
  "mcpServers": {
    "my-app": {
      "command": "bash",
      "args": ["/path/to/my-app/mcp-server.sh"]
    }
  }
}
```

Claude を再起動すると、カタログで宣言したツールが呼び出し可能なアクションとして表示されます。

---

## 8. MCP 層をテストする

`LocalMcpToolCatalog` を直接テストします。HTTP サーバーは不要です:

```php
use Nene2\Mcp\LocalMcpToolCatalog;

public function testListNotesToolIsPresent(): void
{
    $catalog = new LocalMcpToolCatalog(dirname(__DIR__) . '/docs/mcp/tools.json');

    $tool = $catalog->find('listNotes');

    self::assertNotNull($tool);
    self::assertSame('read', $tool['safety']);
    self::assertSame('GET', $tool['source']['method']);
    self::assertSame('/notes', $tool['source']['path']);
}
```

---

## 次のステップ

- [JWT 認証を追加する](./add-jwt-authentication.md)
- [レートリミットを追加する](./add-rate-limiting.md)
- [ヘルスチェックを追加する](./add-health-check.md)
