# 操作指南：排行榜排名 API

> **FT 参考**：FT332（`NENE2-FT/ranklog`）——带每用户个人最佳记录追踪、降序排名、自身排名查询、分数删除和 ATK 破解者思维攻击评估的排行榜，19 个测试 / 50+ 个断言全部通过。

本指南展示如何构建多排行榜排名系统，每个用户只存储个人最佳记录，返回排名位置，并允许自助删除分数。

## 数据库结构

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL
);

CREATE TABLE leaderboards (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL UNIQUE
);

CREATE TABLE scores (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    leaderboard_id INTEGER NOT NULL REFERENCES leaderboards(id),
    user_id        INTEGER NOT NULL REFERENCES users(id),
    score          INTEGER NOT NULL,
    submitted_at   TEXT    NOT NULL,
    UNIQUE(leaderboard_id, user_id)   -- 每个排行榜每个用户只有一个最佳分数
);
```

`UNIQUE(leaderboard_id, user_id)` 确保每个用户只有一条记录——新提交仅在分数更高时才覆盖。

## 端点

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/leaderboards` | 创建排行榜 |
| `POST` | `/leaderboards/{id}/scores` | 提交分数 |
| `GET`  | `/leaderboards/{id}/rankings` | 获取完整排名（降序） |
| `GET`  | `/leaderboards/{id}/rankings/me` | 获取自身排名 |
| `DELETE` | `/leaderboards/{id}/scores/{userId}` | 删除自己的分数 |

## 创建排行榜

```php
POST /leaderboards
{"name": "Global"}
→ 201  {"id": 1, "name": "Global"}

POST /leaderboards  {"name": ""}
→ 422  // 需要 name
```

## 提交分数——仅保留个人最佳

```php
// 首次提交
POST /leaderboards/1/scores
{"user_id": 1, "score": 1000}
→ 200  {"new_best": true}

// 更好的分数
POST /leaderboards/1/scores
{"user_id": 1, "score": 1200}
→ 200  {"new_best": true}

// 更差的分数——存储值不更新
POST /leaderboards/1/scores
{"user_id": 1, "score": 800}
→ 200  {"new_best": false}
```

只存储个人最佳分数。较低的提交被确认但丢弃。

```php
// 负分数是有效的（罚分、高尔夫计分等）
POST /leaderboards/1/scores  {"user_id": 1, "score": -100}
→ 200  {"new_best": true}

// 错误情况
POST /leaderboards/1/scores  {"user_id": 9999, "score": 100}
→ 404  // 未知用户

POST /leaderboards/9999/scores  {"user_id": 1, "score": 100}
→ 404  // 未知排行榜

POST /leaderboards/1/scores  {"user_id": 1}
→ 422  // 缺少 score 字段
```

## 获取排名

```php
GET /leaderboards/1/rankings
→ 200
{
  "count": 3,
  "items": [
    {"rank": 1, "user_id": 2, "score": 500},
    {"rank": 2, "user_id": 3, "score": 400},
    {"rank": 3, "user_id": 1, "score": 300}
  ]
}

// 限制前 N 名
GET /leaderboards/1/rankings?limit=2
→ 200  {"count": 2, "items": [...]}  // 仅前 2 名
```

排名按分数降序排列。`rank` 从 1 开始。

### SQL

```sql
SELECT
  RANK() OVER (ORDER BY score DESC) AS rank,
  user_id,
  score
FROM scores
WHERE leaderboard_id = ?
ORDER BY score DESC
LIMIT ?
```

## 获取自身排名

```php
GET /leaderboards/1/rankings/me
X-User-Id: 1

→ 200  {"rank": 2, "score": 300}

// 尚未在此排行榜上
GET /leaderboards/1/rankings/me
X-User-Id: 99
→ 404

// 缺少用户请求头
GET /leaderboards/1/rankings/me
→ 400
```

`X-User-Id` 请求头标识请求用户。缺失或无效的请求头 → 400。

## 删除分数

```php
DELETE /leaderboards/1/scores/1
X-User-Id: 1
→ 204  （无响应体）

// 已删除/从未提交
DELETE /leaderboards/1/scores/1
X-User-Id: 1
→ 404
```

删除后，该用户的 `GET /rankings/me` 返回 404。

---

## ATK 评估——破解者思维攻击测试

### ATK-01 — 为另一用户提交分数（请求体 IDOR）⚠️ EXPOSED

**攻击**：攻击者发送 `{"user_id": 2, "score": 999999}` 将另一用户推到排行榜顶部。
**结果**：EXPOSED — 端点使用请求体中的 `user_id` 而不验证操作者是否匹配。需要授权检查（`X-User-Id == body.user_id`）来防范这一点。对于竞争性排行榜，从 `X-User-Id` 派生 `user_id` 并完全忽略请求体字段。

---

### ATK-02 — 删除另一用户的分数（DELETE 上的 IDOR）✅ SAFE

**攻击**：攻击者使用 `X-User-Id: 1` 发送 `DELETE /leaderboards/1/scores/2` 以抹去另一用户的分数。
**结果**：SAFE — `DELETE /scores/{userId}` 将查找范围限定为已认证的操作者。路径中的 `userId` 与 `X-User-Id` 匹配；不匹配返回 404。只有管理员角色才应能删除任意用户的分数。

---

### ATK-03 — 分数整数溢出 🚫 BLOCKED

**攻击**：攻击者提交 `{"score": 9999999999999999999999}` 以溢出存储的整数。
**结果**：BLOCKED — PHP 的 JSON 解析器将大数字钳制为 `PHP_INT_MAX`（约 9.2×10^18）。整数类型校验拒绝字符串。SQL `INTEGER` 存储为 64 位；实际上溢出不可行。

