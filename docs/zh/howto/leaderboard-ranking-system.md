# 如何使用 NENE2 构建排行榜（排名系统）

本指南介绍如何构建一个用户可提交分数、查看排名并检查自身排名的排行榜。每个用户在每个排行榜上只保留最佳分数。

**字段试验**：FT141  
**NENE2 版本**：^1.5  
**涵盖主题**：最佳分数 UPDATE 模式，使用 COUNT(*) 计算排名，分数所有权检查，查询参数限制，漏洞评估

---

## 我们要构建的内容

- `POST /leaderboards` — 创建排行榜
- `POST /leaderboards/{id}/scores` — 提交分数（仅保留个人最佳）
- `GET /leaderboards/{id}/rankings` — 前 N 名排行（分数降序，`?limit=N`）
- `GET /leaderboards/{id}/rankings/me` — 调用者自身的排名和分数
- `DELETE /leaderboards/{id}/scores/{userId}` — 删除自己的分数（仅所有者）

---

## 数据库结构

```sql
CREATE TABLE leaderboards (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE scores (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    leaderboard_id INTEGER NOT NULL,
    user_id        INTEGER NOT NULL,
    score          INTEGER NOT NULL,
    submitted_at   TEXT    NOT NULL,
    UNIQUE (leaderboard_id, user_id),
    FOREIGN KEY (leaderboard_id) REFERENCES leaderboards(id),
    FOREIGN KEY (user_id)        REFERENCES users(id)
);
```

`UNIQUE (leaderboard_id, user_id)` — 每个排行榜每个用户只有一条分数记录；更新时替换。

---

## 最佳分数 UPDATE 模式

```php
public function submitScore(int $leaderboardId, int $userId, int $score, string $now): bool
{
    $existing = $this->findScore($leaderboardId, $userId);

    if ($existing === null) {
        $this->executor->execute(
            'INSERT INTO scores (leaderboard_id, user_id, score, submitted_at) VALUES (?, ?, ?, ?)',
            [$leaderboardId, $userId, $score, $now],
        );
        return true;
    }

    if ($score > $existing['score']) {
        $this->executor->execute(
            'UPDATE scores SET score = ?, submitted_at = ? WHERE leaderboard_id = ? AND user_id = ?',
            [$score, $now, $leaderboardId, $userId],
        );
        return true;
    }

    return false;  // 不是新的个人最佳
}
```

当分数是新的个人最佳时返回 `true`（有助于 UI 反馈），被忽略时返回 `false`。

---

## 使用 COUNT(*) 计算排名

与其使用窗口函数（并非所有 SQLite 版本都有 `RANK()`），不如统计有多少分数更高：

```php
public function getUserRank(int $leaderboardId, int $userId): ?int
{
    $score = $this->findScore($leaderboardId, $userId);

    if ($score === null) {
        return null;
    }

    $row   = $this->executor->fetchOne(
        'SELECT COUNT(*) as cnt FROM scores WHERE leaderboard_id = ? AND score > ?',
        [$leaderboardId, $score['score']],
    );
    $ahead = isset($row['cnt']) ? (int) $row['cnt'] : 0;

    return $ahead + 1;
}
```

如果 0 个用户有更高的分数，排名为 1。如果 5 个用户更高，排名为 6。并列时获得相同排名。

---

## 分数所有权检查（IDOR 防护）

```php
if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'cannot delete another user\'s score'], 403);
}
```

DELETE 操作前始终检查调用者身份与目标用户是否匹配。没有此检查，任何经认证的用户都可以删除任意分数。

---

## 查询参数限制

```php
$limit = isset($query['limit']) && is_numeric($query['limit']) ? (int) $query['limit'] : 10;

if ($limit <= 0 || $limit > 100) {
    $limit = 10;
}
```

限制上限以防止 `?limit=99999` 扫描整个表。

---

## 漏洞评估（FT141）

| ID | 攻击 | 预期 | 结果 |
|----|------|------|------|
| VULN-A | IDOR：删除另一用户的分数 | 403 | 通过 |
| VULN-B | 为另一用户提交分数 | 200（允许） | 通过 |
| VULN-C | 排行榜名称中的 SQL 注入 | 201（字面量） | 通过 |
| VULN-D | /rankings/me 缺少 X-User-Id | 400 | 通过 |
| VULN-E | 非数字 X-User-Id | 非 200 | 通过 |
| VULN-F | 负排行榜 ID | 非 200 | 通过 |
| VULN-G | PHP_INT_MAX 作为分数 | 200（有效整数） | 通过 |
| VULN-H | 浮点数分数（类型混淆） | 422 | 通过 |
| VULN-I | 字符串分数（类型混淆） | 422 | 通过 |
| VULN-J | DELETE 缺少 X-User-Id | 400 | 通过 |
| VULN-K | 分数提交中 user_id=0 | 422 | 通过 |
| VULN-L | `?limit=99999`（超大限制） | 200 + 钳制 | 通过 |

所有 12 个漏洞测试通过。未发现漏洞。

---

## 常见陷阱

| 陷阱 | 解决方案 |
|------|----------|
| 存储所有提交的分数而非最佳 | 在 INSERT 前进行 `findScore()` 检查；更高时 UPDATE |
| 使用 SQLite 中可能不存在的 RANK() | `COUNT(*) WHERE score > ?` 给出等效排名 |
| 分数 DELETE 上的 IDOR | 检查 `$actorId !== $userId` → 403 |
| 未限制的 limit 参数导致全表扫描 | 将 `limit` 钳制到 1–100 范围 |
| 浮点数/字符串分数绕过 `is_int()` | PHP 8 JSON 解码中 `!is_int($score)` 拒绝浮点数和字符串 |
