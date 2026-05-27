# 操作指南：文档版本管理 API

> **FT 参考**：FT239（`NENE2-FT/doclog`）——文档版本管理 API

演示一个仅追加（append-only）的文档版本管理系统，其中通过 `is_current` 标志追踪当前版本，回滚操作创建新版本（非破坏性），所有多步写入均通过 `DatabaseTransactionManagerInterface` 包装在事务中执行。

---

## 路由

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/documents` | 创建文档及其第一个版本 |
| `GET` | `/documents` | 列出文档（分页）及当前版本 |
| `GET` | `/documents/{id}` | 获取文档及其当前版本 |
| `GET` | `/documents/{id}/versions` | 列出版本历史（分页） |
| `POST` | `/documents/{id}/versions` | 添加新版本 |
| `POST` | `/documents/{id}/revert/{version}` | 回滚到指定版本号 |

静态子路由（`/documents/{id}/versions`）需在参数化的 `/documents/{id}` 路由之前注册，以确保正确分发。

---

## 数据库结构：`is_current` 标志模式

```sql
CREATE TABLE IF NOT EXISTS documents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS document_versions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    content     TEXT    NOT NULL,
    version_num INTEGER NOT NULL,
    is_current  INTEGER NOT NULL DEFAULT 0 CHECK(is_current IN (0, 1)),
    created_at  TEXT    NOT NULL,
    UNIQUE(document_id, version_num)
);
CREATE INDEX IF NOT EXISTS idx_versions_document ON document_versions(document_id);
```

`is_current` 是以 INTEGER 存储的布尔标志（0/1），由 `CHECK` 约束保证。每个文档最多只有一行 `is_current = 1`。`UNIQUE(document_id, version_num)` 防止同一文档出现重复的版本号。

**与 `current_version` 整数列的对比**：`is_current` 标志方法避免了每次版本变更时都需要更新父表 `documents` 的列。标志直接在 `document_versions` 表上切换，与插入新版本在同一事务中完成。

---

## 通过 JOIN 获取当前版本

列表和详情查询使用 `LEFT JOIN` 并过滤 `is_current = 1`，在单次查询中获取当前版本：

```php
$row = $this->executor->fetchOne(
    'SELECT d.*, dv.id AS vid, dv.content, dv.version_num, dv.is_current,
            dv.created_at AS version_created_at
     FROM documents d
     LEFT JOIN document_versions dv ON dv.document_id = d.id AND dv.is_current = 1
     WHERE d.id = ?',
    [$id],
);
```

`LEFT JOIN ... AND dv.is_current = 1`——连接条件仅过滤当前版本。没有版本的文档返回 `NULL` 连接行，映射为 `currentVersion: null`。

---

## 添加版本：三步事务

添加版本需要按顺序执行三个操作，并包装在事务中：

```php
public function addVersion(int $documentId, string $content, string $now): Document
{
    return $this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($documentId, $content, $now): Document {
        // 步骤 1：计算下一个版本号
        $maxRow     = $tx->fetchOne('SELECT MAX(version_num) AS max_ver FROM document_versions WHERE document_id = ?', [$documentId]);
        $nextVerNum = ((int) ($maxRow['max_ver'] ?? 0)) + 1;

        // 步骤 2：停用当前版本
        $tx->execute('UPDATE document_versions SET is_current = 0 WHERE document_id = ? AND is_current = 1', [$documentId]);

        // 步骤 3：将新版本插入为当前版本
        $versionId = $tx->insert(
            'INSERT INTO document_versions (document_id, content, version_num, is_current, created_at) VALUES (?, ?, ?, 1, ?)',
            [$documentId, $content, $nextVerNum, $now],
        );

        // 步骤 4：更新文档的 updated_at
        $tx->execute('UPDATE documents SET updated_at = ? WHERE id = ?', [$now, $documentId]);
        // ...
    });
}
```

`DatabaseTransactionManagerInterface::transactional()` 将闭包包装在事务中。任何步骤抛出异常，事务回滚。`$tx` 参数是绑定到该事务的执行器——无需额外连接。

---

## 非破坏性回滚：复制为新版本

回滚操作不修改现有历史——它创建一个包含目标版本内容的新版本：

```php
public function revertToVersion(int $documentId, int $versionNum, string $now): Document
{
    return $this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($documentId, $versionNum, $now): Document {
        $targetRow = $tx->fetchOne(
            'SELECT * FROM document_versions WHERE document_id = ? AND version_num = ?',
            [$documentId, $versionNum],
        );

        if ($targetRow === null) {
            throw new VersionNotFoundException($documentId, $versionNum);
        }

        // 为回滚副本计算下一个版本号
        $maxRow     = $tx->fetchOne('SELECT MAX(version_num) AS max_ver FROM document_versions WHERE document_id = ?', [$documentId]);
        $nextVerNum = ((int) ($maxRow['max_ver'] ?? 0)) + 1;

        // 停用当前版本
        $tx->execute('UPDATE document_versions SET is_current = 0 WHERE document_id = ? AND is_current = 1', [$documentId]);

        // 将目标内容的副本插入为新的当前版本
        $newVersionId = $tx->insert(
            'INSERT INTO document_versions (document_id, content, version_num, is_current, created_at) VALUES (?, ?, ?, 1, ?)',
            [$documentId, (string) $targetRow['content'], $nextVerNum, $now],
        );
        // ...
    });
}
```

如果文档在版本 5，回滚到版本 2，则创建版本 6，其内容为版本 2 的内容。历史记录为：
```
v1 → v2 → v3 → v4 → v5 → v6（v2 的副本）
```

这种方式保留完整的审计追踪——回滚操作本身作为新条目在历史中可见。不可能"丢失"历史。

---

## 带结构化上下文的 VersionNotFoundException

`VersionNotFoundException` 同时携带文档 ID 和版本号：

```php
final class VersionNotFoundException extends \RuntimeException
{
    public function __construct(int $documentId, int $versionNum)
    {
        parent::__construct("Version {$versionNum} not found for document {$documentId}.");
    }
}
```

异常在事务闭包内抛出。异常处理器将其映射为 `404 Not Found` 响应。由于异常在回滚中的任何写操作之前抛出，事务会干净地回滚。

---

## NENE2 内置组件：PaginationQueryParser 和 PaginationResponse

列表端点使用 NENE2 的分页辅助工具：

```php
private function listDocuments(ServerRequestInterface $request): ResponseInterface
{
    $pagination = PaginationQueryParser::parse($request);
    $items      = $this->repository->findAll($pagination->limit, $pagination->offset);
    $total      = $this->repository->countAll();

    $response = new PaginationResponse(
        items: array_map($this->serializeDocument(...), $items),
        limit: $pagination->limit,
        offset: $pagination->offset,
        total: $total,
    );

    return $this->json->create($response->toArray());
}
```

`PaginationQueryParser::parse()` 从查询参数中读取 `?limit=` 和 `?offset=`，并提供安全的默认值和边界检查。`PaginationResponse::toArray()` 生成统一的响应封装：`{ items, total, limit, offset }`。

---

## NENE2 内置组件：ValidationException 和 ValidationError

输入校验使用 NENE2 的结构化校验辅助工具：

```php
$errors = [];
if (!isset($body['title']) || !is_string($body['title']) || trim($body['title']) === '') {
    $errors[] = new ValidationError('title', 'title is required.', 'required');
}
if (!isset($body['content']) || !is_string($body['content'])) {
    $errors[] = new ValidationError('content', 'content is required.', 'required');
}
if ($errors !== []) {
    throw new ValidationException($errors);
}
```

`ValidationException` 被 NENE2 的错误处理器捕获，转换为带结构化 `errors` 数组的 `422 Unprocessable Entity` Problem Details 响应——与通过 `ProblemDetailsResponseFactory::create()` 附带 `errors` 扩展的调用效果相同，但走的是基于异常的路径。

---

## 相关操作指南

- [`content-versioning.md`](content-versioning.md) ——基于整数 current_version 的模式
- [`audit-trail.md`](audit-trail.md) ——仅追加历史记录模式
- [`transactions.md`](transactions.md) ——DatabaseTransactionManagerInterface 模式
- [`use-transactions.md`](use-transactions.md) ——包装多步写入操作
