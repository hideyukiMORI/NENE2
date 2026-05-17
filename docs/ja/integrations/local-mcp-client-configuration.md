# ローカル MCP クライアント設定

このガイドでは、ローカル MCP クライアントを NENE2 の stdio MCP サーバーに接続する方法を説明します。

ローカル開発専用です。本番 MCP デプロイにこの設定を再利用しないでください。

## 前提条件

PHP イメージをビルドしてローカル API を起動します:

```bash
docker compose build app
docker compose up -d app
```

API が到達可能か確認します:

```bash
curl -i http://localhost:8080/health
```

MCP サーバーは stdio プロセスです。HTTP サーバーではなく、MCP クライアントによって起動される必要があります。

## 汎用 stdio 設定

コマンド・引数・環境変数を受け付ける MCP クライアントでは、この形式を使用します:

```json
{
  "mcpServers": {
    "nene2-local": {
      "command": "docker",
      "args": [
        "compose",
        "run",
        "--rm",
        "-e",
        "NENE2_LOCAL_API_BASE_URL=http://app",
        "app",
        "php",
        "tools/local-mcp-server.php"
      ]
    }
  }
}
```

`http://app` を使用する理由:

- MCP サーバープロセスは Docker Compose の `app` コンテナー内で動作する
- ターゲット Web サービスは Compose サービス名で到達可能
- そのコンテナー内の `localhost` はワンオフ MCP コンテナーを参照し、実行中の Web サービスではない

コミットされた MCP クライアント設定にシークレットを含めないでください。将来のローカルツールが認証情報を必要とする場合は、ドキュメントに環境変数名のみを記載し、値はリポジトリ外で設定してください。

## ローカルスモークチェック

スモークヘルパースクリプトを使用して、ボイラープレートなしで完全な JSON-RPC シーケンスを実行します。

最初にアプリサービスが起動している必要があります:

```bash
docker compose up -d app
```

次にヘルパーを実行します:

```bash
# initialize + tools/list のみ
bash tools/mcp-smoke.sh

# 特定のツールを呼び出す
bash tools/mcp-smoke.sh getHealth '{}'

# パスパラメーターを持つツールを呼び出す（整数フィールドには JSON 数値を使用）
bash tools/mcp-smoke.sh getExhibitionWorkByYearAndId '{"year":2026,"workId":20260101}'
```

スクリプトはメッセージを stdio MCP サーバーにパイプし、Compose ネットワーク上の `http://app` で動作する `app` サービスを呼び出します。必要に応じて API ベース URL を上書きします:

```bash
NENE2_LOCAL_API_BASE_URL=http://my-api bash tools/mcp-smoke.sh getHealth '{}'
```

**手動の代替** — より細かい制御が必要な場合は生の JSON-RPC 行をパイプします:

```bash
printf '%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"local-smoke","version":"0.0.0"}}}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' \
  '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"getHealth","arguments":{}}}' \
  | docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://app app php tools/local-mcp-server.php
```

レスポンスには文書化されたローカル HTTP API からの構造化コンテンツが含まれ、request id メタデータが含まれる場合があります。

## 利用可能なツール

最初のローカルサーバーは `docs/mcp/tools.json` から read-only ツールを読み込みます。

現在の例:

- `getFrameworkSmoke`
- `getHealth`

カタログを検証するには:

```bash
docker compose run --rm app composer mcp
```

### パスパラメーターの型

整数パラメーター（例: `{year}`・`{id}`）を持つ OpenAPI パスにマッピングされるツールは、文字列ではなく `tools/call` の引数で JSON 数値を必要とします。

正しい例:

```json
{"name": "getItemsByYear", "arguments": {"year": 2026}}
```

誤った例（スキーマが `integer` を指定している場合に拒否される）:

```json
{"name": "getItemsByYear", "arguments": {"year": "2026"}}
```

各パラメーターの期待される型を確認するには、`docs/mcp/tools.json` のツールの `inputSchema` を確認してください。

## 安全ルール

ローカル MCP クライアントで許可される操作:

- 文書化されたローカル HTTP API を呼び出す
- サーバーを通じてコミットされた MCP メタデータを読み取る
- OpenAPI オペレーションに対応した read-only ツールを使用する

ローカル MCP クライアントで禁止される操作:

- `.env` のシークレットを読み取る
- 本番 API を呼び出す
- 直接のデータベースまたはファイルシステムアクセスを公開する
- フォーカスされた設計と Issue なしに write・admin・destructive ツールを追加する
- ユーザー固有の MCP クライアント設定をコミットする

## 関連ドキュメント

- ローカル MCP サーバーガイダンス: `docs/integrations/local-mcp-server.md`
- MCP ツールポリシー: `docs/integrations/mcp-tools.md`
- MCP カタログ: `docs/mcp/tools.json`
- クライアントプロジェクト開始ガイド: `docs/development/client-project-start.md`
- 認証境界: `docs/development/authentication-boundary.md`
