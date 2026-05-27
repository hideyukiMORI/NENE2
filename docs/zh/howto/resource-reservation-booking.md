# 操作指南：资源预约与预订 API

> **FT 参考**：FT335（`NENE2-FT/reservationlog`）——资源时间段预订，带半开区间重叠防止、公开响应中排除 user_id、取消 IDOR 防护（403）、管理员/用户双层访问，30 个测试 / 70+ 断言全部通过。

本指南展示如何构建房间/资源预订系统：创建可预订资源（管理员）、预订时间段（用户）、原子性防止重叠，以及保护公开响应中的用户隐私。

## 数据库结构

```sql
CREATE TABLE resources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE bookings (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL REFERENCES resources(id),
    user_id     INTEGER NOT NULL,
    starts_at   TEXT    NOT NULL,   -- ISO 8601 UTC
    ends_at     TEXT    NOT NULL,
    note        TEXT,               -- 可选
    created_at  TEXT    NOT NULL
);
```

日期存储为 ISO 8601 UTC 字符串（`2026-06-01T09:00:00Z`）。UTC ISO 字符串的字典序比较是正确的。

## 端点

| 方法 | 路径 | 认证 | 说明 |
|--------|------|------|-------------|
| `POST` | `/resources` | 管理员 | 创建可预订资源 |
| `POST` | `/resources/{id}/book` | 用户 | 预订时间段 |
| `DELETE` | `/bookings/{id}` | 用户（所有者） | 取消自己的预订 |
| `GET` | `/bookings` | 用户 | 列出自己的预订 |
| `GET` | `/resources/{id}/bookings` | 管理员 | 列出资源的所有预订 |

## 创建资源（管理员）

```php
POST /resources
X-Admin-Key: admin-secret
{"name": "Meeting Room 1"}
→ 201  {"resource": {"id": 1, "name": "Meeting Room 1", "created_at": "..."}}

// 无管理员密钥
POST /resources  {"name": "Room"}
→ 401

POST /resources  X-Admin-Key: admin-secret  {"name": ""}
→ 422  // name 是必填的

POST /resources  X-Admin-Key: admin-secret  {"name": "x".repeat(201)}
→ 422  // name 过长（最大 200 个字符）
```

## 预订时间段

```php
POST /resources/1/book
X-User-Id: 101
{"starts_at": "2026-06-01T09:00:00Z", "ends_at": "2026-06-01T10:00:00Z"}
→ 201
{
  "booking": {
    "id": 1,
    "resource_id": 1,
    "starts_at": "2026-06-01T09:00:00Z",
    "ends_at": "2026-06-01T10:00:00Z",
    "note": null,
    "created_at": "..."
    // user_id 不返回——IDOR 防护
  }
}

// 可选备注
POST /resources/1/book  X-User-Id: 101
{"starts_at": "2026-06-01T09:00:00Z", "ends_at": "2026-06-01T10:00:00Z", "note": "Team meeting"}
→ 201  {"booking": {..., "note": "Team meeting"}}
```

### 校验错误

```php
// ends_at 早于 starts_at
{"starts_at": "2026-06-01T10:00:00Z", "ends_at": "2026-06-01T09:00:00Z"}
→ 422

// starts_at == ends_at（零时长）
{"starts_at": "2026-06-01T09:00:00Z", "ends_at": "2026-06-01T09:00:00Z"}
→ 422

// 缺少 starts_at
{"ends_at": "2026-06-01T10:00:00Z"}
→ 422

// 缺少 X-User-Id
POST /resources/1/book  (无头)
→ 400

// 零或溢出的 X-User-Id
X-User-Id: 0    → 400
X-User-Id: 9999999999999999999  → 400

// 未知资源
POST /resources/9999/book  X-User-Id: 101  {...}
→ 404
```

## 重叠防止——半开区间

时间段是**半开的**：`[starts_at, ends_at)`。一个预订的结束和下一个预订的开始相等但不重叠。

