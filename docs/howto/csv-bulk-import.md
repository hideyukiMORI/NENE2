---
title: "CSV バルクインポート API の実装ガイド"
category: api-design
tags: [csv, bulk-import, partial-success, validation, history]
difficulty: intermediate
related: [bulk-operations-partial-success, batch-api-partial-success]
---

# CSV バルクインポート API の実装ガイド

## 概要

このガイドでは NENE2 を使って CSV バルクインポート API を実装する方法を説明します。
行単位バリデーション・部分成功・エラー収集・インポート履歴管理を REST API として提供します。

---

## DB スキーマ

```sql
CREATE TABLE import_jobs (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    filename      TEXT    NOT NULL,
    status        TEXT    NOT NULL DEFAULT 'completed',
    total_rows    INTEGER NOT NULL DEFAULT 0,
    imported_rows INTEGER NOT NULL DEFAULT 0,
    failed_rows   INTEGER NOT NULL DEFAULT 0,
    errors        TEXT    NOT NULL DEFAULT '[]',
    created_at    TEXT    NOT NULL,
    completed_at  TEXT
);

CREATE TABLE imported_records (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    import_job_id INTEGER NOT NULL,
    name          TEXT    NOT NULL,
    email         TEXT    NOT NULL,
    age           INTEGER,
    created_at    TEXT    NOT NULL,
    FOREIGN KEY (import_job_id) REFERENCES import_jobs(id)
);
```

---

## エンドポイント設計

| メソッド | パス | 説明 |
|---|---|---|
| POST | `/imports` | CSV をインポート（同期処理、部分成功対応） |
| GET | `/imports` | インポートジョブ一覧 |
| GET | `/imports/{importId}` | インポート結果＋レコード取得 |

### リクエスト形式

```json
POST /imports
{
  "csv": "name,email,age\nAlice,alice@example.com,30\nBob,bob@example.com,25",
  "filename": "users.csv"
}
```

CSV は JSON ボディの `csv` フィールドに文字列として送る。これにより標準的な JSON API フローでテストしやすい。

---

## 実装

### CsvImporter（純粋パーサー）

```php
class CsvImporter
{
    private const array REQUIRED_HEADERS = ['name', 'email', 'age'];

    /** @return array{rows: list<...>, errors: list<...>} */
    public function parse(string $csv): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        // ...

        foreach ($lines as $i => $line) {
            // PHP 8.4: $escape パラメータを明示しないと deprecation
            $fields = str_getcsv($line, ',', '"', '\\');
            $fields = array_map(static fn(?string $f): string => trim((string) ($f ?? '')), $fields);

            if ($i === 0) {
                continue; // skip header
            }
            // ... バリデーションと収集
        }
    }

    public function validateHeader(string $csv): bool
    {
        $firstLine = strtok($csv, "\r\n");
        if ($firstLine === false) {
            return false;
        }
        $headers = array_map(
            static fn(?string $h): string => trim((string) ($h ?? '')),
            str_getcsv($firstLine, ',', '"', '\\'),
        );
        return array_map('strtolower', $headers) === self::REQUIRED_HEADERS;
    }
}
```

### RouteRegistrar（抜粋）

```php
private function handleCreateImport(ServerRequestInterface $request): ResponseInterface
{
    $body = (array) ($request->getParsedBody() ?? []);

    if (!isset($body['csv']) || !is_string($body['csv'])) {
        throw new ValidationException([new ValidationError('csv', 'csv is required', 'required')]);
    }

    $csv = $body['csv'];
    if (trim($csv) === '') {
        throw new ValidationException([new ValidationError('csv', 'csv must not be empty', 'required')]);
    }

    if (!$this->importer->validateHeader($csv)) {
        throw new ValidationException([
            new ValidationError('csv', 'CSV must have header row: name,email,age', 'invalid_format'),
        ]);
    }

    $parsed = $this->importer->parse($csv);
    $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');

    $jobId = $this->repo->createJob(
        $filename,
        count($parsed['rows']) + count($parsed['errors']),
        count($parsed['rows']),
        count($parsed['errors']),
        $parsed['errors'],
        $now,
    );

    foreach ($parsed['rows'] as $row) {
        $this->repo->insertRecord($jobId, $row['name'], $row['email'], $row['age'], $now);
    }

    return $this->json->create($this->formatJob($this->repo->findJob($jobId)), 201);
}
```

