# Field Trial 20 — PostgreSQL による NENE2 v1.5.3 実地検証

## Date

2026-05-20

## Baseline

- NENE2 v1.5.3（`hideyukimori/nene2: ^1.5`、Packagist から取得）
- PHP 8.4 + PostgreSQL 17（Docker compose、`postgres:17` イメージ）
- プロジェクト: **reviewlog** — 書籍レビュー管理 API
- エンティティ: `Review`（book_title, content, rating 1–5, created_at）
- マイグレーション: Phinx 0.16（`pgsql` アダプター）
- テスト: PHPUnit 13/13・PHPStan level 8・PHP-CS-Fixer 全通過
- DB リセット: `TRUNCATE reviews RESTART IDENTITY CASCADE`

## Goal

SQLite・MySQL で動作実績がある NENE2 を PostgreSQL（`pgsql` アダプター）で動かし、
固有の摩擦を記録する。

検証ポイント:
- `PdoDatabaseQueryExecutor::lastInsertId()` の PostgreSQL 互換性
- Phinx `pgsql` アダプターのマイグレーション動作
- `DatabaseConfig::charset` の pgsql DSN への反映
- PostgreSQL 固有 SQL（`RETURNING id`・`TRUNCATE RESTART IDENTITY`）と NENE2 インターフェースの相性

---

## 実装ログ

1. `composer require hideyukimori/nene2:^1.5` — v1.5.3 インストール成功。
2. `Dockerfile` に `pdo_pgsql` 追加（`docker-php-ext-install pdo pdo_pgsql`）。
3. `compose.yaml` に `postgres:17` サービス追加（`pg_isready` ヘルスチェック）。
4. `phinx.php` で `charset` を除いた pgsql 設定を構築。
5. `PgsqlReviewRepository::save()` に `RETURNING id` パターン実装（F-1 回避）。
6. `composer migrations:migrate` — `CreateReviewsTable` 適用成功。
7. テスト実行 → 3 本失敗（`ReviewNotFoundExceptionHandler` の API 呼び出し誤り — F-2）。
8. `ProblemDetailsResponseFactory::create()` シグネチャを修正（`$request` を第 1 引数に追加）。
9. テスト 13 本全通過。PHPStan level 8: 0 エラー。PHP-CS-Fixer: 0 件。

---

## 摩擦記録

### F-1（高）: `lastInsertId()` が PostgreSQL で空文字を返す

**状況**: `DatabaseQueryExecutorInterface::lastInsertId()` の実装（`PdoDatabaseQueryExecutor`）は
`PDO::lastInsertId()` を引数なしで呼ぶ。PostgreSQL では引数なしの場合に **空文字列** が返るため、
`(int) ''` = 0 となり INSERT 直後の ID が取得できない。

```php
// PdoDatabaseQueryExecutor — PostgreSQL では空文字を返す
public function lastInsertId(): int
{
    return (int) $this->connection()->lastInsertId(); // pgsql: returns ''
}
```

**回避策**: `fetchOne` に `RETURNING id` を追加した INSERT SQL を渡すことで、
生成された ID を単一ラウンドトリップで取得できる。

```php
// PgsqlReviewRepository::save() での回避策
$row = $this->executor->fetchOne(
    'INSERT INTO reviews (book_title, content, rating, created_at) VALUES (?, ?, ?, ?) RETURNING id',
    [$bookTitle, $content, $rating, $now],
);
$id = (int) ($row['id'] ?? 0);
```

`fetchOne` を INSERT に使う点が意味論的にやや奇妙だが、動作は正しく PHPStan も通る。

**期待する解決策**:
- `DatabaseQueryExecutorInterface` に `insertAndGetId(string $sql, array $params = []): int` を追加し、
  PostgreSQL では `RETURNING id` を自動付与して `fetchOne` に委譲する。
- または `lastInsertId(string $sequenceName = '')` とシグネチャを拡張し、
  pgsql 向けにシーケンス名を渡せるようにする。
- `docs/howto/` に「PostgreSQL での INSERT ID 取得」パターンをドキュメント化する。

---

### F-2（中）: `ProblemDetailsResponseFactory::create()` の引数変更がドキュメントにない

**状況**: v1.5 で `ProblemDetailsResponseFactory::create()` の第 1 引数に
`ServerRequestInterface $request` が追加された。

```php
// v1.5 以降のシグネチャ（vendor/ の実体）
public function create(
    ServerRequestInterface $request,  // ← 追加された
    string $type,
    string $title,
    int $status,
    ?string $detail = null,
    array $extensions = [],
): ResponseInterface
```

`DomainExceptionHandlerInterface::handle()` の第 2 引数には `$request` が渡されるため、
正しくは `$this->probs->create($request, 'not-found', 'Not Found', 404)` と呼ぶ必要がある。

しかし howto や既存のフィールドトライアルレポートには named arguments を使った古い例が残っており、
`status:` を第 1 引数として渡す誤りを PHPStan がキャッチできない（実行時エラー）。

