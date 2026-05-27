# 优惠券/促销码管理

带有 admin RBAC、每用户使用追踪、有效期和上限控制的优惠券系统实现指南。

## 概述

- 只有 admin 角色可以创建、停用优惠券和查看使用历史
- 普通用户每张优惠券只能使用一次（`UNIQUE (coupon_id, user_id)`）
- `discount_pct`：1–100 的整数（必须校验）
- `max_uses = 0` 表示无上限
- `expires_at` 是 ISO 8601 字符串（NULL = 永久有效）
- user_id **仅**从 `X-User-Id` 头获取（不能从请求体注入）

## 端点

| 方法 | 路径 | 描述 | 权限 |
|------|------|------|------|
| `POST` | `/coupons` | 创建优惠券 | admin |
| `GET` | `/coupons/{code}` | 获取优惠券信息 | 任何人 |
| `POST` | `/coupons/{code}/use` | 使用优惠券（每用户一次） | 已认证 |
| `GET` | `/coupons/{code}/uses` | 使用历史列表 | admin |
| `DELETE` | `/coupons/{code}` | 停用优惠券 | admin |

## 数据库设计

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user',
    created_at TEXT NOT NULL,
    CHECK (role IN ('user', 'admin'))
);

CREATE TABLE coupons (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    discount_pct INTEGER NOT NULL CHECK (discount_pct >= 1 AND discount_pct <= 100),
    max_uses INTEGER NOT NULL DEFAULT 0,
    use_count INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    expires_at TEXT,
    created_by INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE coupon_uses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coupon_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    used_at TEXT NOT NULL,
    UNIQUE (coupon_id, user_id),
    FOREIGN KEY (coupon_id) REFERENCES coupons(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`UNIQUE (coupon_id, user_id)` 在数据库层面防止同一用户的重复使用。

## admin 检查模式

```php
private function requireUserId(ServerRequestInterface $request): ?int
{
    $val = $request->getHeaderLine('X-User-Id');
    return $val !== '' ? (int) $val : null;
}

private function isAdmin(ServerRequestInterface $request): bool
{
    return $request->getHeaderLine('X-User-Role') === 'admin';
}

// handleCreate / handleDeactivate / handleListUses 的开头
$actorId = $this->requireUserId($request);
if ($actorId === null) {
    return $this->responseFactory->create(['error' => 'authentication required'], 401);
}
if (!$this->isAdmin($request)) {
    return $this->responseFactory->create(['error' => 'admin role required'], 403);
}
```

## 优惠券使用检查顺序

```php
// 1. 认证检查
if ($actorId === null) { return 401; }

// 2. 确认优惠券存在
$coupon = $this->repository->findByCode($code);
if ($coupon === null) { return 404; }

// 3. is_active 检查
if (!(bool) $coupon['is_active']) { return 422 'not active'; }

// 4. 有效期检查
$now = date('c');
if ($coupon['expires_at'] !== null && $now > $coupon['expires_at']) { return 422 'expired'; }

// 5. max_uses 检查（0 = 无限制）
if ($maxUses > 0 && $coupon['use_count'] >= $maxUses) { return 422 'limit reached'; }

// 6. 用户重复检查（应用层确认 UNIQUE 约束）
$existing = $this->repository->findUse($coupon['id'], $actorId);
if ($existing !== null) { return 422 'already used'; }

// 7. 记录使用 + 递增 use_count
$this->repository->recordUse($coupon['id'], $actorId, $now);
return 201;
```

## 优惠券使用记录

```php
public function recordUse(int $couponId, int $userId, string $now): int
{
    $id = $this->executor->insert(
        'INSERT INTO coupon_uses (coupon_id, user_id, used_at) VALUES (?, ?, ?)',
        [$couponId, $userId, $now]
    );
    $this->executor->execute(
        'UPDATE coupons SET use_count = use_count + 1 WHERE id = ?',
        [$couponId]
    );
    return $id;
}
```

`use_count` 的递增与 INSERT 在同一处理流程中执行。在 MySQL 中，并发访问时 `use_count = use_count + 1` 会原子性地执行。

## discount_pct 校验

```php
$discountPct = isset($body['discount_pct']) && is_int($body['discount_pct']) ? $body['discount_pct'] : null;
if ($discountPct === null || $discountPct < 1 || $discountPct > 100) {
    return $this->responseFactory->create(['error' => 'discount_pct must be 1-100'], 422);
}
```

`CHECK (discount_pct >= 1 AND discount_pct <= 100)` 也在数据库层面保证，但应用层会先拒绝并返回适当的 422。

## 响应示例

### POST /coupons（创建）
```json
{
  "id": 1,
  "code": "SUMMER20",
  "discount_pct": 20,
  "max_uses": 100,
  "use_count": 0,
  "is_active": true,
  "expires_at": "2026-08-31T23:59:59+00:00",
  "created_by": 1,
  "created_at": "2026-05-21T..."
}
```

### POST /coupons/{code}/use（使用）
```json
{
  "id": 42,
  "coupon_id": 1,
  "code": "SUMMER20",
  "discount_pct": 20,
  "user_id": 7,
  "used_at": "2026-05-21T..."
}
```

## user_id 注入防护

user_id 必须从 `X-User-Id` 头获取。忽略请求体中的 `user_id` 字段。

```php
// 错误：$userId = (int) $body['user_id'];  // 攻击者可以操控
// 正确：
$actorId = $this->requireUserId($request);  // 仅从 X-User-Id 头获取
```
