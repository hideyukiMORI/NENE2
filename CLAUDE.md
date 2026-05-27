# CLAUDE.md — NENE2

Claude Code がこのリポジトリで作業するための完全な手引き。
`.cursor/rules/` と `docs/` の全ポリシーを統合している。

---

## 正本ドキュメント一覧

| 目的 | ドキュメント |
|---|---|
| プロジェクト概要 | `README.md` |
| AI エージェント入口 | `AGENTS.md` |
| コラボレーション方針 | `docs/CONTRIBUTING.md` |
| ワークフロー詳細 | `docs/workflow.md` |
| コーディング規約 | `docs/development/coding-standards.md` |
| コミット規約 | `docs/development/commit-conventions.md` |
| 現在のタスク | `docs/todo/current.md` |
| ロードマップ | `docs/roadmap.md` |
| AI ツールポリシー | `docs/integrations/ai-tools.md` |

---

## プロジェクト哲学

NENE2 は PHP 8.4 製のミニマル API フレームワーク。

- **API first**: JSON API と OpenAPI 契約を中心に据える。
- **薄い HTML**: サーバー HTML はオプション。SPA シェルで差し替えやすく保つ。
- **AI-readable**: 明示的ディレクトリ、小さなクラス、型付き境界、記録された決定。
- **LLM delivery ready**: API・MCP・認証・DB・引き継ぎドキュメントを整合させる。
- **モダン PHP**: strict_types、PSR 標準、DI、自動テスト、静的解析。

Non-goals: Laravel/Symfony 再発明、マジック規約で動作を隠す、フロントエンドライブラリへの依存、直接 DB への AI アクセス。

---

## 1. Issue ドリブンワークフロー（常に適用）

### 基本ルール

- コード・ドキュメント・設定変更は **必ず GitHub Issue ベース**で進める。
- 作業開始前に `docs/roadmap.md`、`docs/milestones/`、`docs/todo/current.md`、関連 Issue を確認する。
- **`main` へ直接コミットしない**。

### ブランチ命名

```
type/issue-number-summary
```

例: `docs/1-initial-governance`、`feat/12-openapi-router`、`fix/34-cors-header`

### 標準フロー

1. GitHub Issue を作成または再利用する。
2. `main` から Issue 番号付きブランチを切る。
3. 最小限の変更を実装する。
4. 方針や状態が変わったらドキュメントを更新する。
5. 該当する `docs/review/` のセルフレビューチェックリストを確認する。
6. 最も狭い有用な検証を実行する。
7. Conventional Commits でコミットする（Issue 番号を含める）。
8. ブランチを push して PR を作成する。
9. チェックが通ったらマージする。
10. ローカル `main` をクリーンな状態に戻す。

**ユーザーが「調査のみ」「コミットしない」「PR まで」など範囲を明示した場合はその指示を優先する。**

### PR 要件

- 目的、変更点、検証結果、関連 Issue（`Closes #番号`）
- 使用したセルフレビューチェックリスト名
- 残りのリスクや後続作業

### コミットメッセージ規約

```
<type>(<scope>): <日本語の説明> (#<issue番号>)

[オプションの本文 — 変更理由、トレードオフ、後続作業]
```

- `type` / `scope` / Conventional Commits キーワードは **英語**
- description と body は **日本語**
- 公開 API 変更は `!` または `BREAKING CHANGE:` フッターを付ける

| type | 用途 |
|---|---|
| `feat` | 新機能 |
| `fix` | バグ修正 |
| `docs` | ドキュメントのみ |
| `refactor` | 機能変更なしのコード変更 |
| `test` | テストの追加・変更 |
| `build` | 依存関係・ビルド設定 |
| `ci` | CI 設定 |
| `chore` | メンテナンス |

---

## 2. 安全ルール（常に適用）

- **シークレット禁止**: パスワード、トークン、ローカル `.env`、生成物をコミットしない。
- 変更は依頼範囲と Issue の目的に留め、無関係な整形・リファクタを混ぜない。
- 破壊的操作（`git reset --hard`、`force push`、DB 削除等）はユーザーの明示的な承認を得てから行う。
- MCP ツールは DB 直結ではなく、ドキュメントされた API 境界を通す。
- AI ツールはプロダクション認証情報にアクセスしない。

---

