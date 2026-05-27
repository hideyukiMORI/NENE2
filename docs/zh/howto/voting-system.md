# 投票系统（点赞/踩票）

允许用户对条目进行点赞或踩票。每个用户每条目最多投一票。同方向投票两次切换取消投票，反方向投票切换方向。

## 概述

投票系统涉及：
- **投票**：对条目点赞或踩票
- **切换取消**：以相同方向投票两次移除投票
- **切换方向**：以相反方向投票替换当前投票
- **分数**：点赞数 − 踩票数，随每次投票响应一起返回
- **当前投票**：获取用户对条目的当前投票（用于 UI 高亮显示）

## 数据库结构

```sql
CREATE TABLE votes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    item_id    INTEGER NOT NULL,
    direction  TEXT    NOT NULL CHECK (direction IN ('up', 'down')),
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, item_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (item_id) REFERENCES items(id)
);
```

`UNIQUE (user_id, item_id)` 约束在数据库层强制每用户每条目只能投一票。`CHECK (direction IN ('up', 'down'))` 即使绕过应用层验证也能防止无效值。

## 方向枚举

使用带值枚举防止无效方向值到达 repository：

```php
enum VoteDirection: string
{
    case Up   = 'up';
    case Down = 'down';
}
```

使用 `VoteDirection::tryFrom($dirStr)` 解析——对无效输入返回 `null`，无需 match/switch 即可实现简洁的 422 处理。

## 切换取消与切换方向逻辑

所有三种情况（切换取消、切换方向、新投票）都在 repository 中处理：

```php
public function castVote(int $userId, int $itemId, VoteDirection $direction, string $now): ?VoteDirection
{
    $current = $this->getCurrentVote($userId, $itemId);

    if ($current === $direction) {
        // 相同方向 → 切换取消
        $this->executor->execute(
            'DELETE FROM votes WHERE user_id = ? AND item_id = ?',
            [$userId, $itemId],
        );
        return null;
    }

    if ($current !== null) {
        // 不同方向 → 切换
        $this->executor->execute(
            'UPDATE votes SET direction = ?, created_at = ? WHERE user_id = ? AND item_id = ?',
            [$direction->value, $now, $userId, $itemId],
        );
    } else {
        // 没有已有投票 → 插入
        $this->executor->execute(
            'INSERT INTO votes (user_id, item_id, direction, created_at) VALUES (?, ?, ?, ?)',
            [$userId, $itemId, $direction->value, $now],
        );
    }

    return $direction;
}
```

返回值 `?VoteDirection` 让处理器知道投票是已设置（`'up'`/`'down'`）还是已移除（`null`）。

## 每次投票时返回分数

在投票响应中包含更新后的分数，使客户端无需单独 GET 请求即可刷新计数器：

```php
$result = $this->repo->castVote($userId, $itemId, $direction, $now);
$score  = $this->repo->getScore($itemId);

return $this->responseFactory->create([
    'user_id' => $userId,
    'item_id' => $itemId,
    'vote'    => $result !== null ? $result->value : null,
    'score'   => $score->toArray(),
]);
```

## 分数计算

按方向分别 COUNT 查询比单个 GROUP BY 更简单、可读性更高：

```php
public function getScore(int $itemId): ItemScore
{
    $upRow = $this->executor->fetchOne(
        "SELECT COUNT(*) as cnt FROM votes WHERE item_id = ? AND direction = 'up'",
        [$itemId],
    );
    $downRow = $this->executor->fetchOne(
        "SELECT COUNT(*) as cnt FROM votes WHERE item_id = ? AND direction = 'down'",
        [$itemId],
    );
    ...
}
```

`score = upvotes - downvotes`。在任何投票投出之前初始状态为零。

## 用户投票状态

单独的端点让 UI 显示当前用户投了哪个方向（用于按钮高亮）：

```php
// GET /items/{itemId}/vote/{userId}
$current = $this->repo->getCurrentVote($userId, $itemId);
return ['vote' => $current !== null ? $current->value : null];
```

用户未投票（或已切换取消投票）时返回 `null`。

## 安全属性

| 属性 | 实现方式 |
|---|---|
| 每用户每条目只投一票 | `UNIQUE (user_id, item_id)` DB 约束 |
| 拒绝无效方向 | `CHECK (direction IN ('up', 'down'))` + `VoteDirection::tryFrom()` |
| 未知用户/条目 | 返回 404——不泄露资源是否存在 |
| 切换取消安全 | 在 DELETE/UPDATE 之前检查当前投票 |

## 路由概览

| 方法 | 路径 | 说明 |
|---|---|---|
| `POST` | `/users` | 创建用户 |
| `POST` | `/items` | 创建条目 |
| `POST` | `/items/{itemId}/vote` | 投票、切换方向或切换取消 |
| `GET` | `/items/{itemId}/score` | 获取点赞数、踩票数和分数 |
| `GET` | `/items/{itemId}/vote/{userId}` | 获取用户对条目的当前投票 |
