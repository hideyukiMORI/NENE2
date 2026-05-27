# 操作指南：带切换功能和分组计数的表情符号反应

> **FT 参考**：FT263（`NENE2-FT/reactionlog`）——表情符号反应：切换（添加/移除）、分组计数、每用户反应列表

演示一个反应 API，每个用户可以对任何目标（帖子、评论等）使用任意表情或反应类型做出反应。单个 `PUT` 端点实现切换：不存在时添加，已存在时移除。按反应类型的分组计数通过汇总查询返回。复合 `UNIQUE` 约束强制每用户每类型只能有一个反应，`DatabaseConstraintException` 处理并发切换竞争。

---

## 路由

| 方法 | 路径 | 描述 |
|------|------|------|
| `PUT` | `/reactions/{targetType}/{targetId}` | 切换反应（添加或移除） |
| `DELETE` | `/reactions/{targetType}/{targetId}/{reactionType}` | 显式移除特定反应 |
| `GET` | `/reactions/{targetType}/{targetId}` | 获取反应摘要（分组计数） |

---

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS reactions (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    target_id     TEXT    NOT NULL,
    target_type   TEXT    NOT NULL DEFAULT 'post',
    reaction_type TEXT    NOT NULL,
    user_id       TEXT    NOT NULL,
    created_at    TEXT    NOT NULL,
    UNIQUE(target_id, target_type, reaction_type, user_id)
);
CREATE INDEX IF NOT EXISTS idx_reactions_target ON reactions (target_id, target_type);
CREATE INDEX IF NOT EXISTS idx_reactions_user   ON reactions (user_id);
```

`UNIQUE(target_id, target_type, reaction_type, user_id)` 强制每个唯一（目标、用户、反应）组合只有一条记录。尝试插入重复记录会触发约束违反，应用层将其捕获为 `DatabaseConstraintException`。

`target_type` 允许同一反应系统服务多种实体类型（`post`、`comment`、`message`），无需单独的表。

---

## 切换模式

```php
public function toggle(string $targetId, string $targetType, string $reactionType, string $userId): bool
{
    $existing = $this->db->fetchOne(
        'SELECT id FROM reactions WHERE target_id = ? AND target_type = ? AND reaction_type = ? AND user_id = ?',
        [$targetId, $targetType, $reactionType, $userId],
    );

    if ($existing !== null) {
        $this->db->execute('DELETE FROM reactions WHERE id = ?', [(int) $existing['id']]);
        return false;   // 反应已移除
    }

    $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    try {
        $this->db->execute(
            'INSERT INTO reactions (target_id, target_type, reaction_type, user_id, created_at) VALUES (?, ?, ?, ?, ?)',
            [$targetId, $targetType, $reactionType, $userId, $now],
        );
    } catch (DatabaseConstraintException) {
        // 竞争条件：来自同一用户的并发切换——视为已移除
        return false;
    }

    return true;   // 反应已添加
}
```

**流程**：
1. `SELECT` 检查反应是否存在。
2. 若存在：`DELETE` → 返回 `false`（已移除）。
3. 若不存在：`INSERT` → 返回 `true`（已添加）。
4. 若 `INSERT` 因 UNIQUE 违反失败（`DatabaseConstraintException`）：在 `SELECT` 和 `INSERT` 之间，并发请求插入了相同的行。视为"已移除"（并发切换胜出）→ 返回 `false`。

**为什么用 `SELECT` 再 `INSERT`？** 另一种方式是 `INSERT OR IGNORE` 并通过检查 `changes() == 0` 来检测行已存在的情况。显式 `SELECT` 方式意图更清晰，且无需额外查询即可产生干净的返回值（添加与移除）。

---

## 控制器：添加时 201，移除时 200

```php
$added = $this->repo->toggle($targetId, $targetType, $reactionType, $userId);

