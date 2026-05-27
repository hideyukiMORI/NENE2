# 软删除（逻辑删除）

软删除通过设置 `deleted_at` 时间戳将记录保留在数据库中并标记为已删除。这实现了：
- 撤销/恢复功能
- 审计跟踪（谁删除了什么，什么时候删除的）
- 引用完整性（记录在清除前仍然可以被引用）

## 数据库结构

添加一个 `deleted_at` 列，活跃记录为 `NULL`，已删除记录为时间戳：

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT NULL          -- NULL = 活跃，时间戳 = 已删除
);
```

## 关键规则：始终过滤 deleted_at

**每个应该只返回活跃记录的查询都必须包含 `AND deleted_at IS NULL`。** 缺少这个过滤器是最常见的错误——代码看起来正常，但已删除的数据泄漏到 API 响应中。

```php
// ❌ 缺少过滤器——也返回已删除记录
$rows = $this->executor->fetchAll('SELECT * FROM articles WHERE id = ?', [$id]);

// ✅ 排除已删除
$rows = $this->executor->fetchAll(
    'SELECT * FROM articles WHERE id = ? AND deleted_at IS NULL',
    [$id],
);
```

这适用于每个查询：`findById`、`findAll`、`findByUser`、分页查询以及 JOIN 目标。

## 实体

```php
final readonly class Article
{
    public function __construct(
        public int $id,
        public string $title,
        public string $body,
        public string $createdAt,
        public string $updatedAt,
        public ?string $deletedAt,
    ) {
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
```

## Repository 模式

使用 `$includeTrashed = false` 参数。默认值 `false` 意味着调用者必须显式选择才能看到已删除记录，这防止了意外泄露：

```php
final class ArticleRepository
{
    public function findById(int $id, bool $includeTrashed = false): ?Article
    {
        $sql = $includeTrashed
            ? 'SELECT * FROM articles WHERE id = ?'
            : 'SELECT * FROM articles WHERE id = ? AND deleted_at IS NULL';

        $row = $this->executor->fetchOne($sql, [$id]);
        return $row !== null ? $this->hydrate($row) : null;
    }

    /** @return list<Article> */
    public function findActive(): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM articles WHERE deleted_at IS NULL ORDER BY created_at DESC',
        );
        return array_map($this->hydrate(...), $rows);
    }

    /** @return list<Article> */
    public function findTrashed(): array
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM articles WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC',
        );
        return array_map($this->hydrate(...), $rows);
    }

    public function softDelete(int $id): ?Article
    {
        $article = $this->findById($id); // 仅活跃记录
        if ($article === null) {
            return null;
        }
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->executor->execute('UPDATE articles SET deleted_at = ? WHERE id = ?', [$now, $id]);
        return new Article($article->id, $article->title, $article->body, $article->createdAt, $article->updatedAt, $now);
    }

    public function restore(int $id): ?Article
    {
        $article = $this->findById($id, includeTrashed: true);
        if ($article === null || !$article->isDeleted()) {
            return null; // 未找到或不在回收站
        }
        $this->executor->execute('UPDATE articles SET deleted_at = NULL WHERE id = ?', [$id]);
        return new Article($article->id, $article->title, $article->body, $article->createdAt, $article->updatedAt, null);
    }

    /** 永久删除——只允许从回收站中操作。 */
    public function purge(int $id): bool
    {
        $article = $this->findById($id, includeTrashed: true);
        if ($article === null || !$article->isDeleted()) {
            return false; // 守护：必须先在回收站中
        }
        $this->executor->execute('DELETE FROM articles WHERE id = ?', [$id]);
        return true;
    }
}
```

### 使用 `insert()` 进行 INSERT

创建记录时，使用 `insert()`（不用 `execute()` + `lastInsertId()`）：

```php
// ❌ 两次调用
$this->executor->execute('INSERT INTO articles ...', [...]);
$id = $this->executor->lastInsertId();

// ✅ 一次调用——返回插入的行 ID
$id = $this->executor->insert('INSERT INTO articles ...', [...]);
```

## 端点

典型的软删除 API：

| 方法 | 路径 | 说明 |
|---|---|---|
| `POST` | `/articles` | 创建 |
| `GET` | `/articles` | 仅活跃记录 |
| `GET` | `/articles/trash` | 仅已删除记录 |
| `GET` | `/articles/{id}` | 获取一条（已删除返回 404） |
| `DELETE` | `/articles/{id}` | 软删除 → 已经删除时返回 404 |
| `POST` | `/articles/{id}/restore` | 恢复 → 不在回收站时返回 404 |
| `DELETE` | `/articles/{id}/purge` | 硬删除 → 不在回收站时返回 404 |

**REST 语义注意**：`DELETE /articles/{id}` 行为是软删除，而不是永久删除。如果这让客户端感到意外，请在 OpenAPI 规范中清楚记录，或使用 `POST /articles/{id}/trash` 作为软删除操作。

## 在响应中始终包含 `deleted_at`

在每个响应中包含 `deleted_at`，以便客户端无需额外请求即可确定资源状态：

```php
return $this->json->create([
    'id'         => $article->id,
    'title'      => $article->title,
    'body'       => $article->body,
    'created_at' => $article->createdAt,
    'updated_at' => $article->updatedAt,
    'deleted_at' => $article->deletedAt, // null = 活跃；时间戳 = 已删除
]);
```

## 外键与软删除

当其他表引用软删除的记录时：
- 软删除不会破坏外键约束——行仍然存在
- 硬删除（清除）如果存在引用行则可能违反约束
- 清除之前，检查依赖记录或级联软删除到依赖项

## 代码审查检查清单

- [ ] 每个活跃记录查询都包含 `AND deleted_at IS NULL`
- [ ] `findById()` 默认是 `$includeTrashed = false`——调用者显式选择
- [ ] `purge()` 防止硬删除活跃记录（`isDeleted()` 检查）
- [ ] `restore()` 在记录不在回收站时返回 `null`（→ 404）
- [ ] 软删除表上的 JOIN 查询也对连接表过滤 `deleted_at IS NULL`
- [ ] `deleted_at` 包含在 API 响应中以便客户端确定状态
- [ ] `DELETE /articles/{id}` 行为（软删除 vs 硬删除）在 OpenAPI 中有文档
- [ ] 测试覆盖：删除 → GET 返回 404、列表排除已删除、恢复 → 再次可见、清除 → 到处消失、双重删除 → 404、清除活跃 → 404
- [ ] INSERT 使用 `insert()`（不用 `execute()` + `lastInsertId()`）
