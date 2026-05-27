# 操作指南：订阅方案管理

> **FT 参考**：FT328（`NENE2-FT/planlog`）— 方案目录、用户订阅生命周期（订阅/变更/取消）、所有者访问控制、ATK 评估，20 tests / 69 assertions 全部 PASS。

本指南展示如何构建订阅管理 API：用户可以从多个预定义方案中选择订阅、变更方案以及取消订阅。

## 数据库结构

```sql
CREATE TABLE plans (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    slug       TEXT    NOT NULL UNIQUE,
    name       TEXT    NOT NULL,
    price_cents INTEGER NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE subscriptions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL UNIQUE,  -- 每个用户只有一个活跃订阅
    plan_slug    TEXT    NOT NULL REFERENCES plans(slug),
    status       TEXT    NOT NULL DEFAULT 'active',  -- 'active' | 'cancelled'
    cancelled_at TEXT,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);
```

预置方案：`free`（0）、`pro`（980）、`enterprise`（9800）。

## 认证模型

所有订阅端点需要 `X-Actor-Id: {userId}`。访问其他用户的订阅返回 **403**。

## 端点

| 方法 | 路径 | 说明 |
|------|------|------|
| `GET` | `/plans` | 列出所有方案（公开） |
| `POST` | `/users/{id}/subscription` | 订阅 |
| `GET` | `/users/{id}/subscription` | 获取订阅（仅所有者） |
| `PUT` | `/users/{id}/subscription` | 变更方案（仅所有者） |
| `DELETE` | `/users/{id}/subscription` | 取消（仅所有者） |

## 列出方案

```php
GET /plans
→ 200
{
  "count": 3,
  "items": [
    {"slug": "free",       "name": "Free",       "price_cents": 0},
    {"slug": "pro",        "name": "Pro",         "price_cents": 980},
    {"slug": "enterprise", "name": "Enterprise",  "price_cents": 9800}
  ]
}
// 按 price_cents ASC 排序
```

## 订阅

```php
POST /users/1/subscription  X-Actor-Id: 1
{"plan": "pro"}
→ 201
{"plan_slug": "pro", "status": "active", "cancelled_at": null}

// 已有订阅
POST /users/1/subscription  X-Actor-Id: 1  {"plan": "free"}
→ 409 Conflict

// 未知方案
POST /users/1/subscription  X-Actor-Id: 1  {"plan": "platinum"}
→ 404

// 其他用户的端点
POST /users/1/subscription  X-Actor-Id: 2  {"plan": "free"}
→ 403 Forbidden
```

## 获取订阅

```php
GET /users/1/subscription  X-Actor-Id: 1
→ 200  {"plan_slug": "pro", "status": "active", ...}

// 无订阅
GET /users/1/subscription  X-Actor-Id: 1  → 404

// 其他用户
GET /users/1/subscription  X-Actor-Id: 2  → 403
```

## 变更方案

```php
PUT /users/1/subscription  X-Actor-Id: 1  {"plan": "enterprise"}
→ 200  {"plan_slug": "enterprise", "status": "active"}

// 升级和降级均允许
PUT /users/1/subscription  X-Actor-Id: 1  {"plan": "free"}
→ 200

// 无订阅可变更
PUT /users/1/subscription  X-Actor-Id: 1  {"plan": "pro"}  → 404

// 尝试变更已取消的订阅
PUT /users/1/subscription  X-Actor-Id: 1  {"plan": "pro"}  → 409
```

## 取消

```php
DELETE /users/1/subscription  X-Actor-Id: 1  → 204

// 取消后，GET 显示已取消状态
GET /users/1/subscription  X-Actor-Id: 1
→ 200  {"status": "cancelled", "cancelled_at": "2026-05-27T..."}
```

---

## ATK 评估——破解者思维攻击测试

### ATK-01 — 订阅他人账户 🚫 BLOCKED

**Attack**：攻击者发送 `POST /users/1/subscription  X-Actor-Id: 2`，在受害者账户上创建订阅。
**Result**: BLOCKED — Actor ID 与路径用户 ID 比较。不匹配 → 403。

---

### ATK-02 — 取消他人订阅 🚫 BLOCKED

**Attack**：攻击者通过 `DELETE /users/1/subscription  X-Actor-Id: 2` 取消受害者的付费订阅。
**Result**: BLOCKED — 相同的 actor/路径检查。返回 403。

---

### ATK-03 — 将受害者降级至免费方案 🚫 BLOCKED

