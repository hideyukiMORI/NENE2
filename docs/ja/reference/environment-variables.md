# 環境変数

NENE2 が認識するすべての環境変数です。
`.env`（phpdotenv がロード）に記述するか、サーバー起動前にエクスポートしてください。

## アプリケーション

| 変数 | 型 | デフォルト | 説明 |
|---|---|---|---|
| `APP_ENV` | string | `local` | 実行環境。使用可能な値: `local`, `test`, `production`。 |
| `APP_DEBUG` | boolean | `false` | デバッグ出力を有効化。開発環境のみ `true` を設定してください。 |
| `APP_NAME` | string | `NENE2` | ログ出力に使用するアプリケーション名。空にできません。 |
| `PROBLEM_DETAILS_BASE_URL` | string | `https://nene2.dev/problems/` | Problem Details の `type` URI に先頭に付けるベース URL。独自ドメインでカスタム問題型を提供する場合に上書きしてください。 |

## 認証

| 変数 | 型 | デフォルト | 説明 |
|---|---|---|---|
| `NENE2_MACHINE_API_KEY` | string | *(空 — 無効)* | マシンクライアントエンドポイントの `X-NENE2-API-Key` ヘッダーに期待される API キー。空にするとマシンキーパスが無効になります。 |
| `NENE2_LOCAL_JWT_SECRET` | string | *(空 — 無効)* | `LocalBearerTokenVerifier` が使う HMAC-HS256 シークレット。`GET /examples/protected` の Bearer JWT 検証を有効にし、ローカル MCP サーバーの書き込みツールを保護します。空にすると JWT 認証は無効になり、読み取り専用の MCP アクセスのみになります。`Nene2\Auth\GuardedJwtSecretResolver` 経由で解決する場合、下記の開発用シークレット opt-in が設定されていない限り空値は fail closed になります（本番では決して許可されません）。 |
| `NENE2_ALLOW_DEV_SECRET` | boolean（厳格） | `false` | `Nene2\Auth\GuardedJwtSecretResolver` が読む開発用シークレットの opt-in（`AppConfig::$allowDevSecret` として公開）。有効な値は `1` / `true` / `yes` **のみ**（大文字小文字は区別せず前後の空白は除去）。それ以外の値は — タイプミスを含めて — すべて opt-out として扱われます。`local` / `test` 環境で `NENE2_LOCAL_JWT_SECRET` が未設定のとき、製品側が注入した開発用シークレットの使用を許可します。**本番では無視され**、常に fail closed になります。[ADR 0013](../adr/0013-guarded-jwt-secret-resolution.md) を参照。 |

## ローカル MCP サーバー

| 変数 | 型 | デフォルト | 説明 |
|---|---|---|---|
| `NENE2_LOCAL_API_BASE_URL` | string | *(必須)* | MCP サーバーが API 呼び出しをプロキシする際に使用するベース URL（例: `http://app`）。Docker Compose でサーバーを起動する場合は必須です。 |

## データベース

| 変数 | 型 | デフォルト | 説明 |
|---|---|---|---|
| `DATABASE_URL` | string | *(空 — `DB_*` を使用)* | データベース接続 URL。空でない場合は個別の `DB_*` 変数をすべて上書きします。 |
| `DB_ADAPTER` | string | `mysql` | データベースドライバー。使用可能な値: `sqlite`, `mysql`, `pgsql`（実験的 — [PostgreSQL を使う](../howto/use-postgresql.md) を参照）。 |
| `DB_HOST` | string | `127.0.0.1` | データベースホスト名または IP アドレス。**SQLite では使用されません。** Docker Compose 内では `compose.yaml` が `app` サービス向けに `mysql` へ上書きします。 |
| `DB_PORT` | integer | `3306` | データベースポート番号（1〜65535）。**SQLite ではバリデーションされません。** |
| `DB_NAME` | string | `nene2` | データベース名。SQLite の場合はファイルパス（例: `/tmp/myapp.sqlite`）を設定します。 |
| `DB_USER` | string | `nene2` | データベースユーザー名。**SQLite では使用されません。** |
| `DB_PASSWORD` | string | *(空)* | データベースパスワード。 |
| `DB_CHARSET` | string | `utf8mb4` | データベース文字セット。**SQLite では使用されません。** |
| `DB_ENV` | string | `local` | Phinx マイグレーションの環境名（`phinx.php` を参照）。 |


### SQLite アダプター

`DB_ADAPTER=sqlite` の場合、必要なのは `DB_NAME`（ファイルパス）のみです。`DB_HOST`・`DB_USER`・`DB_CHARSET` はバリデーション対象外であり、設定不要です。

```dotenv
DB_ADAPTER=sqlite
DB_NAME=/tmp/myapp.sqlite
```

テスト用のインメモリ SQLite には `DB_NAME=:memory:` を使用します。

::: warning シークレットをコミットしない
パスワード・API キー・JWT シークレットを含む `.env` ファイルはバージョン管理にコミットしないでください。
:::