## 3. PHP コーディング規約（`.php` ファイル）

### ベースライン

- PHP `>=8.4.1 <9.0`
- すべての新規 PHP ファイルに `declare(strict_types=1);` を付ける。
- PSR-12 に従う。
- 大きなファイルレベルのコピーライトバナーは付けない。
- immutable な value object と readonly プロパティを優先する。
- 境界で `array` のままにせず DTO / value object / enum / readonly property を使う。
- フレームワークマジックでコントロールフローを隠さない。

### アーキテクチャ

- UseCase / Domain は HTTP・DB・テンプレート・CLI・フロントエンドから**独立させる**。
- インフラ境界では interface に依存してカップリングを減らす。
- コンストラクタインジェクションを優先する。
- 型付き config オブジェクトを使う（`getenv()` / `$_ENV` / `$_SERVER` は config ローダー内のみ）。
- UseCase 入力境界には readonly DTO / コマンドオブジェクトを使う。
- コンテナをドメイン・ユースケースコード内でサービスロケーターとして使わない。
- コントローラーは薄く: 入力解釈 → UseCase 呼び出し → Response 生成。
- 永続化の詳細は Repository / Adapter に閉じ込める。

### HTTP ランタイム

- PSR-7: リクエスト・レスポンスメッセージ
- PSR-15: ミドルウェア・リクエストハンドラー
- PSR-17: ファクトリ
- ルーティングは明示的に。ルートテーブルを読みやすく保つ。
- フロントコントローラーは `public_html/index.php`。

### API と HTML

- JSON API がプライマリ出力面。
- OpenAPI が公開 request / response / error 形状を記述する。
- バリデーションは layered validation + readonly DTO 経由で UseCase を呼ぶ。
- サーバー HTML は最小限。Native PHP テンプレートが最初の標準パス。

### エラーハンドリング

- ユーザー向け JSON エラーは **RFC 9457 Problem Details** を使う（`application/problem+json`）。
- スタックトレース・SQL・ファイルパス・シークレット・プライベート識別子を公開レスポンスに含めない。
- バリデーション失敗は `validation-failed` Problem Details に構造化 `errors` 配列を付ける。
- Problem Details `type` パターン: `https://nene2.dev/problems/{problem-name}`

### テスト

- UseCase / Domain のユニットテストは DB なしで行う。
- アダプターと永続化の結合テストは重要な契約を証明するときのみ追加。
- 公開 API 動作には HTTP / 契約テストを追加。
- テストは決定的で小さく保つ。

### データベース

- フレームワークコアは DB 非依存に保つ。
- マイグレーション: `database/migrations/`、シード: `database/seeds/`、スキーマ: `database/schema/`
- マイグレーションツール: Phinx（ADR 0004）
- `DatabaseConnectionFactoryInterface` → `PdoConnectionFactory`
- クエリ: `DatabaseQueryExecutorInterface`、トランザクション: `DatabaseTransactionManagerInterface`

### DI

- PSR-11 をコンテナ境界として使う。
- 明示的ワイヤリング優先（オートワイヤリングは最初のデフォルトではない）。
- サービスプロバイダーは小さく、関連サービスをグループ化する。

### ドキュメントコメント

- **PHPDoc は公開 API / interface / extension point / ミドルウェア / 型付き config / 動作保証** に書く。
- ネイティブ型や明らかな実装詳細を繰り返す PHPDoc は書かない。

---

## 4. リクエストバリデーション（Layered）

```
Middleware  : request size / content-type / auth / CORS / request id
Controller  : path / query / body マッピング、readonly DTO 生成、フォーマット検証
UseCase     : ビジネス不変条件、認可ルール、状態依存ルール
```

- ミドルウェアにルート固有のビジネスルールを入れない。
- ValidationException → `validation-failed` Problem Details（HTTP 422）。

---

## 5. ミドルウェア順序

```
1. Request id
2. Request logging
3. Security headers
4. CORS
5. Error handling
6. Request size limit
7. Authentication / authorization
8. Rate limiting (optional — ThrottleMiddleware)
9. Routing / handler dispatch
```

> **順序の意図**: RequestId と Security headers は Error handling より外側に置くことで、エラーレスポンスにも `X-Request-Id` やセキュリティヘッダーが付く。CORS も同様に外側に置き、エラーレスポンスにも `Access-Control-Allow-Origin` が返る。Rate limiting は Auth の後ろに置くことで、認証済みユーザー単位での制限が可能になる。