return $this->json->create([
    'target_id'     => $targetId,
    'target_type'   => $targetType,
    'reaction_type' => $reactionType,
    'user_id'       => $userId,
    'added'         => $added,
], $added ? 201 : 200);
```

添加反应时返回 `201 Created`；移除时返回 `200 OK`。响应体中的 `added` 字段让客户端无需检查状态码即可区分两种情况。

**为什么切换用 `PUT`？** HTTP 语义上 `PUT` 是幂等的。单用户切换在效果上是幂等的（两次相同的 `PUT` 恢复原始状态）。也可以使用 `POST` 来表示非幂等切换；选择取决于团队约定。

---

## 分组计数摘要

```php
public function summary(string $targetId, string $targetType, ?string $userId): ReactionSummary
{
    $rows = $this->db->fetchAll(
        'SELECT reaction_type, COUNT(*) AS cnt
           FROM reactions
          WHERE target_id = ? AND target_type = ?
          GROUP BY reaction_type
          ORDER BY cnt DESC',
        [$targetId, $targetType],
    );

    $counts = [];
    $total  = 0;
    foreach ($rows as $row) {
        $counts[(string) $row['reaction_type']] = (int) $row['cnt'];
        $total += (int) $row['cnt'];
    }

    $userReactions = [];
    if ($userId !== null) {
        $userRows = $this->db->fetchAll(
            'SELECT reaction_type FROM reactions WHERE target_id = ? AND target_type = ? AND user_id = ? ORDER BY created_at ASC',
            [$targetId, $targetType, $userId],
        );
        $userReactions = array_map(fn (array $r) => (string) $r['reaction_type'], $userRows);
    }

    return new ReactionSummary($targetId, $targetType, $counts, $total, $userReactions);
}
```

两个查询：
1. 分组计数：`GROUP BY reaction_type ORDER BY cnt DESC`——最受欢迎的优先。
2. 每用户反应（如果提供 `$userId`）：该用户已应用了哪些反应类型。

`ORDER BY cnt DESC` 将使用最多的反应排在最前，符合典型的显示优先级。

---

## 示例摘要响应

**请求**：`GET /reactions/post/42?user_id=alice`

```json
{
  "target_id": "42",
  "target_type": "post",
  "counts": {
    "👍": 15,
    "❤️": 8,
    "😂": 3
  },
  "total": 26,
  "user_reactions": ["👍"]
}
```

`counts` 是从反应类型到计数的映射。`user_reactions` 是 `alice` 已应用的反应列表。客户端可以高亮 `👍` 来表示 alice 的活跃反应。

---

## 显式移除端点

```php
public function remove(string $targetId, string $targetType, string $reactionType, string $userId): bool
{
    $count = $this->db->execute(
        'DELETE FROM reactions WHERE target_id = ? AND target_type = ? AND reaction_type = ? AND user_id = ?',
        [$targetId, $targetType, $reactionType, $userId],
    );
    return $count > 0;
}
```

`DELETE /reactions/{targetType}/{targetId}/{reactionType}` 请求体中携带 `user_id`，不带切换语义地移除特定反应。适用于客户端希望无论当前状态如何都移除特定反应类型的场景。

未找到匹配反应时返回 404（`$count == 0`）。

---

## 复合 UNIQUE 约束作为安全网

`UNIQUE(target_id, target_type, reaction_type, user_id)` 约束：
- **主要执行**：在数据库层面防止重复反应。
- **次要好处**：捕获绕过 `SELECT` 检查的竞争条件。
- **应用逻辑**：`toggle()` 捕获 `DatabaseConstraintException` 并将其视为移除。

没有该约束，来自同一用户的两个并发 `PUT` 请求会插入两个相同的行。约束 + 异常处理器确保在并发情况下保持不变量（每用户每反应类型一行）。

---

## 设计说明

| 决策 | 选择 | 理由 |
|------|------|------|
| 切换端点 | `PUT` | 语义上合适；幂等 |
| 反应标识 | 4 列复合键 | 无需单独的反应类型表 |
| `target_type` | PATH 参数 | 允许一个端点服务多种实体类型 |
| 请求体中的 `user_id` | 必填字段 | 本 FT 避免要求认证中间件 |
| 摘要中的 `user_id` | 查询参数 | 可选——摘要是公开的；每用户详情是选择性的 |

---

## 相关操作指南

- [`multi-value-tag-filter.md`](multi-value-tag-filter.md) ——使用 INSERT OR IGNORE 进行标签去重的 M:N 连接表
- [`mass-assignment-defence.md`](mass-assignment-defence.md) ——复合唯一键作为数据库层安全网
- [`transaction-scope-pattern.md`](transaction-scope-pattern.md) ——多个写操作必须同时成功或失败时的原子操作