```php
// 预订 09:00–10:00
POST /resources/1/book  {"starts_at": "09:00", "ends_at": "10:00"}  → 201

// 重叠——在现有时间段内开始
POST /resources/1/book  {"starts_at": "10:00", "ends_at": "11:00"}  → 201  ✅ 相邻，允许

// 重叠——内部
POST /resources/1/book  {"starts_at": "09:30", "ends_at": "11:00"}  → 409  ❌ 重叠

// 相同时间段
POST /resources/1/book  {"starts_at": "09:00", "ends_at": "10:00"}  → 409  ❌

// 相同资源，不重叠
POST /resources/1/book  {"starts_at": "14:00", "ends_at": "15:00"}  → 201  ✅

// 不同资源上的相同时间段——始终允许
POST /resources/2/book  {"starts_at": "09:00", "ends_at": "10:00"}  → 201  ✅
```

### 重叠 SQL 查询

```sql
-- 检测冲突：NOT (new.ends_at <= existing.starts_at OR new.starts_at >= existing.ends_at)
SELECT COUNT(*) FROM bookings
WHERE resource_id = ?
  AND starts_at < ?   -- existing.starts_at < new.ends_at
  AND ends_at   > ?   -- existing.ends_at > new.starts_at
```

如果 count > 0，返回 409 Conflict。

## 取消预订（IDOR 防护）

```php
DELETE /bookings/1
X-User-Id: 101
→ 200  {"cancelled": true}

// 错误用户 → 403，不是 404
DELETE /bookings/1
X-User-Id: 102
→ 403  // 用户 102 不拥有预订 1

// 未找到 → 404
DELETE /bookings/9999
X-User-Id: 101
→ 404
```

**错误用户取消返回 403（而非 404）**——返回 404 会让用户探测其他用户的预订 ID。预订存在；请求者不是所有者。

```php
// 取消后，时间段空闲
DELETE /bookings/1  X-User-Id: 101  → 200
POST /resources/1/book  X-User-Id: 102  {"starts_at": "09:00", "ends_at": "10:00"}  → 201
```

### ID 校验

```php
DELETE /bookings/0                    → 422  // 零无效
DELETE /bookings/99999999999999999999 → 422  // 溢出
POST /resources/0/book  X-User-Id: 101 {...} → 422  // resource_id 零无效
```

## 列出自己的预订（用户）

```php
GET /bookings
X-User-Id: 101
→ 200
{
  "total": 2,
  "data": [
    {"id": 1, "resource_id": 1, "starts_at": "...", "ends_at": "...", "note": null, "created_at": "..."}
    // user_id 不包含
  ]
}

// 其他用户的预订不返回
// 用户 101 只看到自己的预订，即使用户 102 也有预订
```

## 列出资源预订（管理员）

```php
GET /resources/1/bookings
X-Admin-Key: admin-secret
→ 200
{
  "total": 2,
  "data": [
    {"id": 1, "user_id": 101, "starts_at": "2026-06-01T09:00:00Z", ...},  // user_id 可见
    {"id": 2, "user_id": 102, "starts_at": "2026-06-01T14:00:00Z", ...}
  ]
}
// 按 starts_at ASC 排序

GET /resources/1/bookings  (无管理员密钥)  → 401
GET /resources/9999/bookings  X-Admin-Key: key  → 404
```

管理员在响应中收到 `user_id`；公开用户端点从不返回 `user_id`。

---

## 不应做的事

| 反模式 | 风险 |
|---|---|
| 重叠检查：`new.starts_at < existing.ends_at AND new.ends_at > existing.starts_at`（闭区间） | 相邻时间段（A 的结束 = B 的开始）被错误拒绝为重叠 |
| 在公开预订响应中返回 `user_id` | 暴露每个预订的所有者，使用户枚举成为可能 |
| 错误用户取消返回 404 | 攻击者确认预订存在；使用 403 确认所有权不匹配 |
| 接受 `starts_at >= ends_at` | 零或负时长预订破坏可用性计算 |
| 重叠查询中没有 resource_id 作用域 | 用户 A 在资源 1 上的预订阻止资源 2（误判冲突） |
| 从请求体信任 `user_id` | 攻击者以任意用户身份预订；始终从 `X-User-Id` 头读取身份 |