- CORS は config 駆動。本番ではオリジンを明示的に allowlist に入れる。
- ログにシークレット・トークン・Cookie・Authorization ヘッダー・生リクエストボディを含めない。
- `X-NENE2-API-Key` ヘッダーがマシンクライアント用 API キーパス（env: `NENE2_MACHINE_API_KEY`）。

---

## 6. ロギング・オブザーバビリティ

- PSR-3 (`Psr\Log\LoggerInterface`) をロギング境界として使う。
- 構造化ログ推奨フィールド: `request_id` / `method` / `path` / `status` / `duration_ms`
- `X-Request-Id` ヘッダーで全リクエストを相関させる。
- エラートラッキング・メトリクスはアダプター駆動（コアに直接依存させない）。

ログに **含めてはいけない** もの:
- パスワード / トークン / API キー / セッション ID / Cookie
- `Authorization` ヘッダー / 生リクエストボディ / DB 接続文字列

---

## 7. MCP 統合ポリシー

- MCP ツールは OpenAPI 操作に対応する read-only ツールから始める。
- DB への直接アクセスは禁止。ドキュメントされた API 境界を通す。
- ツールのカタログ: `docs/mcp/tools.json`（`composer mcp` で検証）。
- ツールの安全レベル: `read` / `write` / `admin` / `destructive`。最初は `read` のみ。
- `write` / `admin` / `destructive` ツールには認証・認可・監査・request id・確認動作が必要。

### ローカル MCP サーバー起動

NENE2 本体（開発用）:

```bash
docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://app app php tools/local-mcp-server.php
```

Consumer Project（`vendor/` 経由）:

```bash
docker compose run --rm -e NENE2_LOCAL_API_BASE_URL=http://app app \
  php vendor/hideyukimori/nene2/tools/local-mcp-server.php
```

### Consumer Project から MCP バリデーターを使う

Consumer Project（`composer require hideyukimori/nene2:^1.4`）では:

1. `require-dev` に `symfony/yaml` を追加する（`composer require --dev symfony/yaml`）
2. `composer.json` の `scripts` に追加する:
   ```json
   "mcp": "php vendor/hideyukimori/nene2/tools/validate-mcp-tools.php --root=."
   ```
3. `docs/mcp/tools.json` と `docs/openapi/openapi.yaml` を作成する
4. `composer mcp` を実行する

### ローカル AI・MCP で使用可能なコマンド

```bash
docker compose run --rm app composer check
docker compose run --rm app composer openapi
docker compose run --rm app composer mcp
npm run check --prefix frontend
git diff --check
curl -fsS http://localhost:8080/health
curl -fsS http://localhost:8080/openapi.php
```

---

## 8. 開発コマンドリファレンス

### バックエンド（Docker 経由）

```bash
# セットアップ
docker compose build
docker compose run --rm app composer install

# 標準チェック（全部走る）
docker compose run --rm app composer check

# 個別
docker compose run --rm app composer test
docker compose run --rm app composer analyse
docker compose run --rm app composer cs
docker compose run --rm app composer cs:fix
docker compose run --rm app composer openapi
docker compose run --rm app composer mcp

# DB
docker compose run --rm app composer test:database
docker compose up -d mysql
docker compose run --rm app composer test:database:mysql

# FT MySQL 統合テスト（../NENE2-FT/ プロジェクト共通）
# コンテナ起動（port 3308、永続ボリューム ft_mysql_data）
docker compose -f ../NENE2-FT/docker-compose.yml up -d mysql
# テスト実行（MYSQL_HOST 未設定なら自動 skip）
MYSQL_HOST=127.0.0.1 MYSQL_PORT=3308 MYSQL_DATABASE=ft_test \
  MYSQL_USER=ft_user MYSQL_PASSWORD=ft_pass \
  php8.4 vendor/bin/phpunit --filter Mysql

# マイグレーション
docker compose run --rm app composer migrations:status
docker compose run --rm app composer migrations:migrate
docker compose run --rm app composer migrations:rollback
```

### フロントエンド

```bash
npm install --prefix frontend
npm run check --prefix frontend   # type-check + lint + format
npm run dev --prefix frontend
npm run build --prefix frontend
```

