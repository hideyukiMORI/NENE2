# 操作指南：排行榜与分数追踪 API

本指南展示如何使用 NENE2 构建带分数提交、使用每用户最佳汇总的前 N 名排行以及个人分数历史的排行榜系统。
模式由 **leaderboardlog** 字段试验（FT206）演示。

## 功能特性

- 每个用户每个游戏的分数提交（`X-User-Id` 请求头）
- 前 N 名排行榜：每用户最佳分数降序排列（`MAX(score) GROUP BY user_id`）
- 任意用户/游戏组合的个人分数历史
- 个人最佳分数查询
- 可配置限制（钳制在 1–100）

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
CREATE INDEX IF NOT EXISTS idx_scores_user ON scores (user_id, game);
```

## 端点

| 方法 | 路径 | 认证 | 描述 |
|------|------|------|------|
| `POST` | `/scores` | 用户 | 提交分数 |
| `GET` | `/leaderboard?game=<game>` | 公开 | 前 N 名排行榜 |
| `GET` | `/scores/{userId}?game=<game>` | 公开 | 用户的分数历史 |

## 分数提交

```php
$game  = trim((string) ($body['game'] ?? ''));
$score = $body['score'] ?? null;

if ($game === '' || strlen($game) > 64) {
    return $this->problem(422, 'validation-failed', 'game required (max 64 chars).');
}
if (!is_int($score) || $score < 0) {
    return $this->problem(422, 'validation-failed', 'score must be a non-negative integer.');
}
```

关键点：
- `is_int($score)` — 严格检查；拒绝来自 JSON 的浮点数（`1.5`）和字符串
- 游戏名称限制 64 字符——防止超大游戏名称 DoS
- 分数非负——防止负分注入

创建成功时返回更新后的个人最佳：

```json
{ "message": "Score recorded.", "best_score": 9800 }
```

## 前 N 名排行查询

每用户最佳排行，在 PHP 中分配密集排名：

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

    $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ranked = [];
    foreach ($rows as $i => $row) {
        $ranked[] = array_merge($row, ['rank' => $i + 1]);
    }
    return $ranked;
}
```

- `MAX(score) GROUP BY user_id` — 每个用户一行，取个人最佳
- `ORDER BY best_score DESC` — 最高分优先
- LIMIT 使用 `PDO::PARAM_INT` 绑定——防 SQL 注入

示例响应：

```json
{
  "leaderboard": [
    { "user_id": 42, "best_score": 9800, "rank": 1 },
    { "user_id": 7,  "best_score": 7200, "rank": 2 }
  ]
}
```

## 限制钳制

```php
$limit = ctype_digit($limitRaw) ? (int) $limitRaw : 10;
if ($limit < 1 || $limit > 100) {
    $limit = 10;
}
```

无效或超出范围的限制静默默认为 10——绝不信任客户端提供的 LIMIT 整数。

## 校验模式

| 输入 | 检查 | 原因 |
|------|------|------|
| `score` | `is_int($score) && $score >= 0` | 拒绝浮点数、字符串、负数 |
| `game` | `strlen($game) <= 64` | 防止超大输入 |
| `limit` | `ctype_digit()` + 范围钳制 | ReDoS 安全，有界 |
| `userId`（路径） | `ctype_digit()` + `> 0` | DB 查询前先校验 |