---

## 設計のポイント

### PHP 8.4: str_getcsv() の $escape 必須化

PHP 8.4 で `str_getcsv()` の `$escape` パラメータが必須になった（デフォルト値変更への移行期間）。
明示しないと deprecation が発生する。

```php
// NG: PHP 8.4 deprecation
$fields = str_getcsv($line);

// OK: $escape を明示する（RFC 4180 互換）
$fields = str_getcsv($line, ',', '"', '\\');
```

また、`str_getcsv()` は空フィールドに `null` を返すことがある。PHP 8.4 では `trim(null)` も deprecation になるため明示的に処理する:

```php
$fields = array_map(static fn(?string $f): string => trim((string) ($f ?? '')), $fields);
```

### 部分成功パターン

バルクインポートでは「全行成功か全行失敗か」ではなく **有効行のみインポート + 無効行のエラー収集** が実用的:

```php
$parsed = $this->importer->parse($csv);
// $parsed['rows'] = 有効行リスト → INSERT
// $parsed['errors'] = [{row: 3, value: "bad@", error: "invalid email format"}, ...]
```

レスポンスで `imported_rows` / `failed_rows` / `errors` を返す:

```json
{
  "imported_rows": 4,
  "failed_rows": 1,
  "errors": [{"row": 3, "value": "bad-email", "error": "invalid email format"}]
}
```

### バッチ内重複メール検知

同じ CSV ファイルに同一メールが複数行含まれていても DB 制約に頼るのではなく、
インポーター側でハッシュマップを使って先行して検知する:

```php
$seenEmails = [];
// ...
if (isset($seenEmails[$email])) {
    $rowErrors[] = 'duplicate email in import batch';
}
// ...
$seenEmails[$email] = true;
```

DB 制約エラーをキャッチする方法は、行が INSERT されたかどうかが不明になり、
エラーメッセージも不明瞭になる。先行検知の方が明示的で UX が良い。

### CRLF 対応

Windows 生成 CSV は `\r\n` で改行される。`preg_split('/\r\n|\r|\n/', ...)` で統一処理:

```php
$lines = preg_split('/\r\n|\r|\n/', trim($csv));
```

### errors フィールドの JSON 永続化

`errors` は DB の TEXT 列に JSON 文字列として保存し、取得時にデコードする:

```php
// 保存
json_encode($errors)

// 取得・フォーマット
$errors = json_decode((string) $job['errors'], true) ?? [];
```

SQLite に JSON 型はないため TEXT で代用する。MySQL も同様（JSON 型を使っても良いが互換性のため TEXT にした）。

---

## レスポンス例

### POST /imports（部分成功）

```json
{
  "id": 1,
  "filename": "users.csv",
  "status": "completed",
  "total_rows": 3,
  "imported_rows": 2,
  "failed_rows": 1,
  "errors": [
    {"row": 3, "value": "bad-email", "error": "invalid email format"}
  ],
  "created_at": "2026-01-01T00:00:00Z",
  "completed_at": "2026-01-01T00:00:00Z"
}
```

### GET /imports/{id}（レコード含む）

```json
{
  "id": 1,
  "filename": "users.csv",
  "status": "completed",
  "total_rows": 2,
  "imported_rows": 2,
  "failed_rows": 0,
  "errors": [],
  "records": [
    {"id": 1, "name": "Alice", "email": "alice@example.com", "age": 30, "created_at": "..."},
    {"id": 2, "name": "Bob",   "email": "bob@example.com",   "age": null, "created_at": "..."}
  ]
}
```

---

## MySQL 統合テスト

MySQL 環境では `MYSQL_HOST` 環境変数を設定して統合テストを実行する:

```bash
MYSQL_HOST=127.0.0.1 MYSQL_PORT=3306 MYSQL_DATABASE=ft_test \
  MYSQL_USER=ft_user MYSQL_PASSWORD=ft_pass phpunit
```

統合テストで検証すること:
- 100 行バルクインポートが正しく全件 INSERT されること
- 部分成功で有効行のみが DB に保存されること
- バッチ内重複メールが検知・除外されること

---

## 参照実装

`../NENE2-FT/importlog/` — FT158 フィールドトライアル（22 テスト + MySQL 統合テスト 5 件）
