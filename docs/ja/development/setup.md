# ローカルセットアップガイド

このガイドでは、NENE2 をクローンしてから API を起動するまでのローカルセットアップ手順を説明します。

## 前提条件

- [Docker Desktop](https://www.docker.com/products/docker-desktop/)（または Docker Engine + Compose プラグイン）
- Git

ホスト側への PHP・Node.js・MySQL のインストールは不要です。すべてのランタイム依存関係は Docker 内で動作します。

## 1. クローンと設定

```bash
git clone https://github.com/hideyukiMORI/NENE2.git
cd NENE2
cp .env.example .env
```

`.env` を開いて必要に応じて値を調整します。デフォルト値のままでローカル開発は動作します。

主な環境変数:

| 変数 | デフォルト | 用途 |
|---|---|---|
| `APP_ENV` | `local` | 実行環境 |
| `NENE2_MACHINE_API_KEY` | *(空)* | ローカル開発ではマシンクライアント認証を無効にするため空のままにする |
| `DB_ADAPTER` | `mysql` | `sqlite` または `mysql` |
| `DB_HOST` | `mysql` | Docker Compose のサービス名に対応 |

## 2. ビルドとインストール

```bash
docker compose build
docker compose run --rm app composer install
```

## 3. バックエンドチェックの実行

```bash
docker compose run --rm app composer check
```

PHPUnit・PHPStan・PHP-CS-Fixer・OpenAPI バリデーション・MCP カタログバリデーションを順番に実行します。クリーンなクローン直後はすべてパスするはずです。

## 4. Web サーバーの起動

```bash
docker compose up -d app
```

動作確認:

```bash
curl -i http://localhost:8080/health
```

期待されるレスポンス:

```json
{"status":"ok","service":"NENE2"}
```

その他の便利なローカルエンドポイント:

| URL | 説明 |
|---|---|
| `http://localhost:8080/` | フレームワーク情報 |
| `http://localhost:8080/health` | ヘルスチェック |
| `http://localhost:8080/examples/ping` | Ping サンプル |
| `http://localhost:8080/examples/notes/{id}` | Note 取得（DB 必要） |
| `http://localhost:8080/openapi.php` | OpenAPI JSON |
| `http://localhost:8080/docs/` | Swagger UI |

## 5. サーバーの停止

```bash
docker compose down
```

## オプション: MySQL データベースのセットアップ

デフォルトのテストスイートは SQLite インメモリを使用します。MySQL アダプターの動作確認やサービスデータベースに対する書き込みスモークテストを行う場合:

```bash
docker compose up -d mysql
docker compose run --rm app composer migrations:migrate
docker compose run --rm app composer test:database:mysql
```

SQLite と MySQL の比較については `docs/development/docker.md` を参照してください。

## オプション: マシンクライアント認証

`/machine/health` エンドポイントには API キーが必要です。ローカルでテストするには:

1. `.env` に `NENE2_MACHINE_API_KEY=local-dev-key` を設定する。
2. アプリサービスを再起動する: `docker compose up -d app`
3. 保護されたエンドポイントを呼び出す:

```bash
curl -i -H 'X-NENE2-API-Key: local-dev-key' http://localhost:8080/machine/health
```

マシンクライアントのスモークワークフロー全体は `docs/development/machine-client-smoke.md` を参照してください。

## オプション: フロントエンドのセットアップ

```bash
npm install --prefix frontend
npm run dev --prefix frontend
```

フロントエンド開発サーバーは API コールを `app` コンテナーにプロキシします。詳細は `docs/development/frontend-integration.md` を参照してください。

## オプション: ローカル MCP サーバー

```bash
docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://app app php tools/local-mcp-server.php
```

MCP クライアントの設定については `docs/integrations/local-mcp-server.md` を参照してください。

## オプション: ログで Request ID を確認する

すべてのリクエストに `X-Request-Id` が生成され、レスポンスヘッダーに返され、Monolog の全ログレコードに `extra.request_id` として付与されます。動作確認の手順:

1. アプリを起動する: `docker compose up -d app`
2. リクエストを送信する:
   ```bash
   curl -i http://localhost:8080/health
   # レスポンスヘッダーの X-Request-Id を確認する
   ```
3. 構造化ログの出力を確認する:
   ```bash
   docker compose logs app
   # 各 JSON ログ行に "extra":{"request_id":"<id>"} が含まれる
   ```

独自の ID を指定することもできます:
```bash
curl -i -H 'X-Request-Id: my-trace-id' http://localhost:8080/health
# 同じ ID がレスポンスヘッダーと docker compose logs の両方に現れる
```

## トラブルシューティング

**クリーンなクローン後に `composer check` が失敗する**
先に `docker compose run --rm app composer install` を実行してください。`vendor/` ディレクトリはリポジトリにコミットされていません。

**ポート 8080 がすでに使用中**
使用中のプロセスを停止するか、`compose.yaml` のポートマッピングを変更します:
```yaml
ports:
  - "8081:80"   # 8081 を代わりに使用する
```

**マイグレーション実行時に MySQL 接続が拒否される**
`docker compose up -d mysql` 後、`mysql` コンテナーが起動するまで数秒かかります。少し待ってから再試行してください。

**`DB_HOST` のデフォルトは `127.0.0.1` だが接続が拒否される**
Docker コンテナー内ではホストとしてサービス名 `mysql` を使用します。デフォルトの `.env.example` と `compose.yaml` はすでに正しく設定されています — `.env` に `DB_HOST=mysql` が設定されているか確認してください。
