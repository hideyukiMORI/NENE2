# 操作指南：点赞/踩票 API

> **FT 参考**：FT347（`NENE2-FT/votelog`）— 每用户点赞/踩票，支持切换取消（同方向投票两次取消投票）、切换方向（原子性上→下），分数聚合（点赞数 − 踩票数），`UNIQUE(user_id, item_id)` 约束，15 tests 全部 PASS。

本指南展示如何实现 Reddit/Stack Overflow 风格的投票系统：每个用户每个条目只能投一票，可以通过再次以相同方向投票来切换取消，或切换方向。

## 数据库结构

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE votes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    item_id    INTEGER NOT NULL,
    direction  TEXT    NOT NULL CHECK (direction IN ('up', 'down')),
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, item_id),
    FOREIGN KEY (user_id)  REFERENCES users(id),
    FOREIGN KEY (item_id)  REFERENCES items(id)
);
```

`UNIQUE(user_id, item_id)` 强制每用户每条目只能投一票。`CHECK(direction IN ('up', 'down'))` 在 DB 层拒绝任何其他值。

## 端点

| 方法 | 路径 | 说明 |
|------|------|------|
| `POST` | `/items/{id}/vote` | 投票、切换取消或切换方向 |
| `GET` | `/items/{id}/score` | 获取条目分数 |
| `GET` | `/items/{id}/vote/{userId}` | 获取用户当前投票状态 |

## 投票

```php
POST /items/1/vote
{"user_id": 42, "direction": "up"}

→ 200
{
  "vote": "up",
  "score": {
    "upvotes": 1,
    "downvotes": 0,
    "score": 1
  }
}
```

```php
POST /items/1/vote
{"user_id": 43, "direction": "down"}
→ 200  {"vote": "down", "score": {"upvotes": 1, "downvotes": 1, "score": 0}}
```

## 切换取消（同方向投票两次）

以**相同方向**投票第二次会取消投票：

```php
// 第一次投票
POST /items/1/vote  {"user_id": 42, "direction": "up"}
→ 200  {"vote": "up", "score": {"score": 1}}

// 相同方向第二次投票 → 切换取消
POST /items/1/vote  {"user_id": 42, "direction": "up"}
→ 200  {"vote": null, "score": {"score": 0}}
```

`vote: null` 表示用户对该条目没有活跃投票。

## 切换方向

以**相反方向**投票会原子性地翻转现有投票：

```php
// 从点赞开始
POST /items/1/vote  {"user_id": 42, "direction": "up"}
→ 200  {"vote": "up", "score": {"upvotes": 1, "downvotes": 0, "score": 1}}

// 切换为踩票
POST /items/1/vote  {"user_id": 42, "direction": "down"}
→ 200  {"vote": "down", "score": {"upvotes": 0, "downvotes": 1, "score": -1}}
```

## 获取分数

```php
GET /items/1/score

→ 200
{
  "upvotes": 2,
  "downvotes": 1,
  "score": 1         // 点赞数 − 踩票数
}
```

无投票条目的分数：

```php
GET /items/1/score   // 还没有投票
→ 200  {"upvotes": 0, "downvotes": 0, "score": 0}
```

## 获取用户投票状态

```php
// 尚未投票
GET /items/1/vote/42
→ 200  {"vote": null}

// 点赞后
GET /items/1/vote/42
→ 200  {"vote": "up"}

// 切换取消后
GET /items/1/vote/42
→ 200  {"vote": null}
```

## 实现

### 投票处理器逻辑

```php
public function vote(int $itemId, int $userId, string $direction): array
{
    $item = $this->repo->findItem($itemId);
    if ($item === null) {
        throw new ItemNotFoundException($itemId);
    }

    $existing = $this->repo->findVote($userId, $itemId);

    if ($existing !== null && $existing['direction'] === $direction) {
        // 相同方向 → 切换取消
        $this->repo->deleteVote($userId, $itemId);
        $activeDirection = null;
    } elseif ($existing !== null) {
        // 不同方向 → 就地更新
        $this->repo->updateVote($userId, $itemId, $direction, $now);
        $activeDirection = $direction;
    } else {
        // 新投票
        $this->repo->insertVote($userId, $itemId, $direction, $now);
        $activeDirection = $direction;
    }

    $score = $this->repo->getScore($itemId);

    return [
        'vote'  => $activeDirection,
        'score' => $score,
    ];
}
```

### 分数聚合 SQL

```sql
SELECT
    COALESCE(SUM(CASE WHEN direction = 'up'   THEN 1 ELSE 0 END), 0) AS upvotes,
    COALESCE(SUM(CASE WHEN direction = 'down' THEN 1 ELSE 0 END), 0) AS downvotes,
    COALESCE(SUM(CASE WHEN direction = 'up'   THEN 1 ELSE -1 END), 0) AS score
FROM votes
WHERE item_id = ?
```

`COALESCE(..., 0)` 确保无投票时返回零（空集合的 SUM 返回 NULL）。

### 投票 UPSERT 模式

```sql
-- 插入新投票
INSERT INTO votes (user_id, item_id, direction, created_at) VALUES (?, ?, ?, ?)

-- 更新方向（UNIQUE 约束防止重复）
UPDATE votes SET direction = ? WHERE user_id = ? AND item_id = ?

-- 删除（切换取消）
DELETE FROM votes WHERE user_id = ? AND item_id = ?
```

## 验证

```php
// 无效方向
POST /items/1/vote  {"user_id": 42, "direction": "sideways"}
→ 422  // direction 必须是 'up' 或 'down'

// 条目不存在
POST /items/9999/vote  {"user_id": 42, "direction": "up"}
→ 404
```

## 多用户

```php
// 三个用户对同一条目投票
POST /items/1/vote  {"user_id": 1, "direction": "up"}
POST /items/1/vote  {"user_id": 2, "direction": "up"}
POST /items/1/vote  {"user_id": 3, "direction": "down"}

GET /items/1/score
→ 200  {"upvotes": 2, "downvotes": 1, "score": 1}
```

---

## 不应做的事

| 反模式 | 风险 |
|---|---|
| 无 `UNIQUE(user_id, item_id)` | 用户可以多次投票，虚增分数 |
| `INSERT OR REPLACE` 用于切换方向 | 生成新的 `id` 和 `created_at`；丢失投票历史；破坏审计跟踪 |
| 切换取消时返回 409 | 切换取消是预期行为，不是错误；返回新的（null）投票状态 |
| 通过获取所有投票在应用层计算分数 | 每次请求 O(N)；使用单次查询的 SQL 聚合 |
| 允许 `direction: null` 通过请求体移除投票 | 有歧义；使用切换模式（同方向两次）或单独的 DELETE 端点 |
| 分数聚合中省略 `COALESCE` | `SUM()` 在无行匹配时返回 `NULL`；`null − null` 会崩溃或返回错误类型 |
