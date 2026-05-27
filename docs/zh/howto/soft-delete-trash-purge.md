# 操作指南：软删除、回收站和永久清除

> **FT 参考**：FT257（`NENE2-FT/softdeletelog`）— 使用 `deleted_at` 列的软删除/回收站/永久清除模式

演示记录的三阶段生命周期：活跃 → 软删除（回收站）→ 永久清除。
活跃列表自动排除已删除记录。专用回收站端点只列出已删除记录。
恢复将记录从回收站返回到活跃状态。清除物理上从数据库中删除记录（只允许在回收站中的记录）。

---

## 路由

| 方法 | 路径 | 说明 |
|------|------|------|
| `POST` | `/notes` | 创建笔记 |
| `GET` | `/notes` | 列出活跃笔记（排除软删除） |
| `GET` | `/notes/trash` | 仅列出回收站中的笔记 |
| `GET` | `/notes/{id}` | 获取单个活跃笔记 |
| `DELETE` | `/notes/{id}` | 软删除笔记（移入回收站） |
| `POST` | `/notes/{id}/restore` | 从回收站恢复到活跃 |
| `DELETE` | `/notes/{id}/purge` | 永久删除（只允许回收站中的记录） |

> **路由顺序**：`/notes/trash` 必须在 `/notes/{id}` 之前注册，以免字面段 `trash` 被捕获为路径参数。

