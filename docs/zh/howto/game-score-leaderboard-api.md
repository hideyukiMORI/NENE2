# 操作指南：游戏分数与排行榜 API

> **FT 参考**：FT259（`NENE2-FT/scorelog`）——游戏分数提交，支持批量插入（最多 100 条，全有或全无），按玩家的排行榜含 `best_score` 聚合和 `play_count`，防止负分，分页，20 个测试全部通过。

本指南展示如何构建游戏评分系统：记录单次游玩分数、批量导入结果并计算每款游戏的排名榜单。

## 数据库结构

```sql
CREATE TABLE scores (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    player     TEXT    NOT NULL,
    game       TEXT    NOT NULL,
    score      INTEGER NOT NULL CHECK(score >= 0),
    played_at  TEXT    NOT NULL,      -- ISO 8601 日期：YYYY-MM-DD
    created_at TEXT    NOT NULL
);

CREATE INDEX idx_scores_game      ON scores (game);
CREATE INDEX idx_scores_player    ON scores (player);
CREATE INDEX idx_scores_played_at ON scores (played_at);
```

`CHECK(score >= 0)` 在 DB 层面防止负分。`game` 和 `player` 上的索引支持过滤列表和排行榜查询。

## 端点

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/scores` | 提交单条分数 |
| `POST` | `/scores/bulk` | 批量提交最多 100 条分数 |
| `GET` | `/scores` | 列出分数（过滤 + 分页） |
| `GET` | `/scores/leaderboard` | 按玩家的游戏排行榜 |
| `GET` | `/scores/{id}` | 获取单条分数 |
| `DELETE` | `/scores/{id}` | 删除分数 |

## 提交分数

```php
POST /scores
{
  "player":    "Alice",
  "game":      "tetris",
  "score":     1500,
  "played_at": "2026-01-15"
}

→ 201
{
  "id": 1,
  "player": "Alice",
  "game": "tetris",
  "score": 1500,
  "played_at": "2026-01-15",
  "created_at": "..."
}
```

每位玩家每款游戏可有多条分数记录——每次游玩是独立记录。

### 校验

```php
POST /scores  {"game": "tetris", "score": 100, "played_at": "2026-01-15"}
→ 422  // player 是必填项

POST /scores  {"player": "Alice", "game": "tetris", "score": -1, "played_at": "2026-01-15"}
→ 422  // score 必须 >= 0

POST /scores  {"player": "Alice", "game": "tetris", "score": 100, "played_at": "15/01/2026"}
→ 422  // played_at 必须是 YYYY-MM-DD 格式

POST /scores  {"player": "Alice", "game": "tetris", "score": 0, "played_at": "2026-01-15"}
→ 201  // score = 0 有效
```

## 列出分数

```php
// 所有分数
GET /scores
→ 200  {"items": [...], "total": 10}

// 按游戏过滤
GET /scores?game=tetris
→ 200  {"items": [/* 仅 tetris 分数 */], "total": 3}

// 按玩家过滤
GET /scores?player=Alice
→ 200  {"items": [/* 仅 Alice 的分数 */], "total": 2}

// 分页
GET /scores?limit=2&offset=1
→ 200  {"items": [/* 2 条，从索引 1 开始 */], "total": 5}
```

## 批量提交

```php
POST /scores/bulk
{
  "scores": [
    {"player": "Alice", "game": "tetris", "score": 1000, "played_at": "2026-01-15"},
    {"player": "Bob",   "game": "tetris", "score": 2000, "played_at": "2026-01-16"},
    {"player": "Carol", "game": "snake",  "score": 500,  "played_at": "2026-01-15"}
  ]
}

→ 201
{
  "created": 3,
  "scores": [
    {"id": 1, "player": "Alice", ...},
    {"id": 2, "player": "Bob",   ...},
    {"id": 3, "player": "Carol", ...}
  ]
}
```

### 批量校验规则

```php
// 空数组
POST /scores/bulk  {"scores": []}
→ 422  // 至少需要 1 条

// 任何无效条目导致整个批次失败
POST /scores/bulk
{"scores": [
  {"player": "Alice", "game": "tetris", "score": 1000, "played_at": "2026-01-15"},
  {"player": "",      "game": "tetris", "score": 500,  "played_at": "2026-01-15"}
]}
→ 422  // "player" 不能为空——不插入任何记录

// 超过 100 条
POST /scores/bulk  {"scores": [...101条...]}
→ 422  // 每次批量请求最多 100 条
```

**全有或全无**：在插入任何记录前校验所有条目。使用 DB 事务确保原子性。

### 批量实现

```php
public function bulkSubmit(array $entries): array
{
    // 先校验所有条目
    foreach ($entries as $i => $entry) {
        $this->validate($entry, "scores[{$i}]");
    }

    // 在事务中插入所有记录
    $this->db->beginTransaction();
    try {
        $ids = [];
        foreach ($entries as $entry) {
            $ids[] = $this->repo->insert($entry['player'], $entry['game'], $entry['score'], $entry['played_at'], $now);
        }
        $this->db->commit();
        return $this->repo->findByIds($ids);
    } catch (\Throwable $e) {
        $this->db->rollback();
        throw $e;
    }
}
```

## 排行榜

```php
GET /scores/leaderboard?game=tetris

→ 200
{
  "game":    "tetris",
  "top":     10,
  "entries": [
    {"rank": 1, "player": "Alice", "best_score": 3000, "play_count": 2},
    {"rank": 2, "player": "Bob",   "best_score": 2000, "play_count": 1},
    {"rank": 3, "player": "Carol", "best_score": 500,  "play_count": 1}
  ]
}
```

每位玩家出现**一次**——取其所有游玩记录中**最高**分，以及 `play_count`。

### Top-N 限制

```php
GET /scores/leaderboard?game=tetris&top=3
→ 200  {"entries": [...3位玩家...], "top": 3}

GET /scores/leaderboard?game=tetris&top=0
→ 422  // top 必须 >= 1

GET /scores/leaderboard          // 缺少 game
→ 422
```

### 排行榜 SQL

```sql
SELECT
    player,
    MAX(score)   AS best_score,
    COUNT(*)     AS play_count,
    RANK() OVER (ORDER BY MAX(score) DESC) AS rank
FROM scores
WHERE game = ?
GROUP BY player
ORDER BY best_score DESC
LIMIT ?
```

`RANK() OVER (ORDER BY MAX(score) DESC)` 为并列玩家分配相同排名（后续排名有间隔）。若不想有间隔，使用 `DENSE_RANK()`。

---

## 反模式

| 反模式 | 风险 |
|--------|------|
| 仅在应用层禁止负分 | `CHECK(score >= 0)` DB 约束是最终防线；应用层校验可被绕过 |
| 不在事务中逐条插入批量条目 | 部分失败导致一半批次进入 DB；无法区分已提交和未提交 |
| 在插入循环内部校验批量条目 | 前 N 条已插入后校验才失败；DB 中留有部分数据 |
| 无 GROUP BY 使用 `score = MAX(score)` | 聚合整个表而不按玩家分组；排行榜结果错误 |
| 排行榜不加 LIMIT 返回所有玩家 | 无上限的全表扫描和排序；大分数表存在 DoS 风险 |
| 在 PHP 中获取所有分数计算 `best_score` | 每位玩家 O(N)；应使用 SQL `MAX()` 聚合 |