### ローカル HTTP スモーク

```bash
docker compose up -d app
curl -i http://localhost:8080/health
curl -i http://localhost:8080/examples/ping
# 保護されたエンドポイント
NENE2_MACHINE_API_KEY=local-dev-key docker compose up -d app
curl -i -H 'X-NENE2-API-Key: local-dev-key' http://localhost:8080/machine/health
```

---

## 9. エンドポイントスキャフォールド手順

新しい JSON エンドポイントを追加するたびに次の手順を踏む:

1. GitHub Issue を作成または再利用する。
2. ランタイムルートとハンドラーを追加する（`src/Http/RuntimeApplicationFactory.php` 参照）。
3. OpenAPI パスを追加する（`operationId` / success schema / Problem Details レスポンス / `ok` example）。
4. 動作に近いテストを追加する。
5. 最も狭い検証から実行する:
   ```bash
   docker compose run --rm app vendor/bin/phpunit tests/HttpRuntimeTest.php tests/OpenApi/RuntimeContractTest.php
   docker compose run --rm app composer check
   ```
6. Docker 経由でローカル HTTP スモークチェックを実行する。
7. エンドポイントが MCP ツールになる場合は `docs/mcp/tools.json` にエントリを追加して `composer mcp` を実行する。

---

## 10. セルフレビューチェックリスト

PR 前に作業タイプに合わせたチェックリストを `docs/review/` から使う:

| ファイル | 対象 |
|---|---|
| `docs/review/backend-api.md` | API エンドポイント・コントローラー・バリデーション |
| `docs/review/openapi-contract.md` | OpenAPI ドキュメント・スキーマ・例 |
| `docs/review/middleware-security.md` | ミドルウェア・認証・ロギング・CORS |
| `docs/review/database.md` | DB マイグレーション・リポジトリ |
| `docs/review/frontend.md` | React / TypeScript / Vite |
| `docs/review/view-rendering.md` | サーバー HTML・テンプレート |
| `docs/review/release-ci.md` | リリース・CI |
| `docs/review/docs-policy.md` | ドキュメント変更 |

PR の本文に使用したチェックリスト名を記載する（例: `Self-review: backend-api, middleware-security`）。

---

## 11. プロジェクトレイアウト

```
src/                  フレームワークコア
  Config/             型付き config オブジェクトとローダー
  DependencyInjection/ PSR-11 コンテナ・サービスプロバイダー
  Error/              例外 → レスポンスマッピング
  Http/               PSR HTTP ヘルパー・レスポンスファクトリ
  Middleware/         PSR-15 ミドルウェアパイプライン
  Routing/            ルート定義・ディスパッチ
  View/               ビューレンダリング抽象
  Database/           DB 接続・クエリ・トランザクション境界
  Mcp/                ローカル MCP サーバー
  Validation/         バリデーション例外・エラー

tests/                PHPUnit テスト（src/ を鏡像）
config/               シークレットなし設定例
database/             migrations / seeds / schema
templates/            ネイティブ PHP テンプレート
public_html/          Web ドキュメントルート（公開のみ）
  index.php           フロントコントローラー
  assets/             ビルド済みフロントエンドアセット
frontend/             React + TypeScript + Vite ソース
  src/
docs/                 ADR・マイルストーン・TODO・レビュー
tools/                バリデーションスクリプト
```

---

## 12. 設定・環境変数

`.env` の raw アクセスは `ConfigLoader` 内のみ。アプリコードは `AppConfig` / `DatabaseConfig` などの型付きオブジェクトを受け取る。

主な環境変数:

