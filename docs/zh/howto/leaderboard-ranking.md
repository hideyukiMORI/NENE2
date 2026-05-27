# 操作指南：游戏排行榜与排名 API

本指南演示如何构建排行榜 API，玩家按游戏提交分数，系统追踪个人最佳并产生排名排行榜。

## 模式概述

- 玩家通过 `POST /scores` 提交分数（由 `X-User-Id` 请求头标识）。
- 每次提交都被存储；返回个人最佳（有史以来最高分）。
- `GET /leaderboard?game=<name>` 返回按个人最佳排名的前 N 名玩家。
- `GET /scores/{userId}?game=<name>` 列出特定玩家的所有原始分数。
- 游戏完全隔离——"tetris" 中的分数永远不会影响 "snake"。

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS scores (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    game       TEXT    NOT NULL,
    score      INTEGER NOT NULL,
    created_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_scores_game ON scores (game, score DESC);
```

所有尝试都被存储（不使用 upsert），这允许按游戏查看分数历史。个人最佳在查询时用 `MAX(score)` 派生。

## 分数提交

```php
public function submit(int $userId, string $game, int $score): void
{
    $this->pdo->prepare(
        'INSERT INTO scores (user_id, game, score, created_at) VALUES (:uid, :game, :score, :now)'
    )->execute([':uid' => $userId, ':game' => $game, ':score' => $score, ':now' => $this->now()]);
}

public function bestScore(int $userId, string $game): ?int
{
    $stmt = $this->pdo->prepare(
        'SELECT MAX(score) FROM scores WHERE user_id = :uid AND game = :game'
    );
    $stmt->execute([':uid' => $userId, ':game' => $game]);
    $val = $stmt->fetchColumn();
    return $val !== false && $val !== null ? (int) $val : null;
}
```

处理器在确认中一并返回个人最佳：

```json
{ "message": "Score recorded.", "best_score": 2000 }
```

## 排行榜查询

每个用户的最佳分数，降序排列，排名在 PHP 中添加：

```php
public function leaderboard(string $game, int $limit): array
{
    $stmt = $this->pdo->prepare(
        'SELECT user_id, MAX(score) AS best_score
         FROM scores
         WHERE game = :game
         GROUP BY user_id
         ORDER BY best_score DESC
         LIMIT :lim'
    );
    $stmt->bindValue(':game', $game, PDO::PARAM_STR);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ranked = [];
    foreach ($rows as $i => $row) {
        $ranked[] = array_merge($row, ['rank' => $i + 1]);
    }
    return $ranked;
}
```

响应：

```json
{
  "leaderboard": [
    { "user_id": 2, "best_score": 1000, "rank": 1 },
    { "user_id": 1, "best_score": 500,  "rank": 2 }
  ]
}
```

## 校验规则

| 字段 | 规则 |
|------|------|
| `X-User-Id` 请求头 | POST 必填；`ctype_digit`，1–18 字符，值 > 0 |
| `game` 请求体/查询 | 必填，非空，最多 64 字符 |
| `score` 请求体 | 只接受 ≥ 0 的整数（`is_int($score) && $score >= 0`） |
| `userId` 路径参数 | `ctype_digit`，最多 18 字符，值 > 0；否则 404 |
| `limit` 查询 | 1–100，默认 10；无效值静默钳制 |

字符串分数（`"100"`）以 422 拒绝，因为即使值是数字，`is_int()` 对字符串也返回 false。

零是有效分数——适用于玩家可能得零分的游戏。

## 路由

```
POST   /scores              提交分数（需要 X-User-Id）
GET    /leaderboard         前 N 名排名玩家（?game= 必填，?limit= 可选）
GET    /scores/{userId}     玩家的所有分数（?game= 必填）
```

## 游戏隔离

`game` 列充当命名空间。每个查询中始终包含 `WHERE game = :game`。在 "tetris" 中得分的玩家永远不会出现在 "snake" 排行榜上。

## 参见

- FT206 源码：`../NENE2-FT/leaderboardlog/`
- 相关：`docs/howto/rate-limiting.md`（FT200），`docs/howto/coupon-redemption.md`（FT204）
