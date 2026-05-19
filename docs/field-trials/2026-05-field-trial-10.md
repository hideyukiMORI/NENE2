# Field Trial 10 — hoplog: 独立プロジェクトとしての NENE2 v1.4 実地検証

## Date

2026-05-19

## Baseline

- NENE2 v1.4.x（`hideyukimori/nene2: ^1.4`）
- PHP 8.4.21（Docker: `php:8.4-cli` ベースイメージ）
- プロジェクト: **hoplog** — クラフトビールテイスティングノート JSON API
- エンティティ: `Brewery` / `Beer` / `TastingNote`（3ドメイン、各5エンドポイント、計15 routes）
- テスト: PHPUnit 13.1、PHPStan level 8、PHP-CS-Fixer 3.95
- DB: SQLite（ローカル）/ MySQL（本番想定）

## Goal

NENE2 サンプルコード（Note/Tag）を参照しながら、独立した新規プロジェクトを 0 から構築し、フレームワークの実用性・摩擦箇所・ドキュメントギャップを記録する。

---

## Steps Taken

### 1. プロジェクト骨格の構築

`composer.json` に `hideyukimori/nene2: ^1.4` を追加。`composer install` で依存解決。
Brewery ドメインから実装を開始し、Note/Tag の実装を参照しながら Handler → UseCase → Repository → ServiceProvider → RouteRegistrar の順に構築した。

**Finding**: パターンが一貫していて、2つ目のドメイン（Beer）は1つ目（Brewery）をほぼそのまま模倣するだけで済んだ。コピーの方向性が明確で学習曲線が緩やか。

### 2. DI コンテナのオーバーライド

`RuntimeServiceProvider` を追加後、`RuntimeApplicationFactory` を hoplog 独自のルート/例外ハンドラで置き換えようとした。

```php
$builder->addProvider(new RuntimeServiceProvider());
// ↓ この順序が必須
$builder->set(RuntimeApplicationFactory::class, static function (...) {
    return new RuntimeApplicationFactory(..., [$breweryRegistrar, ...]);
});
```

**Finding (F-1)**: `RuntimeServiceProvider` が内部で `RuntimeApplicationFactory`（Note/Tag ルート付き）を登録済みである。後から `$builder->set()` で上書きできるが、「後勝ち」というルールはドキュメントに記載がない。ここで詰まった場合、コンテナ実装を読まなければ解決できない。

### 3. パス・パラメータの取得

ハンドラ内でパスパラメータ（`/breweries/{id}` の `id`）を取り出す際、正しい書き方が分からなかった。

```php
// 正解
$params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
$id = (int) $params['id'];
```

**Finding (F-2)**: `Router::PARAMETERS_ATTRIBUTE` の存在と使い方は、リファレンスドキュメントに記載がない。Note の実装を grep して発見した。新規参入者は NENE2 の内部ソースを読まなければ気づけない。

### 4. Dockerfile の構築

`php:8.4-cli` ベースイメージでは Composer が未インストール。また `pdo_sqlite` のビルドに `libsqlite3-dev` が必要だが、イメージに含まれていない。

```dockerfile
RUN apt-get update && apt-get install -y libsqlite3-dev libonig-dev curl unzip \
    && docker-php-ext-install pdo pdo_sqlite pdo_mysql \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
```

**Finding (F-3)**: NENE2 には推奨 Dockerfile が存在しない。`php:8.4-cli` を選択した場合に何が必要かを How-to ドキュメントや `compose.yaml` のコメントで示すと、新規プロジェクトの立ち上げ摩擦が下がる。

### 5. 環境変数の命名（SQLite）

SQLite を使う場合、ファイルパスの指定に `DB_SQLITE_PATH` という変数名を想定して `.env` を記述した。実際には NENE2 が読む変数は `DB_NAME` であり、SQLite アダプターでもこの変数にパスを書く必要がある。

```dotenv
# 誤（動かない）
DB_ADAPTER=sqlite
DB_SQLITE_PATH=/tmp/hoplog.sqlite

# 正（NENE2 の実際の変数名）
DB_ADAPTER=sqlite
DB_NAME=/tmp/hoplog.sqlite
DB_HOST=localhost    # SQLite では無視されるが必須フィールド
DB_USER=hoplog       # 同上
DB_CHARSET=utf8mb4   # 同上
```

**Finding (F-4)**: SQLite の場合、`DB_HOST` / `DB_USER` / `DB_CHARSET` は接続に使われないが、`DatabaseConfig` のバリデーションで空文字を拒否されるため、ダミー値の設定が必要。この制約はドキュメントに記載がない。また `DB_NAME` が「ファイルパス」として機能することも、変数名から直感的に分からない。

### 6. 内部エラーの可視性（APP_DEBUG=true でも 500 が不透明）

環境変数の誤設定（F-4）により、全ての POST/GET が 500 を返した。`APP_DEBUG=true` を設定していたが、レスポンスは常に汎用の Problem Details のみ。ログ（INFO レベル）にもスタックトレースは出力されなかった。

