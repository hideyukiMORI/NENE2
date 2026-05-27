# 操作指南：API 用量计量与配额管理

> **FT 参考**：FT321（`NENE2-FT/meterlog`）——按用户每日配额管理，机器密钥保护的用量记录，按端点明细，IDOR 防护，剩余量永不为负保证，24 个测试 / 92 个断言全部通过。

本指南展示如何构建一个用量计量系统，按用户每日跟踪 API 调用并强制执行可配置的每日配额。

## 数据库结构

```sql
CREATE TABLE quotas (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL UNIQUE,
    daily_limit INTEGER NOT NULL,
    updated_at  TEXT    NOT NULL
);

CREATE TABLE usage_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    endpoint    TEXT    NOT NULL,
    day_key     TEXT    NOT NULL,   -- 'YYYY-MM-DD'
    recorded_at TEXT    NOT NULL
);

CREATE INDEX idx_usage_user_day ON usage_events(user_id, day_key);
```

## 常量

```php
const DEFAULT_DAILY_LIMIT = 1000;  // 没有配额行时使用此默认值
```

## 认证模型

```
POST /quotas               → X-Admin-Key   （配额配置）
POST /usage                → X-Machine-Key （服务端用量记录）
POST /usage/check          → X-Machine-Key （预检配额）
GET  /usage/{id}/breakdown → X-User-Id（本人）或 X-Admin-Key（任意用户）
```

## 配额管理（管理员）

```php
POST /quotas  X-Admin-Key: admin-secret
{"user_id": 1, "daily_limit": 500}
→ 200  {"user_id": 1, "daily_limit": 500}

// 更新已有配额（Upsert）
POST /quotas  X-Admin-Key: admin-secret
{"user_id": 1, "daily_limit": 1000}
→ 200  {"user_id": 1, "daily_limit": 1000}

// 无管理员密钥  → 401
// 密钥错误      → 401
// daily_limit <= 0 → 422
```

## 配额状态

```php
GET /quotas/1
→ 200
{
  "user_id": 1,
  "daily_limit": 500,
  "used": 3,
  "remaining": 497,
  "allowed": true
}

// 没有配额行的用户 → 使用 DEFAULT_DAILY_LIMIT
GET /quotas/99
→ 200  {"user_id": 99, "daily_limit": 1000, "used": 0, "remaining": 1000, "allowed": true}
```

`remaining = max(0, daily_limit - used)` — **永不为负**。

## 记录用量

每次成功的 API 请求后由服务端调用：

```php
POST /usage  X-Machine-Key: machine-secret
{"user_id": 1, "endpoint": "GET /articles"}
→ 201
{
  "recorded": true,
  "user_id": 1,
  "endpoint": "GET /articles",
  "day_key": "2026-05-27"
}

// 无机器密钥  → 401
// user_id <= 0 → 422
// 空端点名   → 422
```

## 预检配额

```php
POST /usage/check  X-Machine-Key: machine-secret
{"user_id": 1}
→ 200  {"allowed": true,  "remaining": 5, "used": 0}  // 未超额
→ 200  {"allowed": false, "remaining": 0, "used": 2}  // 已耗尽
```

## 用量明细

```php
GET /usage/1/breakdown?date=2026-05-27  X-User-Id: 1
→ 200
{
  "user_id": 1,
  "date": "2026-05-27",
  "total": 3,
  "breakdown": [
    {"endpoint": "GET /articles", "count": 2},
    {"endpoint": "POST /articles", "count": 1}
  ]
}

// IDOR 被阻止
GET /usage/1/breakdown  X-User-Id: 2        → 403
// 管理员可访问任意用户
GET /usage/1/breakdown  X-Admin-Key: admin  → 200
// 无效日期
GET /usage/1/breakdown?date=not-a-date      → 422
```

---

## 漏洞评估

### V-01 — 无密钥管理配额 ✅ SAFE

**风险**：未认证的调用者为任意用户将配额设置为 0 或 INT_MAX。
**结论**：SAFE——`POST /quotas` 需要 `X-Admin-Key`。缺少或错误的密钥返回 401。

---

### V-02 — 管理员密钥大小写/变体绕过 ✅ SAFE

**风险**：攻击者尝试 `ADMIN-SECRET`、`admin_secret`、`""` 绕过密钥检查。
**结论**：SAFE——精确的 `hash_equals()` 匹配。所有变体均返回 401。

---

### V-03 — 非正 daily_limit ✅ SAFE

**风险**：`daily_limit=0` 或 `-1` 永久锁定用户。
**结论**：SAFE——`daily_limit <= 0` 返回 422。

---

### V-04 — 无机器密钥记录用量 ✅ SAFE

**风险**：外部调用者记录虚假用量以耗尽配额。
**结论**：SAFE——`POST /usage` 需要 `X-Machine-Key`。缺少/错误密钥返回 401。

---

### V-05 — 端点字段 SQL 注入 ✅ SAFE

**风险**：`"'; DROP TABLE usage_events; --"` 破坏数据库。
**结论**：SAFE——参数化查询。注入内容作为字面字符串存储。表数据不受影响。

---

### V-06 — 用量记录中非正 user_id ✅ SAFE

**风险**：`user_id=0/-1` 为不存在的用户插入行。
**结论**：SAFE——`user_id <= 0` 返回 422。

---

### V-07 — 明细的 IDOR ✅ SAFE

**风险**：用户读取其他用户的端点使用模式。
**结论**：SAFE——`X-User-Id` 与路径 `{id}` 进行比较。不匹配 → 403。管理员可绕过。

---

### V-08 — 明细中的无效日期 ✅ SAFE

**风险**：`date=` 参数中的路径遍历或不可能的日期导致崩溃或 SQL 错误。
**结论**：SAFE——`/^\d{4}-\d{2}-\d{2}$/` + `checkdate()` 验证。无效 → 422。

---

### V-09 — 剩余配额变为负数 ✅ SAFE

**风险**：当用量超过已减少的配额时，向客户端显示负数剩余量。
**结论**：SAFE——`remaining = max(0, $daily_limit - $used)`。

---

### V-10 — 空端点字符串 ✅ SAFE

**风险**：空端点创建无法使用的明细行。
**结论**：SAFE——`endpoint === ''` 返回 422。

---

### 漏洞总结

| ID | 漏洞 | 结论 |
|----|---------------|---------|
| V-01 | 无密钥管理配额 | ✅ SAFE |
| V-02 | 密钥大小写/变体绕过 | ✅ SAFE |
| V-03 | 非正 daily_limit | ✅ SAFE |
| V-04 | 无机器密钥记录用量 | ✅ SAFE |
| V-05 | 端点字段 SQL 注入 | ✅ SAFE |
| V-06 | 非正 user_id | ✅ SAFE |
| V-07 | 明细的 IDOR | ✅ SAFE |
| V-08 | 无效日期格式 | ✅ SAFE |
| V-09 | 剩余配额变为负数 | ✅ SAFE |
| V-10 | 空端点字符串 | ✅ SAFE |

**10 项 SAFE，0 项 EXPOSED** — 无关键发现。

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 允许 `remaining` 变为负数 | 令人困惑的负数；门控逻辑失效 |
| 用量记录无机器密钥保护 | 任何客户端可虚增/虚减其他用户的配额 |
| 明细无 IDOR 检查 | 端点使用模式泄露给未授权用户 |
| 配额检查前就记录用量 | 被拒绝的调用仍然消耗配额 |
| 允许 `daily_limit=0` | 用户从一开始就被永久锁定 |
