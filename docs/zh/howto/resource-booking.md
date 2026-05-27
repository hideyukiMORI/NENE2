# 操作指南：资源预订系统

## 概述

本指南介绍如何使用 NENE2 构建资源预订 API。功能包括容量强制执行、防重复预订、按用户 IDOR 隔离和管理员取消。

**参考实现**：`../NENE2-FT/bookinglog/`

---

## 数据库设计

```sql
CREATE TABLE IF NOT EXISTS resources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL UNIQUE,
    capacity   INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS bookings (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    slot_date   TEXT    NOT NULL,   -- 'YYYY-MM-DD'
    slot_hour   INTEGER NOT NULL,   -- 0-23
    created_at  TEXT    NOT NULL,
    cancelled   INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE,
    UNIQUE (resource_id, user_id, slot_date, slot_hour)
);
```

关键约束：
- `UNIQUE (resource_id, user_id, slot_date, slot_hour)` — 每用户每时间段只能预订一次。
- `cancelled` 软删除标志——保留历史记录同时允许重新预订。
- 容量在查询时检查（活跃预订数量 vs resource.capacity）。

---

## 路由表

| 方法 | 路径 | 认证 | 说明 |
|--------|------|------|-------------|
| `GET` | `/resources` | 无 | 列出所有资源 |
| `POST` | `/resources` | 管理员 | 创建资源 |
| `POST` | `/bookings` | 用户 | 预订时间段 |
| `GET` | `/bookings` | 用户 | 列出自己的预订 |
| `GET` | `/bookings/{id}` | 用户 | 获取单个预订 |
| `DELETE` | `/bookings/{id}` | 用户/管理员 | 取消预订 |

---

## 防重复预订

首先，检查用户是否已有此时间段（应用层）：

```php
$stmt = $this->pdo->prepare(
    'SELECT id FROM bookings WHERE resource_id = :rid AND user_id = :uid
     AND slot_date = :d AND slot_hour = :h AND cancelled = 0'
);
$stmt->execute([...]);
if ($stmt->fetch() !== false) {
    return 'double_booking';
}
```

然后检查容量：

```php
$count = $this->countSlotBookings($resourceId, $date, $hour);
if ($count >= (int) $resource['capacity']) {
    return 'capacity_full';
}
```

---

## IDOR 隔离

用户只能读取/取消自己的预订。返回 404（而非 403）以避免暴露存在性：

```php
if (!$this->isAdmin($req) && (int) $booking['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Booking not found.');
}
```

---

## 无 X-User-Id 的管理员取消

管理员可以取消任何预订而无需提供自己的用户 ID：

```php
$isAdmin = $this->isAdmin($req);
$uid     = $this->uid($req);
if ($uid === null && !$isAdmin) {
    return $this->problem(400, 'bad-request', 'X-User-Id required.');
}
$result = $this->repo->cancel($id, $uid ?? 0, $isAdmin);
```

---

## 校验规则

| 字段 | 规则 |
|-------|------|
| `resource_id` | `is_int()` + 正数 |
| `slot_date` | 正则 `/\A\d{4}-\d{2}-\d{2}\z/` |
| `slot_hour` | `is_int()` + 0–23 |
| `capacity` | `is_int()` + 正数 |
| `name` | 非空字符串 |

---

## HTTP 状态码

| 情况 | 状态 |
|-----------|--------|
| 资源已创建 | 201 |
| 预订已确认 | 201 |
| 预订找到/列表 | 200 |
| 无 X-User-Id | 400 |
| 字段类型无效 | 422 |
| 日期格式无效 | 422 |
| slot_hour 超出 0–23 | 422 |
| 资源未找到 | 404 |
| 预订未找到 | 404 |
| 无管理员密钥 | 403 |
| 取消自己的预订 | 200 |
| 取消他人的预订 | 403 |
| 重复预订 | 409 |
| 容量已满 | 409 |

---

## 涵盖的 VULN 模式

| VULN | 模式 | 防御 |
|------|---------|---------|
| A | IDOR：用户查看他人预订 | `WHERE user_id = :uid` + 404 |
| B | 负数 resource_id | `is_int() + > 0` 检查 |
| C | 零 slot_hour（午夜） | 0-23 范围允许 0 |
| D | slot_date 中的 SQL 注入 | 正则校验 + 参数化查询 |
| E | 字符串 resource_id 类型混淆 | `is_int()` 严格检查 |
| F | 重复预订 | INSERT 前检查存在性 |
| G | 容量溢出 | COUNT vs capacity 检查 |
| H | 无 X-User-Id | 带消息的 400 |
| I | 取消他人预订 | `user_id` 所有权检查 → 403 |
| J | 列表泄露他人数据 | `WHERE user_id = :uid` |
| K | 管理员取消任何预订 | `isAdmin` 绕过所有权 |
| L | slot_hour = 24（超出范围） | `$hour > 23` → 422 |