```php
// 誤った呼び出し（実行時 ArgumentCountError）
return $this->probs->create(
    status: 404,
    type: 'https://nene2.dev/problems/not-found',  // URL ではなくスラグを渡すべき
    title: 'Not Found',
    detail: $exception->getMessage(),
);

// 正しい呼び出し
return $this->probs->create(
    request: $request,
    type: 'not-found',   // スラグのみ（ファクトリがベース URL を付与）
    title: 'Not Found',
    status: 404,
    detail: $exception->getMessage(),
);
```

また `type` にフル URL を渡してしまう誤りも起きやすい（ファクトリ側でベース URL を付与するため、
フル URL を渡すと `https://nene2.dev/problems/https://nene2.dev/problems/not-found` になる）。

**期待する解決策**:
- `docs/howto/` に `DomainExceptionHandlerInterface` の実装例を追加し、
  `ProblemDetailsResponseFactory::create()` の正しい呼び出し方を示す。
- `add-domain-exception-handler.md` のような howto があると consumer が参照できる。

---

### F-3（低）: テストのテーブルリセットが DB 固有

**状況**: テスト間のデータ分離のため `setUp()` でテーブルをリセットする際、
DB ごとに異なる SQL が必要になる。

| DB | リセット SQL |
|---|---|
| PostgreSQL | `TRUNCATE reviews RESTART IDENTITY CASCADE` |
| MySQL | `TRUNCATE reviews`（AUTO_INCREMENT は自動リセット） |
| SQLite | `DELETE FROM reviews; DELETE FROM sqlite_sequence WHERE name='reviews'` |

今回の `TRUNCATE RESTART IDENTITY` は PostgreSQL 専用で、MySQL や SQLite に対して実行するとエラーになる。
NENE2 のドキュメントにはこのパターンが記載されていない。

**期待する解決策**: `docs/howto/use-transactions.md` または新しい `testing-with-database.md` に
各 DB アダプターでのテストデータリセットパターンを追記する。

---

### F-4（低）: `pgsql` DSN に `charset` が含まれない

**状況**: `PdoConnectionFactory` の pgsql DSN 生成は `charset` を含まない。

```php
'pgsql' => sprintf(
    'pgsql:host=%s;port=%d;dbname=%s',
    // charset は含まれない
    $this->config->host,
    $this->config->port,
    $this->config->name,
),
```

`DatabaseConfig::charset` に `'utf8'` を設定しても pgsql では無視される。
PostgreSQL のクライアントエンコーディングを明示したい場合は `DATABASE_URL` を使う
（`pgsql://host/db?client_encoding=UTF8`）か、接続後に `SET client_encoding` を実行する必要がある。

MySQL のクセで `DB_CHARSET=utf8mb4` を設定していても pgsql では影響がなく、実害はないが
NENE2 のドキュメントにこの挙動が記載されていない。

**期待する解決策**: `PdoConnectionFactory` の pgsql DSN に `options` としてエンコーディングを
付与するか、ドキュメントで「pgsql では charset は無視される」と明記する。

---

## テストカバレッジ

| テスト | 検証内容 |
|---|---|
| `testListReturnsEmptyInitially` | GET /reviews → 200 空配列 |
| `testCreateReview` | POST /reviews → 201、全フィールド確認 |
| `testGetReviewById` | GET /reviews/{id} → 200 |
| `testDeleteReview` | DELETE /reviews/{id} → 200、その後 404 |
| `testListReturnsAllReviews` | 複数 POST 後 GET /reviews → 全件取得 |
| `testListFiltersByRating` | GET /reviews?rating=5 → rating=5 のみ返す |
| `testListFilterByInvalidRatingReturns422` | GET /reviews?rating=9 → 422 |
| `testCreateRejectsEmptyBookTitle` | 空 book_title → 422 |
| `testCreateRejectsInvalidRating` | rating=6 → 422 |
| `testCreateRejectsMissingContent` | content 欠落 → 422 |
| `testGetNonExistentReviewReturns404` | 存在しない ID → 404 |
| `testDeleteNonExistentReviewReturns404` | 存在しない ID → 404 |
| `testConsecutiveInsertsHaveSequentialIds` | **RETURNING id 検証**: 連続 INSERT で id が連番になる |

**合計**: 13/13 通過

---

## 総評

NENE2 v1.5.3 は PostgreSQL で動作する。`pgsql` アダプターの DSN 生成・Phinx マイグレーション・
クエリ実行はすべて正常に動作した。

最大の摩擦は `lastInsertId()` の非互換（F-1）。`fetchOne` + `RETURNING id` で回避できるが、
consumer が自力で発見する必要があり、ドキュメントもない。`insertAndGetId` のような専用メソッドか
howto があれば解消できる。

F-2（`ProblemDetailsResponseFactory::create()` の引数）は PostgreSQL 固有ではなく
v1.5 アップグレード時の breaking change に近いが、今回初めてこのシグネチャで実装して気づいた。

次のアクション候補:
1. F-1 → `DatabaseQueryExecutorInterface::insertAndGetId()` 追加、または howto で `RETURNING id` パターンを文書化
2. F-2 → `docs/howto/add-domain-exception-handler.md` 新規作成
3. F-3 → `testing-with-database.md` または既存 howto へのテストリセットパターン追記
4. F-4 → pgsql charset ドキュメント整備（低優先度）