```json
{
    "type": "https://nene2.dev/problems/internal-server-error",
    "title": "Internal Server Error",
    "status": 500,
    "detail": "The server encountered an unexpected condition."
}
```

コンテナ内で PHP を直接実行してようやく原因を特定できた。

**Finding (F-5)**: `APP_DEBUG=true` の場合、例外の `getMessage()` や `getTrace()` を `detail` に含めるか、少なくとも DEBUG レベルでログに記録する機能があると、ローカル開発の体験が大きく改善する。本番では絶対に出すべきではないが、開発中にレスポンスか標準ログを見るだけで原因が分かるのが理想。

### 7. SQLite スキーマの自動適用

コンテナを再起動すると `/tmp/hoplog.sqlite` が消える（`/tmp` はコンテナの揮発領域）。スキーマを毎回手動で適用する必要がある。NENE2 の MySQL 向けには Phinx マイグレーションがあるが、SQLite + 開発用の「初回起動時スキーマ適用」に相当する仕組みがない。

**Finding (F-6)**: ローカル開発において SQLite のスキーマ初期化を自動化する薄いユーティリティ（例: `composer db:init`、あるいはサーバー起動前フックとして `public/index.php` で DB ファイルの存在チェック）があると便利。現状は新規プロジェクト側で自前実装が必要。

### 8. ValidationException の手動スロー

`PaginationQueryParser` は `ValidationException` を自動でスローするが、ハンドラ内でドメイン固有のバリデーション（例: `overall` が 1〜5 の範囲）を実施する際は自前で `ValidationException` を構築する必要がある。

```php
// ValidationException の構築方法は Example ソースを読んで推測した
throw new ValidationException([
    new ValidationError(field: 'overall', message: 'Overall must be between 1 and 5.', code: 'out_of_range'),
]);
```

**Finding (F-7)**: `ValidationException` / `ValidationError` の構築方法（引数、`code` の慣習的な値）がリファレンスに記載されていない。コード例が一つあるだけで解決する。

### 9. リスト応答に `total` がない

`PaginationQueryParser` が返す `ListBreweriesOutput` は `items` / `limit` / `offset` を持つが、全件数 `total` がない。クライアント（SPA など）が次のページの有無を判定するには `items.length < limit` という推測に頼るか、追加リクエストが必要になる。

**Finding (F-8)**: `total` フィールドをリポジトリ側で `COUNT(*)` して返すパターンを、推奨設計として示す（または `PaginationQueryParser` と対になる `PaginationResponse` DTO に `total` を含める）と良い。現状はプロジェクト側で独自実装が必要。

### 10. PHP-CS-Fixer — `--allow-risky=yes` が必要

NENE2 の `.php-cs-fixer.php` サンプルに `declare_strict_types` ルールが含まれる場合、`risky` フィクサーとして扱われ、`--allow-risky=yes` なしでは `check` コマンドがエラー終了する。

```bash
# エラー
vendor/bin/php-cs-fixer check --diff
# → "rules contain risky fixers, but they are not allowed to run"

# 正解
vendor/bin/php-cs-fixer check --diff --allow-risky=yes
```

**Finding (F-9)**: NENE2 の `composer.json` サンプルスクリプトに `--allow-risky=yes` を含める、あるいはドキュメントに注記を追加する。

### 11. 最終 composer check 結果

```
PHPUnit 13.1.10 — 18 tests, 62 assertions — OK
PHPStan level 8 — No errors
PHP-CS-Fixer — Found 0 of 104 files that can be fixed
```

---

## Results

| シナリオ | 期待 | 実際 | 状態 |
|---|---|---|---|
| 3ドメイン・15ルートの構築 | Note/Tag パターンで実装できる | ✓ | Pass |
| PHPUnit 統合テスト（InMemoryRepository）| 全テスト通過 | 18/18 Pass | Pass |
| PHPStan level 8 | エラーなし | ✓ | Pass |
| PHP-CS-Fixer | 差分なし | ✓（`--allow-risky=yes` 追加後）| Pass |
| ContainerBuilder の上書き | 後勝ちで RuntimeApplicationFactory を差し替え | ✓ | Pass（F-1 で詰まった） |
| SQLite 接続 | `DB_NAME` にパスを設定すれば動く | ✓ | Pass（F-4 で詰まった） |
| PaginationQueryParser | `?limit=&offset=` が動く | ✓ | Pass |
| 422 バリデーションエラー | `errors` 配列で返る | ✓ | Pass |
| 404 NotFound（カスタム例外） | Problem Details で返る | ✓ | Pass |
| APP_DEBUG=true でのデバッグ | 原因が分かる | ✗（汎用 500 のみ） | F-5 |

---

## Friction Summary

