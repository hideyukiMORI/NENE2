# 操作指南：订阅/方案管理 API（VULN-A~L）

本指南演示一个订阅管理 API，用户可以订阅方案，并包含重复订阅防护、取消功能和 IDOR 防护。

## 模式概述

- 种子方案在 schema 初始化时插入（`free`、`starter`、`pro`、`annual`）。
- 用户通过 `POST /subscriptions` 并携带 `plan_id` 进行订阅。
- 每个（用户、方案）组合最多只能有一个活跃订阅。
- 取消操作将状态改为 `'cancelled'`；已取消的订阅不能再次取消。

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS plans (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL UNIQUE,
    price_cents INTEGER NOT NULL,
    interval    TEXT    NOT NULL DEFAULT 'monthly'
);

CREATE TABLE IF NOT EXISTS subscriptions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    plan_id      INTEGER NOT NULL,
    status       TEXT    NOT NULL DEFAULT 'active',
    started_at   TEXT    NOT NULL,
    cancelled_at TEXT,
    FOREIGN KEY (plan_id) REFERENCES plans(id),
    UNIQUE (user_id, plan_id, status)
);
```

## VULN-A：SQL 注入

所有查询使用 PDO 预处理语句。方案名称和用户 ID 从不拼接进字符串。

## VULN-C：IDOR

非管理员用户只能访问自己的订阅。访问其他用户的订阅返回 404（而非 403）：

```php
if (!$isAdmin && (int) $sub['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Subscription not found.');
}
```

## VULN-D：管理员失闭合

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

## VULN-G：ReDoS

路径 ID 使用 `ctype_digit()` + 长度限制进行验证。非数字路径（`/subscriptions/abc`）立即返回 404。

## VULN-J：类型混淆

```php
$planId = $body['plan_id'] ?? null;
if (!is_int($planId) || $planId < 1) {
    return $this->problem(422, 'validation-failed', 'plan_id must be a positive integer.');
}
```

字符串 `"2"`、浮点数 `2.5` 和零都返回 422。

## 重复订阅防护

```php
$stmt = $this->pdo->prepare(
    "SELECT id FROM subscriptions WHERE user_id = :uid AND plan_id = :pid AND status = 'active'"
);
```

尝试订阅已活跃的方案返回 409。

## 取消幂等性

`cancel()` 方法在更新前检查状态。对已 `'cancelled'` 订阅的第二次取消尝试返回 `'already_cancelled'` → 409（而非 204）。

## 通过 JOIN 获取丰富响应

订阅详情通过 JOIN 包含方案信息：

```sql
SELECT s.*, p.name AS plan_name, p.price_cents, p.interval AS plan_interval
FROM subscriptions s JOIN plans p ON p.id = s.plan_id
WHERE s.id = :id
```

## 路由

```
GET    /plans                           列出可用方案（公开）
POST   /subscriptions                   订阅方案（需要 X-User-Id）
GET    /subscriptions/{id}              获取订阅（所有者或管理员）
POST   /subscriptions/{id}/cancel       取消订阅（所有者或管理员）
GET    /users/{userId}/subscriptions    列出用户的订阅（所有者或管理员）
```

## 参见

- FT213 源码：`../NENE2-FT/subscriptionlog/`
- 相关：`docs/howto/coupon-redemption.md`（FT204，同样是有状态的每用户限制）
- 相关：`docs/howto/wish-list-api.md`（FT207，VULN 模式）
