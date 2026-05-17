# ローカル MCP サーバー統合

ローカル MCP サーバー統合は、エージェントが文書化された境界を通じて NENE2 を検査・検証できるようにします。

これは開発の利便性であり、本番環境のバックドアではありません。

## ポジション

ローカル MCP サーバーは、開発者のローカル NENE2 チェックアウトに対して、read-only の検査ツールと安全な検証コマンドを公開できます。

使用するもの:

- パブリックのローカル HTTP API
- コミットされたドキュメント
- `docs/mcp/tools.json`
- 文書化された安全なローカルコマンド

## 最初のローカルサーバー

NENE2 にはローカル専用の stdio MCP サーバーが含まれています:

```bash
docker compose run --rm app php tools/local-mcp-server.php
```

デフォルトでは `http://localhost:8080` のローカル API を呼び出します。必要に応じてリポジトリ外でベース URL を上書きします:

```bash
docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://localhost:8080 app php tools/local-mcp-server.php
```

Compose の `app` サービスに対して Docker 内でサーバーを実行する場合は、サービス名を API ベース URL として使用します:

```bash
docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://app app php tools/local-mcp-server.php
```

### write ツールの DB 前提条件

Read ツール（`getHealth`・`listExampleNotes`・`getExampleNoteById` 等）は `app` コンテナーのみ必要です。

Write ツール（`createExampleNote`・`updateExampleNoteById`・`deleteExampleNoteById`）はデータベースに永続化するエンドポイントを呼び出します。write ツールを呼び出す前に、MySQL を起動してマイグレーションを適用してください:

```bash
docker compose up -d mysql
docker compose run --rm app composer migrations:migrate
```

この手順を省略すると、write ツールの呼び出しは詳細情報なしに HTTP 500 を返します。write ツールが 500 を返す場合は、詳細調査の前に MySQL が起動しておりマイグレーションが適用済みであることを確認してください。

サーバーがサポートするメソッド:

- `initialize`
- `tools/list`
- `tools/call`

ツールは `docs/mcp/tools.json` から読み込まれます。read-only（`safety: read`）と write（`safety: write`）の両方の OpenAPI 対応ツールが公開されます。

Read ツール（`getHealth`・`getFrameworkSmoke`・`listExampleNotes`・`getExampleNoteById`）は HTTP GET にマッピングされます。引数はパスパラメーターまたはクエリ文字列の値になります。

Write ツール（`createExampleNote`・`updateExampleNoteById`・`deleteExampleNoteById`）はそれぞれ HTTP POST・PUT・DELETE にマッピングされます。パスパラメーターは引数から補間され、残りの引数は JSON リクエストボディとして送信されます。

サーバーは stdio 上の改行区切り JSON-RPC メッセージで通信します。ローカル MCP クライアントと開発スモークチェック向けであり、ブラウザで直接使用するものではありません。

具体的なローカル MCP クライアント設定例は `docs/integrations/local-mcp-client-configuration.md` を参照してください。

使用してはいけないもの:

- 本番データベースへの直接アクセス
- 生の `.env` シークレット読み取り
- ユーザーの非公開ファイルシステムパス
- 通常の境界でテストできない隠れたアプリケーション動作

## ローカルの責任範囲

ローカル MCP サーバーで許可される操作:

- コミットされた MCP カタログを読み取る
- `http://localhost:8080/` と他の文書化されたローカル API ルートを呼び出す
- HTTP レスポンスの `X-Request-Id` メタデータを返す
- `docs/integrations/local-ai-commands.md` の文書化された検証コマンドを実行する
- コミットされたプロジェクトドキュメントを読み取ってプロジェクト構造に関する質問に答える

ローカル MCP サーバーはツールを小さく見直しやすく保つ必要があります。ツールにビジネス動作が必要な場合は、まずアプリケーション API の追加または文書化を優先します。

## 認証情報

ローカル MCP サーバーの設定は環境変数名を記載できますが、認証情報の値をコミットしてはなりません。

許可される例:

```text
NENE2_LOCAL_API_BASE_URL=http://localhost:8080
NENE2_LOCAL_MCP_API_KEY=<リポジトリ外で設定する>
```

認証情報は `docs/development/authentication-boundary.md` に従う必要があります。

認証を必要としないローカルツールは、認証情報が必要であるかのように見せかけてはなりません。認証情報を必要とするツールは、生のシークレット値ではなく必要なスコープを文書化する必要があります。

## ツールの形

ローカルツールは実用的な場合に既存のカタログまたは OpenAPI オペレーションにマッピングする必要があります。

推奨メタデータ:

- ツール名
- 安全レベル（`read`・`write`・`admin`・`destructive`）
- ソースオペレーションまたはコマンド
- 必要なスコープ（ある場合）
- ツールが HTTP を呼び出すかどうか
- ツールが request id メタデータを返すかどうか

`admin` と `destructive` ツールは現在のローカル MCP サーバーガイダンスのスコープ外です。

### 整数パスパラメーター

ツールが `{year}` や `{id}` などの整数パラメーターを持つ OpenAPI パスにマッピングされる場合、ツールの `inputSchema` で `"type": "integer"` として宣言し、`tools/call` の引数で JSON 数値として渡す必要があります。

スキーマが整数型を指定している場合、`"2026"` などの文字列値はアダプターバリデーションで拒否されます。完全なパスパラメーターポリシーについては `docs/integrations/mcp-tools.md` を参照してください。

## HTTP 動作

ローカル MCP ツールが HTTP API を呼び出す場合:

- 設定されたローカル API ベース URL を使用する
- JSON API には `Accept: application/json` を送信する
- Problem Details エラーを無関係な形に書き換えず保持する
- 存在する場合は `X-Request-Id` レスポンスヘッダーを返すかログに記録する
- 返されるメタデータに認証情報を含めない

## 安全なコマンド

ローカルコマンドツールは以下のような文書化されたチェックに限定する必要があります:

```bash
docker compose run --rm app composer check
docker compose run --rm app composer mcp
npm run check --prefix frontend
git diff --check
```

依存関係のインストール・データベースの変更・リリースのタグ付け・PR のマージ・git 履歴の変更を行うコマンドは、フォーカスされた Issue と明示的なユーザーの意図が必要です。

## 本番環境の境界

本番 MCP ツールは、認証・認可・監査・運用上の所有権を持つプロダクト機能として設計する必要があります。

ローカル MCP サーバーの設定を本番設定として再利用しないでください。

本番 MCP 統合が存在する前に、以下を文書化してください:

- 所有者
- 許可される環境
- 認証情報とスコープ
- 監査フィールド
- レート制限または不正利用対策
- 失敗とロールバックの動作