| ID | 箇所 | 深刻度 | 種別 |
|---|---|---|---|
| F-1 | ContainerBuilder の後勝ちルール | 中 | ドキュメント欠如 |
| F-2 | `Router::PARAMETERS_ATTRIBUTE` の存在 | 高 | ドキュメント欠如 |
| F-3 | Dockerfile / 必要パッケージが未提示 | 中 | ドキュメント欠如 |
| F-4 | SQLite 時の `DB_NAME` とダミー必須フィールド | 高 | ドキュメント欠如 + 設計 |
| F-5 | `APP_DEBUG=true` でも例外詳細が非表示 | 高 | 機能改善余地 |
| F-6 | SQLite スキーマの自動初期化がない | 低 | 機能改善余地 |
| F-7 | `ValidationException` / `ValidationError` の構築 | 中 | ドキュメント欠如 |
| F-8 | リスト応答に `total` がない | 低 | 設計上のトレードオフ |
| F-9 | PHP-CS-Fixer `--allow-risky=yes` | 低 | ドキュメント欠如 |

---

## Recommendations

### 即対応（ドキュメント追加で解決できる）

1. **`Router::PARAMETERS_ATTRIBUTE` のリファレンス記載（F-2）**
   ハンドラからパスパラメータを取り出す方法を「Handler の書き方」セクションに明記する。
   ```php
   $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
   $id = (int) $params['id'];
   ```

2. **SQLite 環境変数の説明（F-4）**
   `docs/reference/environment-variables.md` に「SQLite アダプター使用時は `DB_NAME` にファイルパスを設定し、`DB_HOST` / `DB_USER` / `DB_CHARSET` にはダミー値が必要」と明記する。

3. **`ContainerBuilder::set()` の後勝ちルール（F-1）**
   既存の `addProvider()` 後に `set()` で上書きできることを、How-to「フレームワーク上にアプリを構築する」ページで説明する。

4. **`ValidationException` の構築例（F-7）**
   ハンドラ内でカスタムバリデーションを行う際のコード例をチュートリアルまたはリファレンスに追加する。

5. **PHP-CS-Fixer `--allow-risky=yes`（F-9）**
   サンプル `composer.json` または Getting Started に注記を追加する。

### 推奨対応（機能・設計）

6. **`APP_DEBUG=true` 時の例外詳細ログ出力（F-5）**
   `APP_DEBUG=true` の場合のみ、500 エラーの `detail` に `$e->getMessage()` を含める、または DEBUG レベルのログにスタックトレースを出力する。本番環境（`APP_ENV=production`）では必ず汎用メッセージを返す。

7. **Dockerfile / Docker How-to（F-3）**
   `php:8.4-cli` ベースで新規プロジェクトを立ち上げる際の推奨 Dockerfile（`libsqlite3-dev` / `pdo_sqlite` / `pdo_mysql` / Composer インストール）を How-to ドキュメントに掲載する。

### 検討事項（トレードオフあり）

8. **リスト応答の `total`（F-8）**
   `COUNT(*)` のオーバーヘッドを許容できるなら `PaginationResponse` DTO に `total` フィールドを追加する。許容できない場合は「`total` をオプトインで取得する方法」をリファレンスで説明する（例: 別途 `HEAD /breweries` か `?count=true`）。

9. **SQLite スキーマ自動初期化（F-6）**
   `composer db:init` スクリプトを新規プロジェクト向けに用意するか、`public/index.php` のボイラープレートに「DB ファイルが存在しなければスキーマを適用する」パターンのコードコメントを添付する。

---

## Overall Impression

NENE2 のアーキテクチャは **一貫性が高く、学習コストが低い**。Note/Tag のサンプル実装を一度読めば、3ドメインの CRUD API を短時間で構築できた。`PaginationQueryParser` / `JsonRequestBodyParser` / `ProblemDetailsResponseFactory` という三点セットは特に実用的で、ボイラープレートを大幅に削減できる。

摩擦の大半は**ドキュメントの欠如**に起因しており、コード品質やアーキテクチャの問題ではない。特に F-2（`Router::PARAMETERS_ATTRIBUTE`）と F-4（SQLite の環境変数）は、新規参入者が確実に詰まるポイントであり、短いリファレンス追記で解消できる。

F-5（デバッグ時の例外詳細非表示）だけは機能改善が必要で、`APP_DEBUG=true` の開発体験に直接影響する。

---

## Follow-up Issues

- [ ] docs: `Router::PARAMETERS_ATTRIBUTE` をハンドラリファレンスに追記
- [ ] docs: SQLite 環境変数（`DB_NAME` + ダミー必須フィールド）を記載
- [ ] docs: `ContainerBuilder::set()` 後勝ちルールを How-to に追記
- [ ] docs: `ValidationException` 構築例をチュートリアルに追加
- [ ] docs: PHP-CS-Fixer `--allow-risky=yes` を Getting Started に注記
- [ ] docs: Dockerfile How-to（`php:8.4-cli` + `libsqlite3-dev` + Composer）を追加
- [ ] feat: `APP_DEBUG=true` 時に例外メッセージをレスポンスまたはログに出力
- [ ] chore: リスト応答の `total` フィールド追加を検討