| 変数 | 用途 |
|---|---|
| `APP_ENV` | `local` / `test` / `production` |
| `APP_DEBUG` | boolean |
| `APP_NAME` | アプリ名 |
| `DATABASE_URL` | DB URL（設定するとホスト/ポート等の個別変数を上書き） |
| `DB_ADAPTER` | `sqlite` / `mysql` / `pgsql` |
| `DB_HOST` | DB ホスト（デフォルト: `127.0.0.1`） |
| `DB_PORT` | DB ポート（デフォルト: `3306`） |
| `DB_NAME` | DB 名（デフォルト: `nene2`） |
| `DB_USER` | DB ユーザー（デフォルト: `nene2`） |
| `DB_PASSWORD` | DB パスワード — **`DB_PASS` ではなく `DB_PASSWORD`**（ConfigLoader が読む） |
| `DB_CHARSET` | 文字セット（デフォルト: `utf8mb4`） |
| `DB_ENV` | Phinx 用環境名（デフォルト: `local`） |
| `NENE2_MACHINE_API_KEY` | マシンクライアント API キー（未設定でパブリックのみ） |
| `NENE2_LOCAL_JWT_SECRET` | HMAC-HS256 シークレット（Bearer JWT / MCP 書き込みツール保護） |
| `NENE2_LOCAL_API_BASE_URL` | ローカル MCP サーバーの API ベース URL |
| `PROBLEM_DETAILS_BASE_URL` | Problem Details type URI のベース URL（デフォルト: `https://nene2.dev/problems/`） |

コミット禁止: `.env` / `.env.local` / `.env.*.local` / パスワード / トークン / 本番認証情報。

---

## 13. フロントエンド

- React + TypeScript + Vite が公式スターター方向。
- フレームワークコアはフロントエンドパッケージに依存させない。
- パッケージマネージャー: npm（`package-lock.json` をコミット）。
- Node.js: active LTS（`>=22 <25`）。
- ビルド出力: `public_html/assets/`（`node_modules/` はコミットしない）。
- API クライアントは `frontend/src/api/` の型付き fetch ラッパー。

---

## 14. ADR ポリシー

ADR を書くべきタイミング:

- 公開 PHP API / HTTP ランタイムアーキテクチャ
- 依存パッケージの選択
- フロントエンドスターターのアーキテクチャ
- OpenAPI 契約戦略
- リリース・バージョニング・互換性ポリシー
- DB・マイグレーション戦略
- オブザーバビリティ・セキュリティアーキテクチャ
- AI / MCP 統合境界

ADR ファイル: `docs/adr/NNNN-kebab-case-title.md`、テンプレート: `docs/adr/0000-template.md`

---

## 15. リリース・バージョニング

- Semantic Versioning。現在 `v1.5.x`。
- タグは `main` のコミットを指す（`v1.5.NNN` 形式）。
- タグを push したら対応する GitHub Release も作成する（`gh release create`）。
- リリース前に `docs/development/release-checklist.md` を使う。
- Packagist 公開済み: `composer require hideyukimori/nene2`

---

## 16. 言語ポリシー

**英語で書く**:
- `README.md` / `docs/development/` / `docs/integrations/` / `docs/adr/` / `docs/review/`
- OpenAPI / Problem Details `title` / `type` / validation `code`
- コード識別子（クラス名・メソッド名・変数名）
- パッケージメタデータ・リリースノート

**日本語を使用可能**:
- `.cursor/rules/` / GitHub Issues・PR ボディ / AI 協業メモ
- ローカル TODO・引き継ぎノート（チームが日本語使用の場合）
- コミット description と body

日本語のローカルノートが英語ポリシードキュメントと矛盾する場合は **英語ドキュメントを先に更新する**。

---

## 17. AI エージェントの責任範囲

通常の作業フロー（明示的に制限されない限り）:

1. Issue を作成または再利用する。
2. ロードマップ・マイルストーン・TODO の状態を確認する。
3. Issue 番号付きブランチを `main` から作成する。
4. 関連ファイルのみ編集する。
5. 該当するセルフレビューチェックリストを確認する。
6. 変更を検証する（最も狭い有用なコマンドを使う）。
7. Conventional Commits でコミットし、push、PR 作成、マージ、`main` 同期まで行う。
8. プロジェクト状態を記述するローカルドキュメントを更新する。

ユーザーが明示的にスコープを制限した場合（「調査のみ」「コミットしない」等）はその指示に従う。

---

## 18. FT ループ作業ルール

FT（フィールドトライアル）を連続してこなすときの専用ルール。

### セッション開始時

1. **`docs/todo/current.md` を必ず読む** — 最終 FT 番号・VERSION・次の ATK/VULN サイクルを確認する。
2. `bash tools/uncovered-fts.sh` で未カバー FT を確認し、次の対象を選ぶ。
3. 対象 FT のテストが全通過することを確認する:
   ```bash
   cd ../NENE2-FT/<name> && php8.4 vendor/bin/phpunit tests/ --no-coverage
   ```

