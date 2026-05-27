# CSV 批量导入 API 实现指南

## 概述

本指南介绍如何使用 NENE2 实现 CSV 批量导入 API。以 REST API 的形式提供行级校验、部分成功、错误收集和导入历史管理功能。

---

## 数据库结构

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

## 端点设计

| 方法 | 路径 | 描述 |
|------|------|------|
| POST | `/imports` | 导入 CSV（同步处理，支持部分成功） |
| GET | `/imports` | 导入作业列表 |
| GET | `/imports/{importId}` | 获取导入结果+记录 |

### 请求格式

```json
POST /imports
{
  "csv": "name,email,age\nAlice,alice@example.com,30\nBob,bob@example.com,25",
  "filename": "users.csv"
}
```

CSV 以字符串形式发送到 JSON 请求体的 `csv` 字段。这使得用标准 JSON API 流程进行测试更容易。

---

## 实现

### CsvImporter（纯解析器）

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
            // PHP 8.4：不显式指定 $escape 参数会产生 deprecation
            $fields = str_getcsv($line, ',', '"', '\\');
            $fields = array_map(static fn(?string $f): string => trim((string) ($f ?? '')), $fields);

            if ($i === 0) {
                continue; // 跳过表头
            }
            // ... 校验和收集
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

### RouteRegistrar（摘录）

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

## 设计要点

### PHP 8.4：str_getcsv() 的 $escape 必须指定

PHP 8.4 中 `str_getcsv()` 的 `$escape` 参数变为必须（默认值更改的过渡期）。不指定会产生 deprecation。

```php
// 不好：PHP 8.4 deprecation
$fields = str_getcsv($line);

// 好：显式指定 $escape（RFC 4180 兼容）
$fields = str_getcsv($line, ',', '"', '\\');
```

此外，`str_getcsv()` 对空字段可能返回 `null`。PHP 8.4 中 `trim(null)` 也会产生 deprecation，因此需要显式处理：

```php
$fields = array_map(static fn(?string $f): string => trim((string) ($f ?? '')), $fields);
```

### 部分成功模式

在批量导入中，实用的做法不是"全部成功或全部失败"，而是**只导入有效行 + 收集无效行的错误**：

```php
$parsed = $this->importer->parse($csv);
// $parsed['rows'] = 有效行列表 → INSERT
// $parsed['errors'] = [{row: 3, value: "bad@", error: "invalid email format"}, ...]
```

响应中返回 `imported_rows` / `failed_rows` / `errors`：

```json
{
  "imported_rows": 4,
  "failed_rows": 1,
  "errors": [{"row": 3, "value": "bad-email", "error": "invalid email format"}]
}
```

### 批次内重复邮箱检测

同一 CSV 文件中包含相同邮箱的多行时，不依赖数据库约束，而是在导入器侧使用哈希映射进行预先检测：

```php
$seenEmails = [];
// ...
if (isset($seenEmails[$email])) {
    $rowErrors[] = 'duplicate email in import batch';
}
// ...
$seenEmails[$email] = true;
```

捕获数据库约束错误的方式会导致行是否已插入不明确，错误消息也不清晰。预先检测更明确，用户体验更好。

### CRLF 处理

Windows 生成的 CSV 使用 `\r\n` 换行。使用 `preg_split('/\r\n|\r|\n/', ...)` 统一处理：

```php
$lines = preg_split('/\r\n|\r|\n/', trim($csv));
```

### errors 字段的 JSON 持久化

`errors` 以 JSON 字符串形式保存在数据库的 TEXT 列中，取出时解码：

```php
// 保存
json_encode($errors)

// 取出并格式化
$errors = json_decode((string) $job['errors'], true) ?? [];
```

SQLite 没有 JSON 类型，因此用 TEXT 代替。MySQL 也同样处理（也可以使用 JSON 类型，但为了兼容性使用 TEXT）。

---

## 响应示例

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

### GET /imports/{id}（含记录）

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

## MySQL 集成测试

在 MySQL 环境中设置 `MYSQL_HOST` 环境变量来运行集成测试：

```bash
MYSQL_HOST=127.0.0.1 MYSQL_PORT=3306 MYSQL_DATABASE=ft_test \
  MYSQL_USER=ft_user MYSQL_PASSWORD=ft_pass phpunit
```

集成测试要验证的内容：
- 100 行批量导入全部正确 INSERT
- 部分成功时只有有效行保存到数据库
- 批次内重复邮箱被检测并排除

---

## 参考实现

`../NENE2-FT/importlog/` — FT158 字段测试（22 个测试 + 5 个 MySQL 集成测试）
