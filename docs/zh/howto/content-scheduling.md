# 内容调度——带生命周期状态的定时发布

使用 `publish_at` 列、状态机（`draft → scheduled → published → archived`）和一个由 cron 作业调用的**发布到期触发端点**，将内容定时发布到未来某个时间。

**参考实现**：[hideyukiMORI/NENE2-examples](https://github.com/hideyukiMORI/NENE2-examples) 中的 `FT172 pubschedulelog`

---

## 状态生命周期

```
draft ──┬──► scheduled ──► published ──► archived
        │                               ▲
        └───────────────────────────────┘
        （另外：scheduled → draft 通过取消调度）
```

| 来源 | 允许的转换 |
|------|----------|
| `draft` | `scheduled`、`published`、`archived` |
| `scheduled` | `published`、`draft`、`archived` |
| `published` | `archived` |
| `archived` | _（无）_ |

---

## 数据库结构

```sql
CREATE TABLE articles (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id    INTEGER NOT NULL,
    title        TEXT    NOT NULL,
    body         TEXT    NOT NULL,
    status       TEXT    NOT NULL DEFAULT 'draft',
    -- 'draft' | 'scheduled' | 'published' | 'archived'
    publish_at   TEXT,    -- ISO 8601；调度时设置；否则为 NULL
    published_at TEXT,    -- 实际发布时设置
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);
```

---

## 端点

| 方法 | 路径 | 认证 | 描述 |
|------|------|------|------|
| `POST` | `/articles` | X-User-Id | 创建草稿 |
| `GET` | `/articles` | 可选 | 列表（`?status=published` 公开；其他状态需要认证 + 仅自己的文章） |
| `GET` | `/articles/{id}` | 可选 | 获取一篇文章（published = 公开，draft/scheduled = 仅所有者） |
| `PUT` | `/articles/{id}` | X-User-Id | 更新 title/body（仅限 draft 或 scheduled） |
| `POST` | `/articles/{id}/schedule` | X-User-Id | 设置 `publish_at` → 转为 `scheduled` |
| `POST` | `/articles/{id}/unschedule` | X-User-Id | 取消调度 → 返回 `draft` |
| `POST` | `/articles/{id}/publish` | X-User-Id | 立即发布 |
| `POST` | `/articles/{id}/archive` | X-User-Id | 归档 |
| `POST` | `/articles/publish-due` | X-Admin-Key | 批量发布所有到期的调度文章 |

---

## 核心模式

### 带转换守卫的状态枚举

```php
enum ArticleStatus: string {
    case Draft     = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived  = 'archived';

    public function canTransitionTo(self $next): bool {
        return match ($this) {
            self::Draft     => in_array($next, [self::Scheduled, self::Published, self::Archived], true),
            self::Scheduled => in_array($next, [self::Published, self::Draft, self::Archived], true),
            self::Published => $next === self::Archived,
            self::Archived  => false,
        };
    }
}
```

### 调度：仅允许未来时间校验

```php
$ts = strtotime($publishAt);
if ($ts === false || $ts === -1) {
    throw new ArticleScheduleException('publish_at is not a valid datetime.');
}
if ($ts <= strtotime($now)) {
    throw new ArticleScheduleException('publish_at must be in the future.');
}
```

### 发布到期触发器（cron 安全，幂等）

```php
public function publishDue(string $now): array
{
    $rows = $this->db->fetchAll(
        "SELECT id FROM articles WHERE status = ? AND publish_at <= ? ORDER BY publish_at",
        [ArticleStatus::Scheduled->value, $now],
    );

    $published = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $this->db->execute(
            'UPDATE articles SET status = ?, published_at = ?, publish_at = NULL, updated_at = ? WHERE id = ?',
            [ArticleStatus::Published->value, $now, $now, $id],
        );
        $published[] = $id;
    }

    return $published;  // list<int>
}
```

每分钟通过 cron 作业调用此方法。幂等性：立即重新运行时找不到新的到期文章，因为 `publish_at` 在发布时被清除为 `NULL`。

### IDOR 防护

草稿和已调度的文章仅限所有者——返回 404（而非 403）以避免泄露存在性：

```php
if ($article->authorId !== $actorId) {
    throw new ArticleNotFoundException($id);  // 404，非 403
}
```

### 管理员密钥——时序安全比较

```php
if ($apiKey === '' || !hash_equals($expected, $apiKey)) {
    return $this->responseFactory->create(['error' => 'unauthorized'], 401);
}
```

密钥比较绝不使用 `!==`——使用 `hash_equals()` 防止时序攻击。

---

## 安全说明

| 风险 | 缓解措施 |
|------|---------|
| 注入过去的 `publish_at` | `strtotime($publishAt) <= strtotime($now)` → 422 |
| 跨用户状态变更 | 每次转换前检查所有权；404 而非 403 |
| 通过请求体注入作者 ID | `authorId` 仅从 `X-User-Id` 头获取 |
| 通过请求体注入状态 | PUT 请求体中的 `status` 字段被忽略；转换通过专用动作端点进行 |
| 管理员密钥的时序攻击 | `hash_equals()` 而非 `!==` |
| 枚举未发布文章 | 公开列表始终按 `status = published` 过滤；未发布的需要认证 + 仅自己的文章 |
| 发布后编辑 | PUT 对非 draft/scheduled 文章返回 422 |
| 双重归档 | 转换守卫对无效转换返回 409 |

---

## Cron 集成

```bash
# /etc/cron.d/publish-due
* * * * * www-data curl -s -X POST https://api.example.com/articles/publish-due \
  -H "X-Admin-Key: $ADMIN_KEY"
```

对于更高吞吐量的场景，迁移到作业队列（参见 [job-queue.md](./job-queue.md)），由队列 worker 调用 `publishDue()`。

---

## 参见

- [内容草稿生命周期](./content-draft-lifecycle.md) ——无调度的草稿/活跃/归档
- [作业队列](./job-queue.md) ——高吞吐量发布触发器的后台处理
- [软删除](./soft-delete.md) ——归档的补充
- [审计跟踪](./audit-trail.md) ——记录谁在何时发布了什么
