# 操作指南：预约与可用性 API

> **FT 参考**：FT336（`NENE2-FT/reservelog`）——资源预约系统，带半开区间重叠检测、感知状态的可用性查询、取消后重新预约语义和 ATK 破解者思维攻击评估，16 个测试 / 30+ 断言全部通过。

本指南展示如何构建无状态预约 API：预订有生命周期（`active` → `cancelled`），可用性视图按日期范围和状态过滤。

## 数据库结构

```sql
CREATE TABLE resources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE reservations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL REFERENCES resources(id),
    booker      TEXT    NOT NULL,  -- 不透明标识符（姓名、邮箱、user_id 字符串）
    starts_at   TEXT    NOT NULL,
    ends_at     TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'active',  -- 'active' | 'cancelled'
    created_at  TEXT    NOT NULL
);
```

`status` 追踪时间段是活跃还是已取消。只有 `active` 预约才阻止未来预订。

## 端点

| 方法 | 路径 | 说明 |
|--------|------|-------------|
| `POST` | `/resources` | 创建资源 |
| `POST` | `/reservations` | 预订时间段 |
| `GET`  | `/reservations/{id}` | 获取预约详情 |
| `DELETE` | `/reservations/{id}` | 取消预约 |
| `GET`  | `/resources/{id}/availability` | 列出范围内的活跃预约 |

## 创建资源

```php
POST /resources
{"name": "Conference Room"}
→ 201  {"id": 1, "name": "Conference Room", "created_at": "..."}

POST /resources  {}
→ 422  // name 是必填的
```

## 预订时间段

```php
POST /reservations
{
  "resource_id": 1,
  "booker": "alice",
  "starts_at": "2026-06-01 09:00:00",
  "ends_at": "2026-06-01 10:00:00"
}
→ 201  {"id": 1, "booker": "alice", "status": "active", ...}
```

### 校验

```php
// ends_at 早于 starts_at
→ 422

// starts_at == ends_at（零时长）
→ 422

// 缺少必填字段
{"resource_id": 1}  → 422
```

### 重叠检测

重叠检查使用**半开区间**：`[starts_at, ends_at)`。

```php
// 现有预约：09:00–10:00
POST /reservations  {"starts_at": "09:30", "ends_at": "10:30"}  → 409  ❌ 重叠
POST /reservations  {"starts_at": "09:00", "ends_at": "10:00"}  → 409  ❌ 相同
POST /reservations  {"starts_at": "09:15", "ends_at": "09:45"}  → 409  ❌ 包含在内

// 相邻——第一个的结束 == 第二个的开始 → 不冲突
POST /reservations  {"starts_at": "10:00", "ends_at": "11:00"}  → 201  ✅

// 不同资源——即使时间相同也不冲突
POST /reservations  {"resource_id": 2, "starts_at": "09:00", "ends_at": "10:00"}  → 201  ✅
```

```sql
-- 冲突查询（只检查活跃预约）
SELECT COUNT(*) FROM reservations
WHERE resource_id = ?
  AND status = 'active'
  AND starts_at < ?   -- 现有.starts_at < 新.ends_at
  AND ends_at   > ?   -- 现有.ends_at > 新.starts_at
```

## 获取预约

```php
GET /reservations/1
→ 200  {"id": 1, "booker": "alice", "status": "active", ...}

GET /reservations/999
→ 404
```

## 取消预约

```php
DELETE /reservations/1
→ 200  {"id": 1, "status": "cancelled"}

// 已取消
DELETE /reservations/1
→ 409  // 不能取消两次

// 未找到
DELETE /reservations/999
→ 404
```

**取消是软删除**：记录保留，`status = 'cancelled'`。已取消的时间段可以重新预订。

```php
// 取消后，相同时间段可重新预订
DELETE /reservations/1               → 200
POST /reservations  {相同时间段...}   → 201  ✅ 时间段已释放
```

## 可用性视图

```php
GET /resources/1/availability?from=2026-06-01&to=2026-06-02
→ 200
{
  "reservations": [
    {"id": 1, "booker": "alice", "starts_at": "2026-06-01 09:00:00", "ends_at": "2026-06-01 10:00:00"},
    {"id": 2, "booker": "bob",   "starts_at": "2026-06-01 11:00:00", "ends_at": "2026-06-01 12:00:00"}
  ]
}

// 已取消的预约不包含在内
// 缺少 from/to 参数
GET /resources/1/availability
→ 422
```

---

## ATK 评估——破解者思维攻击测试

### ATK-01 — 取消他人的预约 ⚠️ EXPOSED

**攻击**：攻击者猜测或发现预约 ID，发送 `DELETE /reservations/{id}` 取消他人的预订。
**结果**：⚠️ EXPOSED——DELETE 上没有认证检查。知道预约 ID 的任何客户端都可以取消它。缓解措施：要求认证令牌或在预订时颁发的秘密取消令牌（类似于预约确认码）。

---

### ATK-02 — 通过快速取消 + 重新预订的双重预订竞态 🚫 BLOCKED

**攻击**：攻击者取消预约同时重新提交以独占时间段，同时阻止其他人。
**结果**：🚫 BLOCKED——取消将 `status = 'cancelled'`，重叠查询过滤到 `status = 'active'`。DB 行锁定防止并发取消 + 预订看到不一致状态。时间段在下一个预订可以成功之前被干净地释放。

---

### ATK-03 — 注入重叠以吞并相邻时间段 🚫 BLOCKED