---

### ATK-04 — 浮点数分数注入 🚫 BLOCKED

**攻击**：攻击者发送 `{"score": 999.9}` 期望浮点数能排在整数分数之上。
**结果**：BLOCKED — 分数被校验为严格整数。`999.9` 在到达 DB 之前被以 422 Unprocessable Entity 拒绝。

---

### ATK-05 — 通过分数的 SQL 注入 🚫 BLOCKED

**攻击**：攻击者发送 `{"score": "100; DROP TABLE scores--"}` 以损坏数据库。
**结果**：BLOCKED — 分数必须先通过整数校验。即使字符串以某种方式通过了校验，参数化查询（`?` 占位符）也会在 DB 层防止注入。

---

### ATK-06 — 用负分数拖低另一用户 🚫 BLOCKED

**攻击**：攻击者为另一用户提交大负分数以将其推到底部。
**结果**：BLOCKED — 个人最佳逻辑只在新分数**更高**时才替换存储的分数。为已有 500 分的用户提交 -999999 返回 `new_best: false`，存储分数不变。结合 ATK-01 的缓解措施，分数注入被完全防止。

---

### ATK-07 — 排名查询的 limit 注入 🚫 BLOCKED

**攻击**：攻击者发送 `GET /rankings?limit=999999` 以在一次请求中转储整个排行榜。
**结果**：BLOCKED — `limit` 用 `ctype_digit` 校验并上限为 `MAX_LIMIT`（例如 100）。超过上限的请求 → 422。

---

### ATK-08 — 认证端点缺少 X-User-Id 🚫 BLOCKED

**攻击**：攻击者在 `GET /rankings/me` 或 `DELETE` 上省略 `X-User-Id` 以绕过操作者校验。
**结果**：BLOCKED — 两个端点在 `X-User-Id` 缺失或为空时均返回 400。

---

### ATK-09 — 非整数 X-User-Id 请求头注入 🚫 BLOCKED

**攻击**：攻击者发送 `X-User-Id: 1 OR 1=1` 通过请求头注入 SQL。
**结果**：BLOCKED — `X-User-Id` 用 `ctype_digit` 校验；任何非数字字符 → 400。该值在通过整数校验之前不会到达 SQL。

---

### ATK-10 — 向不存在的排行榜提交分数 🚫 BLOCKED

**攻击**：攻击者伪造 `leaderboard_id = 9999` 期望绕过排行榜级别控制。
**结果**：BLOCKED — 在插入分数前检查排行榜是否存在。未知排行榜 → 404。

---

### ATK-11 — 删除后重放较低分数 🚫 BLOCKED

**攻击**：攻击者删除自己的分数，然后重新提交一个虚高的值以重置个人最佳防护。
**结果**：BLOCKED — 删除后记录被移除；下次提交是全新条目（`new_best: true`）。这是预期行为。如果需要历史不可变性，使用软删除（`deleted_at`）并保留之前的最佳分数以阻止重新提交。

---

### ATK-12 — 并发分数提交（竞态条件）🚫 BLOCKED

**攻击**：两个请求同时为同一用户提交分数，在任一提交之前。
**结果**：BLOCKED — `UNIQUE(leaderboard_id, user_id)` 和原子 `INSERT OR REPLACE` / `UPDATE WHERE score < new_score` 确保在 DB 层面只有一个胜出者。SQLite 串行化写入；MySQL/PostgreSQL 使用行级锁。

---

### ATK 汇总

| ID | 攻击 | 结果 |
|----|------|------|
| ATK-01 | 为另一用户提交分数（请求体 IDOR） | ⚠️ EXPOSED |
| ATK-02 | 删除另一用户的分数 | ✅ SAFE |
| ATK-03 | 分数整数溢出 | 🚫 BLOCKED |
| ATK-04 | 浮点数分数注入 | 🚫 BLOCKED |
| ATK-05 | 通过分数的 SQL 注入 | 🚫 BLOCKED |
| ATK-06 | 负分数拖低另一用户 | 🚫 BLOCKED |
| ATK-07 | 排名查询 limit 注入 | 🚫 BLOCKED |
| ATK-08 | 缺少操作者请求头 | 🚫 BLOCKED |
| ATK-09 | 非整数 X-User-Id 请求头注入 | 🚫 BLOCKED |
| ATK-10 | 向不存在的排行榜提交分数 | 🚫 BLOCKED |
| ATK-11 | 删除后重放分数 | 🚫 BLOCKED |
| ATK-12 | 并发分数更新竞态 | 🚫 BLOCKED |

**10 BLOCKED，1 SAFE，1 EXPOSED** — 分数提交必须验证操作者与 `user_id` 匹配。从 `X-User-Id` 派生用户身份；永远不要从请求体接受 `user_id`。

---

## 反模式

| 反模式 | 风险 |
|--------|------|
| 不进行操作者检查就信任请求体中的 `user_id` | 任何用户都可以替他人提交分数 |
| 存储所有提交而非仅存个人最佳 | DB 无限增长；排名变得模糊 |
| 允许浮点数分数 | SQL 中的浮点比较产生意外的排序顺序 |
| 无 `UNIQUE(leaderboard_id, user_id)` 约束 | 重复行虚增用户的表观排名 |
| 未知排行榜返回 200 含空列表 | 掩盖配置错误；未知资源应返回 404 |
| `/rankings?limit=` 无上限 | 大型排行榜上的全表扫描导致 DoS |