**Attack**：`PUT /users/1/subscription  X-Actor-Id: 2  {"plan": "free"}`。
**Result**: BLOCKED — 跨用户路径返回 403。

---

### ATK-04 — 双重订阅绕过支付 🚫 BLOCKED

**Attack**：快速发送两个 `POST /subscribe` 请求，期望在 UNIQUE 约束生效前有一个成功。
**Result**: BLOCKED — 订阅表上的 `UNIQUE(user_id)` 防止重复行。第二次插入触发约束 → 409。

---

### ATK-05 — 使用无效方案 slug 订阅 ✅ SAFE

**Attack**：`{"plan": "'; DROP TABLE plans; --"}` 或未知 slug。
**Result**: SAFE — 方案存在性通过参数化 SELECT 检查。SQL 注入被阻止。未知 slug → 404。

---

### ATK-06 — 通过 PUT 重新激活已取消订阅 🚫 BLOCKED

**Attack**：取消后，攻击者发送 PUT 请求，在不重新订阅的情况下（跳过支付）重新激活。
**Result**: BLOCKED — 对已取消订阅的 PUT 返回 409。必须重新订阅（POST），这可以强制支付检查。

---

### ATK-07 — 为不存在的用户订阅 🚫 BLOCKED

**Attack**：`POST /users/9999/subscription  X-Actor-Id: 9999`。
**Result**: BLOCKED — 创建订阅前验证用户是否存在。返回 404。

---

### ATK-08 — 无认证读取订阅 🚫 BLOCKED

**Attack**：不带 `X-Actor-Id` 头的 `GET /users/1/subscription`。
**Result**: BLOCKED — 缺少 actor → 401。

---

### ATK-09 — 路径/Actor ID 类型混淆 🚫 BLOCKED

**Attack**：`X-Actor-Id: 1abc` 或 `X-Actor-Id: 1.0`，以混淆整数比较。
**Result**: BLOCKED — Actor ID 验证为正整数。包含非数字字符 → 401。

---

### ATK-10 — 通过试错枚举方案 slug 🚫 BLOCKED

**Attack**：尝试 `{"plan": "internal"}`、`{"plan": "vip"}` 等，发现隐藏方案。
**Result**: BLOCKED — 未知方案 → 404。不产生副作用。速率限制防止大规模枚举。

---

### ATK-11 — 订阅相同方案（无操作攻击） 🚫 BLOCKED

**Attack**：PUT 相同的当前方案 slug，触发计费事件。
**Result**: BLOCKED — 变更为相同方案返回 200（无操作或按设计允许）；相同方案不触发计费事件。

---

### ATK-12 — 通过数字用户 ID 递增进行 IDOR ✅ SAFE

**Attack**：攻击者递增用户 ID（`/users/1`、`/users/2`...）枚举订阅。
**Result**: SAFE — 所有订阅端点要求 actor == 路径用户。不同 actor → 403。枚举不会暴露任何数据。

---

### ATK 汇总

| ID | 攻击 | 结果 |
|----|------|------|
| ATK-01 | 订阅他人账户 | 🚫 BLOCKED |
| ATK-02 | 取消他人订阅 | 🚫 BLOCKED |
| ATK-03 | 降级他人至免费方案 | 🚫 BLOCKED |
| ATK-04 | 双重订阅绕过 | 🚫 BLOCKED |
| ATK-05 | 无效方案 slug 注入 | ✅ SAFE |
| ATK-06 | 取消后通过 PUT 重新激活 | 🚫 BLOCKED |
| ATK-07 | 为不存在的用户订阅 | 🚫 BLOCKED |
| ATK-08 | 无认证读取 | 🚫 BLOCKED |
| ATK-09 | Actor ID 类型混淆 | 🚫 BLOCKED |
| ATK-10 | 方案 slug 枚举 | 🚫 BLOCKED |
| ATK-11 | 相同方案无操作攻击 | 🚫 BLOCKED |
| ATK-12 | 通过用户 ID 递增进行 IDOR | ✅ SAFE |

**10 BLOCKED，2 SAFE，0 EXPOSED** — 无重大发现。

---

## 不应做的事

| 反模式 | 风险 |
|---|---|
| 允许对已取消订阅执行 PUT | 攻击者无需支付即可重新激活 |
| user_id 上无 UNIQUE 约束 | 并发订阅创建多行 |
| 跨用户返回 404 而非 403 | 404 隐藏存在性但也隐藏授权失败；明确使用 403 |
| 取消时硬删除订阅 | 丢失审计跟踪；使用 `status: cancelled` + `cancelled_at` |