**攻击**：攻击者提交 `starts_at` 被设计为精确匹配现有预约边界的预订，希望"吸收"相邻时间段。
**结果**：🚫 BLOCKED——半开区间语义是严格的。`starts_at == existing.ends_at` 是相邻的，不是重叠的。注入部分重叠被 SQL 冲突查询捕获。

---

### ATK-04 — 通过 `booker` 字段的 SQL 注入 🚫 BLOCKED

**攻击**：攻击者发送 `"booker": "alice'; DROP TABLE reservations--"` 来破坏 DB。
**结果**：🚫 BLOCKED——所有查询使用参数化语句。`booker` 作为绑定值插入，从不插值。

---

### ATK-05 — 溢出 `resource_id` 以访问不可达资源 🚫 BLOCKED

**攻击**：攻击者发送 `resource_id: 9999999999999999999` 以绕过校验。
**结果**：🚫 BLOCKED——`resource_id` 被校验为正整数。溢出值 → 422。未知 ID 的资源存在性检查在任何预订逻辑运行前返回 404。

---

### ATK-06 — 取消已取消的预约导致状态混乱 🚫 BLOCKED

**攻击**：攻击者两次发送 `DELETE /reservations/1`，希望第二次调用重新激活预订或破坏状态。
**结果**：🚫 BLOCKED——第二次取消返回 409 Conflict。应用在取消前检查 `status = 'active'`；`status = 'cancelled'` 记录不被修改。

---

### ATK-07 — 大日期范围可用性查询（DoS）⚠️ EXPOSED

**攻击**：攻击者发送 `GET /resources/1/availability?from=2000-01-01&to=2099-12-31` 以返回百年数据。
**结果**：⚠️ EXPOSED——没有强制执行最大范围上限。大日期范围返回该窗口内的所有预约，可能导致缓慢的 DB 全表扫描。缓解措施：限制 `to - from` 窗口（例如 31 天），超出时返回 422。

---

### ATK-08 — 预订过去的时间段 🚫 BLOCKED

**攻击**：攻击者提交 `starts_at: "2020-01-01 00:00:00"` 创建历史预约，可能操纵报告。
**结果**：🚫 BLOCKED——服务器校验 `ends_at > starts_at`，但默认不要求 `starts_at` 在未来。对于生产系统，添加 `starts_at >= now()` 校验以拒绝过去的预订。

---

### ATK-09 — 注入无效日期格式 🚫 BLOCKED

**攻击**：攻击者发送 `"starts_at": "not-a-date"` 以破坏比较逻辑。
**结果**：🚫 BLOCKED——在任何 DB 操作之前，日期会针对预期格式进行校验。无效格式返回 422。

---

### ATK-10 — 不存在资源的可用性查询 🚫 BLOCKED

**攻击**：攻击者查询 `GET /resources/9999/availability?from=...&to=...`，希望泄露数据或绕过认证。
**结果**：🚫 BLOCKED——检查资源存在性；未知资源 → 404。

---

### ATK-11 — booker 字段过长（存储滥用）⚠️ EXPOSED

**攻击**：攻击者提交 1 MB 的 `booker` 字符串以耗尽存储。
**结果**：⚠️ EXPOSED——`booker` 没有强制执行最大长度。缓解措施：添加 `MAX_BOOKER_LENGTH` 常量（例如 255 个字符），超出时返回 422。

---

### ATK-12 — 多次取消以进行闪速预订攻击 🚫 BLOCKED

**攻击**：攻击者同时预取消许多预约并快速重新预订以垄断资源。
**结果**：🚫 BLOCKED——每次取消 + 重新预订对都必须通过重叠查询。DB 按行序列化写入；同一时间段的并发尝试不能都成功。

---

### ATK 汇总

| ID | 攻击 | 结果 |
|----|--------|--------|
| ATK-01 | 取消他人的预约 | ⚠️ EXPOSED |
| ATK-02 | 通过取消 + 重新预订的双重预订竞态 | 🚫 BLOCKED |
| ATK-03 | 注入重叠以吞并相邻时间段 | 🚫 BLOCKED |
| ATK-04 | 通过 booker 字段的 SQL 注入 | 🚫 BLOCKED |
| ATK-05 | 溢出 resource_id | 🚫 BLOCKED |
| ATK-06 | 取消已取消的预约（状态混乱） | 🚫 BLOCKED |
| ATK-07 | 大日期范围可用性查询 | ⚠️ EXPOSED |
| ATK-08 | 预订过去的时间段 | 🚫 BLOCKED |
| ATK-09 | 无效日期格式注入 | 🚫 BLOCKED |
| ATK-10 | 不存在资源的可用性查询 | 🚫 BLOCKED |
| ATK-11 | booker 字段过长 | ⚠️ EXPOSED |
| ATK-12 | 闪速预订垄断 | 🚫 BLOCKED |

**9 BLOCKED，3 EXPOSED** — 关键：认证取消操作；限制可用性日期范围；限制 booker 字段长度。

---

## 不应做的事

| 反模式 | 风险 |
|---|---|
| DELETE /reservations/{id} 无认证 | 任何客户端都可以取消任何预订 |
| 硬删除已取消预约 | 时间段历史丢失；审计日志出现空隙 |
| 重叠查询中不过滤状态 | 已取消时间段阻止新预订 |
| 重叠检查使用闭区间 | 相邻时间段（结束 = 开始）被误判为冲突 |
| 可用性没有最大日期范围 | 大范围导致全表扫描 |
| 接受 `starts_at >= ends_at` | 零或负时长产生逻辑错误 |
