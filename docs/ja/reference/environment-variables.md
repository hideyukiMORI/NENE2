# 環境変数

NENE2 が認識するすべての環境変数です。
`.env`（phpdotenv がロード）に記述するか、サーバー起動前にエクスポートしてください。

## アプリケーション

| 変数 | 型 | デフォルト | 説明 |
|---|---|---|---|
| `APP_ENV` | string | `local` | 実行環境。使用可能な値: `local`, `test`, `production`。 |
| `APP_DEBUG` | boolean | `false` | デバッグ出力を有効化。開発環境のみ `true` を設定してください。 |
| `APP_NAME` | string | `NENE2` | ログ出力に使用するアプリケーション名。空にできません。 |

## 認証

| 変数 | 型 | デフォルト | 説明 |
|---|---|---|---|
| `NENE2_MACHINE_API_KEY` | string | *(空 — 無効)* | マシンクライアントエンドポイントの `X-NENE2-API-Key` ヘッダーに期待される API キー。空にするとマシンキーパスが無効になります。 |
| `NENE2_LOCAL_JWT_SECRET` | string | *(空 — 無効)* | ローカル MCP サーバーの書き込みツールを保護する HMAC-HS256 シークレット。空にすると読み取り専用ツールは認証なしで利用可能です。 |

## ローカル MCP サーバー

| 変数 | 型 | デフォルト | 説明 |
|---|---|---|---|
| `NENE2_LOCAL_API_BASE_URL` | string | *(必須)* | MCP サーバーが API 呼び出しをプロキシする際に使用するベース URL（例: `http://app`）。Docker Compose でサーバーを起動する場合は必須です。 |

## データベース

| 変数 | 型 | デフォルト | 説明 |
|---|---|---|---|
| `DATABASE_URL` | string | *(空 — `DB_*` を使用)* | データベース接続 URL。空でない場合は個別の `DB_*` 変数をすべて上書きします。 |
| `DB_ADAPTER` | string | `mysql` | データベースドライバー。使用可能な値: `sqlite`, `mysql`。 |
| `DB_HOST` | string | `127.0.0.1` | データベースホスト名または IP アドレス。 |
| `DB_PORT` | integer | `3306` | データベースポート番号（1〜65535）。 |
| `DB_NAME` | string | `nene2` | データベース名。 |
| `DB_USER` | string | `nene2` | データベースユーザー名。 |
| `DB_PASSWORD` | string | *(空)* | データベースパスワード。 |
| `DB_CHARSET` | string | `utf8mb4` | データベース文字セット。 |

::: warning シークレットをコミットしない
パスワード・API キー・JWT シークレットを含む `.env` ファイルはバージョン管理にコミットしないでください。
:::