### 各 FT の作業フロー

```
1. docs/howto/<topic>.md が既存か ls で確認 → 既存なら Write でなく Edit を使う
2. GitHub Issue 作成
3. git checkout -b docs/<issue>-ft<N>-<name>
4. bash tools/bump-ft.sh <new-version>  # FrameworkInfo.php + openapi.yaml を一括バンプ
5. CHANGELOG.md を Read してから Edit（ブランチ切替後は必ず Read が必要）
6. docs/howto/<topic>.md を Write または Edit
7. docker compose run --rm app composer check
8. git commit / push / gh pr create / CI 待ち / merge / git pull
```

### ATK / VULN サイクル

- **ATK**（クラッカー攻撃試験）: **4 件ごと**（FT252, 256, 260, 264, 268, 272...）
- **VULN**（脆弱性診断）: **6 件ごと**（FT249, 255, 261, 267, 273...）
- 現在のサイクル状態は `docs/todo/current.md` に記載。

### ATK / VULN howto テンプレート

ATK や VULN を書くときは以下の骨格を使う（毎回ゼロから書かなくてよい）。

#### ATK セクション骨格

```markdown
## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — [攻撃名] 🚫 BLOCKED

**Attack**: [具体的な攻撃手法の説明]
**Result**: BLOCKED — [防御できた理由、どのコードが防いだか]

---

### ATK-02 — [攻撃名] 🚫 BLOCKED

**Attack**: ...
**Result**: BLOCKED — ...

---

<!-- ATK-03 〜 ATK-12 を同形式で続ける -->

---

### ATK Summary

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | [攻撃名] | 🚫 BLOCKED |
| ATK-02 | [攻撃名] | 🚫 BLOCKED |
| ...  | ...  | ... |

**N BLOCKED / SAFE, 0 EXPOSED**
重大な発見があればここに要約する。なければ "No critical findings." と書く。
```

**絵文字ルール**: `🚫 BLOCKED`（防御済み）/ `⚠️ EXPOSED`（脆弱性あり）/ `✅ SAFE`（そもそも攻撃面なし）

#### VULN セクション骨格

```markdown
## Vulnerability Assessment

### V-01 — [脆弱性名] ✅ SAFE / ⚠️ EXPOSED

**Risk**: [リスクの説明]
**Finding**: SAFE / EXPOSED — [判定理由]

---

<!-- V-02 〜 V-10 を同形式で続ける -->

---

### VULN Summary

| ID | Vulnerability | Finding |
|----|---------------|---------|
| V-01 | [脆弱性名] | ✅ SAFE |
| ...  | ... | ... |

**N SAFE, M EXPOSED**
重大な発見があればここに要約する。なければ "No critical findings." と書く。
```

---

### 引き継ぎドキュメント更新

**5FT ごと**またはセッション終了時に `docs/todo/current.md` を更新する:

```
- 最終完了 FT 番号と VERSION
- 次の FT 番号
- 次の ATK 回・VULN 回の FT 番号
- 直近 10 件の FT 履歴
- 進行中ブランチがあれば記録
```

### ツールリファレンス

```bash
bash tools/uncovered-fts.sh              # 未カバー FT 一覧
bash tools/uncovered-fts.sh --all        # 全件（✅/❌ 表示）
bash tools/uncovered-fts.sh --check cartlog  # 特定 FT の確認
bash tools/bump-ft.sh 1.5.241            # バージョン一括バンプ
```

---

## 19. フィールドトライアルプロジェクトでの NENE2 参照先

**重要**: このリポジトリ（`../NENE2/`）は開発版（リリース前の HEAD）。
フィールドトライアルプロジェクトからこのソースを参照するときは以下に注意すること。

| 目的 | 参照先 |
|---|---|
| 設計パターン・アーキテクチャの参考 | `../NENE2/src/` — 最新の実装例として使う |
| **実際のメソッドシグネチャ・クラス一覧** | `vendor/hideyukimori/nene2/` — インストール済みを優先 |
| 安定 API の確認 | `../NENE2/docs/adr/0009-v1.0-public-api-scope.md` |

`../NENE2/src/` に存在するクラスや引数が `vendor/` には無いことがある（リリース前の機能）。
PHPStan level 8 でキャッチできるため、静的解析を早めに実行して乖離を発見すること。