---

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT NULL
);
```

`deleted_at TEXT NULL` 是软删除标记。为 `NULL` 时记录是活跃的；设置为 ISO 时间戳时记录在回收站中。不需要单独的 `is_deleted` 布尔值——时间戳同时记录了删除发生的时间，这对于审计跟踪和基于 TTL 的清除任务很有用。

---

## 领域对象

```php
final readonly class Note
{
    public function __construct(
        public int     $id,
        public string  $title,
        public string  $body,
        public string  $createdAt,
        public string  $updatedAt,
        public ?string $deletedAt,     // null = 活跃，非 null = 在回收站
    ) {}

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
```

`isDeleted()` 封装了空值检查，调用者无需了解实现细节。

---

## Repository：`includeTrashed` 参数

```php
public function findById(int $id, bool $includeTrashed = false): ?Note
{
    $sql = $includeTrashed
        ? 'SELECT * FROM notes WHERE id = ?'
        : 'SELECT * FROM notes WHERE id = ? AND deleted_at IS NULL';

    $rows = $this->executor->fetchAll($sql, [$id]);
    return $rows === [] ? null : $this->hydrate($rows[0]);
}
```

默认值（`includeTrashed: false`）应用 `deleted_at IS NULL` 过滤器，调用者自动获得安全行为。只有恢复和清除需要看到回收站记录，它们显式传递 `includeTrashed: true`。

**为什么不用单独的 `findByIdIncludingTrashed()` 方法？**

命名布尔参数在调用处是自文档化的：
- `findById($id)` — 明确只含活跃记录
- `findById($id, includeTrashed: true)` — 明确感知回收站

独立方法会复制水合逻辑或需要内部共享辅助函数。

---

## 列表：活跃 vs 回收站

```php
public function listActive(): array
{
    return $this->executor->fetchAll(
        'SELECT * FROM notes WHERE deleted_at IS NULL ORDER BY created_at DESC',
        [],
    );
}

public function listTrashed(): array
{
    return $this->executor->fetchAll(
        'SELECT * FROM notes WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC',
        [],
    );
}
```

活跃笔记按创建时间排序（最新在前）。回收站笔记按删除时间排序（最近删除的在前），这对于"最近删除"UI 来说更自然。

---

## 软删除

```php
public function softDelete(int $id, string $now): ?Note
{
    $note = $this->findById($id);   // 仅活跃记录查找
    if ($note === null) {
        return null;   // 未找到或已在回收站 → 404
    }

    $this->executor->execute(
        'UPDATE notes SET deleted_at = ? WHERE id = ?',
        [$now, $id],
    );

    return new Note($note->id, $note->title, $note->body, $note->createdAt, $note->updatedAt, $now);
}
```

不带 `includeTrashed` 的 `findById($id)` 意味着对已在回收站的笔记调用 `DELETE /notes/{id}` 会返回 `null` → 404。这防止了双重删除的混淆：客户端无法从 404 判断笔记是活跃且不存在，还是已在回收站。

---

## 恢复

```php
public function restore(int $id): ?Note
{
    $note = $this->findById($id, includeTrashed: true);
    if ($note === null || !$note->isDeleted()) {
        return null;   // 未找到或已经是活跃 → 404
    }

    $this->executor->execute(
        'UPDATE notes SET deleted_at = NULL WHERE id = ?',
        [$id],
    );

    return new Note($note->id, $note->title, $note->body, $note->createdAt, $note->updatedAt, null);
}
```

这里需要 `includeTrashed: true`——笔记确实是被删除的，所以默认过滤器会隐藏它。`!$note->isDeleted()` 守护拒绝活跃笔记：对活跃笔记调用恢复返回 `null` → 404。这使恢复在"已经恢复"路径上是幂等的：两次调用恢复的客户端第一次得到 200，第二次得到 404。

---

## 清除（永久删除）

```php
public function purge(int $id): bool
{
    $note = $this->findById($id, includeTrashed: true);
    if ($note === null || !$note->isDeleted()) {
        return false;   // 未找到或仍然是活跃 → 404
    }

    $this->executor->execute('DELETE FROM notes WHERE id = ?', [$id]);
    return true;
}
```

`purge()` 只作用于回收站记录（`isDeleted()` 必须为 true）。对活跃笔记调用 `DELETE /notes/{id}/purge` 返回 `false` → 404。这防止通过错误端点意外销毁数据——客户端必须先显式软删除才能清除。

---

## 状态机

```
           POST /notes
               │
               ▼
           [活跃]  ←──────── POST /notes/{id}/restore ────────┐
               │                                               │
    DELETE /notes/{id}                                        │
               │                                               │
               ▼                                               │
           [回收站]  ─────────────────────────────────────────┘
               │
    DELETE /notes/{id}/purge
               │
               ▼
          [消失——物理 DELETE]
```

`活跃 → 回收站` 是可逆的。`回收站 → 消失` 是不可逆的。没有从 `活跃 → 消失` 的直接路径：清除需要先经过软删除步骤。

---

## 控制器：路由注册顺序

```php
public function register(Router $router): void
{
    $router->post('/notes',              $this->create(...));
    $router->get('/notes',               $this->listActive(...));
    $router->get('/notes/trash',         $this->listTrashed(...));   // ← 必须在 {id} 之前
    $router->get('/notes/{id}',          $this->get(...));
    $router->delete('/notes/{id}',       $this->softDelete(...));
    $router->post('/notes/{id}/restore', $this->restore(...));
    $router->delete('/notes/{id}/purge', $this->purge(...));
}
```

`/notes/trash` 必须在 `/notes/{id}` 之前注册。如果顺序相反，`GET /notes/trash` 请求会匹配 `{id}` 并将 `id = "trash"`，整数转换失败，返回 404 或带空响应体的 200，而不是回收站列表。

---

## HTTP 语义

| 操作 | 方法 | 原因 |
|------|------|------|
| 软删除 | `DELETE` | 客户端意图从其视图中移除资源 |
| 恢复 | `POST` | 不是幂等的（第二次调用返回 404）；`POST` 合适 |
| 清除 | `DELETE` | 客户端意图永久删除 |

`PATCH /notes/{id}` 带 `{"deleted_at": null}` 是恢复的另一种方式，但 `POST /restore` 更明确，避免在 API 契约中泄露内部列名。

---

## 设计比较

| 方式 | 活跃过滤 | 删除标记 | 恢复 | 清除 |
|---|---|---|---|---|
| `deleted_at` 时间戳 | `WHERE deleted_at IS NULL` | 时间戳 + 审计跟踪 | `SET deleted_at = NULL` | 物理 `DELETE` |
| `is_deleted` 布尔值 | `WHERE is_deleted = 0` | 仅布尔值 | `SET is_deleted = 0` | 物理 `DELETE` |
| 独立 `deleted_notes` 表 | 无需过滤 | 将行移至另一个表 | 将行移回 | 从 `deleted_notes` 中删除 |

`deleted_at` 是最常见的模式：一列、最小的数据库结构变更，以及内置的审计时间戳，零额外代价。

---

## 相关指南

- [`article-versioning-api.md`](article-versioning-api.md) — 内容版本历史（审计跟踪模式）
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — 显式 DTO 白名单防止字段注入
- [`transaction-scope-pattern.md`](transaction-scope-pattern.md) — 原子多写操作
