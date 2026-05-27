# 操作指南：使用 NENE2 构建表情符号反应系统

本指南演示如何构建一个反应系统，用户可以用表情符号对帖子进行反应，支持分组计数和每用户反应追踪。

**字段试验**：FT143
**NENE2 版本**：^1.5
**涵盖主题**：UNIQUE(post_id, user_id, emoji) 约束，GROUP BY 表情计数，每用户反应追踪，表情长度校验，MySQL 集成测试

---

## 我们要构建什么

- `POST /posts` ——创建帖子
- `POST /posts/{id}/reactions` ——添加反应（表情字符串，每个用户每个表情只能有一个）
- `DELETE /posts/{id}/reactions/{emoji}` ——移除反应（只能移除自己的）
- `GET /posts/{id}/reactions` ——获取反应计数和当前用户的反应

---

## 数据库结构

```sql
CREATE TABLE reactions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id    INTEGER NOT NULL,
    user_id    INTEGER NOT NULL,
    emoji      TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (post_id, user_id, emoji),
    FOREIGN KEY (post_id) REFERENCES posts(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`UNIQUE (post_id, user_id, emoji)`——每个用户对每个帖子的每个表情只有一行。同一用户可以用不同表情反应（👍 和 ❤️ = 2 行）。多个用户可以使用相同表情（每人各有一行）。

---

## 重复反应 → 409

```php
public function addReaction(int $postId, int $userId, string $emoji, string $now): bool
{
    try {
        $this->executor->execute(
            'INSERT INTO reactions (post_id, user_id, emoji, created_at) VALUES (?, ?, ?, ?)',
            [$postId, $userId, $emoji, $now],
        );
        return true;
    } catch (DatabaseConstraintException) {
        return false;
    }
}
```

当 `addReaction()` 返回 `false` 时，处理器返回 409。无需单独的存在性检查。

---

## 通过 GROUP BY 获取分组反应计数

```sql
SELECT emoji, COUNT(*) as cnt
FROM reactions
WHERE post_id = ?
GROUP BY emoji
ORDER BY cnt DESC, emoji ASC
```

按计数降序排列（最受欢迎的表情优先），然后按字母顺序作为平局决胜。结果直接映射为 PHP `array<string, int>`：

```php
$counts = [];
foreach ($rows as $row) {
    $arr = (array) $row;
    if (isset($arr['emoji']) && is_string($arr['emoji'])) {
        $counts[$arr['emoji']] = isset($arr['cnt']) ? (int) $arr['cnt'] : 0;
    }
}
```

---

## 每用户反应（可选操作者）

`GET /reactions` 端点接受可选的 `X-User-Id` 头。存在时，响应包含调用者已使用的表情列表：

```php
$actorId       = (int) $request->getHeaderLine('X-User-Id');
$userReactions = $actorId > 0 ? $this->repository->getUserReactions($postId, $actorId) : [];
```

这允许 UI 显示当前用户已经做出反应的表情。

---

## 表情校验

```php
if (mb_strlen($emoji) > 8) {
    return $this->responseFactory->create(['error' => 'emoji too long'], 422);
}
```

`mb_strlen` 计算 Unicode 码点数量，而非字节数。单个表情如 🧑‍💻（技术人员）有 3 个码点；8 个字符的限制可容纳大多数表情序列。根据需要调整。

---

## MySQL 集成测试（FT143）

MySQL 清理顺序很重要：

```php
$this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$this->pdo->exec('DROP TABLE IF EXISTS reactions');
$this->pdo->exec('DROP TABLE IF EXISTS posts');
$this->pdo->exec('DROP TABLE IF EXISTS users');
$this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
```

MySQL 表结构中表情列使用 `VARCHAR(32)`（而非 `TEXT`），以允许该列出现在 UNIQUE 键中而无需指定前缀长度。`VARCHAR(32)` 最多存储 32 个字符，足以覆盖所有表情序列。

---

## 常见陷阱

| 陷阱 | 修复方法 |
|------|---------|
| 允许重复表情反应 | `UNIQUE (post_id, user_id, emoji)` + 捕获 `DatabaseConstraintException` |
| 对表情使用 `strlen()` | 使用 `mb_strlen()`——表情是多字节 Unicode |
| 可变计数列失去同步 | 通过 `GROUP BY emoji` 从 `reactions` 表实时计算 |
| 缺少 MySQL 表情支持 | 使用 `utf8mb4` 字符集，表情列使用 `VARCHAR`（而非 `CHAR`） |
| 对 `fetchAll` 结果使用 `is_array()` 始终为 true | 跳过该检查；`fetchAll` 已返回 `array<int, array<string, mixed>>` |
