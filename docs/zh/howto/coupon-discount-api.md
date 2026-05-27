# 操作指南：优惠券折扣码 API

> **FT 参考**：FT302（`NENE2-FT/couponlog`）——优惠券折扣码 API：使用 `X-Admin-Key`（hash_equals）仅限管理员创建，CODE_PATTERN `[A-Z0-9]{4,32}` 自动规范化为大写，`UNIQUE(coupon_id, user_id)` 防止重复兑换，已过期/已耗尽/重复 → 409，26 个测试 / 50 个断言全部通过。

本指南展示如何构建优惠券系统，管理员创建折扣码，用户在使用次数限制和有效期范围内兑换。

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS coupons (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    code        TEXT    NOT NULL UNIQUE,
    discount    INTEGER NOT NULL,          -- 以分为单位，例如 500 = $5.00
    max_uses    INTEGER NOT NULL DEFAULT 1,
    used_count  INTEGER NOT NULL DEFAULT 0,
    expires_at  TEXT    NOT NULL,
    created_at  TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS redemptions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    coupon_id   INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    redeemed_at TEXT    NOT NULL,
    UNIQUE (coupon_id, user_id),
    FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_coupons_code ON coupons (code);
```

`UNIQUE(coupon_id, user_id)` 防止同一用户对同一优惠券兑换两次。`code` 上的索引加速按优惠码字符串的查找。

## 端点

| 方法 | 路径 | 认证 | 描述 |
|------|------|------|------|
| `POST` | `/coupons` | `X-Admin-Key` | 创建优惠券（仅管理员） |
| `GET` | `/coupons/{code}` | — | 获取优惠券详情 |
| `POST` | `/coupons/{code}/redeem` | `X-User-Id` | 兑换优惠券 |
| `GET` | `/coupons/{code}/redemptions` | `X-Admin-Key` | 列出兑换记录（仅管理员） |

## 管理员认证——hash_equals

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

`hash_equals()` 防止密钥比较上的时序旁路攻击。如果 `adminKey` 是空字符串（配置错误），`isAdmin()` 返回 false——默认关闭。

## 优惠码格式——CODE_PATTERN

```php
private const string CODE_PATTERN = '/\A[A-Z0-9]{4,32}\z/';
```

- 仅限大写字母和数字
- 4–32 个字符
- `\A` / `\z` 锚点（全串匹配，而非子串匹配）

输入的优惠码在校验前规范化为大写：

```php
$code = strtoupper(trim((string) ($body['code'] ?? '')));
if ($code === '') {
    // 未提供时自动生成
    $code = strtoupper(bin2hex(random_bytes(6)));
}
if (!preg_match(self::CODE_PATTERN, $code)) {
    return $this->problem(422, 'validation-failed', 'code must be 4–32 uppercase alphanumeric chars.');
}
```

发送 `"summer50"` 的用户与发送 `"SUMMER50"` 的用户获得相同的优惠券——系统自动规范化为大写。`pathCode()` 也会将路径参数规范化为大写，所以 `GET /coupons/summer50` 和 `GET /coupons/SUMMER50` 解析到同一优惠券。

## 优惠券创建校验

```php
$discount = $body['discount'] ?? null;
if (!is_int($discount) || $discount < 1 || $discount > 10000) {
    return $this->problem(422, 'validation-failed', 'discount must be integer 1–10000 (cents).');
}

$maxUses = $body['max_uses'] ?? 1;
if (!is_int($maxUses) || $maxUses < 1 || $maxUses > 100000) {
    return $this->problem(422, 'validation-failed', 'max_uses must be integer 1–100000.');
}

if (!preg_match(self::ISO_DATE_PATTERN, $expiresAt)) {
    return $this->problem(422, 'validation-failed', 'expires_at must be ISO 8601 datetime.');
}
```

- `discount`：严格的 `is_int()`——`9.99` 这样的浮点数会被拒绝
- `max_uses`：未提供时默认为 `1`
- `expires_at`：必须匹配 `\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}` ISO 8601 前缀

## 兑换——四种失败模式

```php
$result = $this->repo->redeem($code, $uid);

return match ($result) {
    'not_found'        => $this->problem(404, 'not-found', 'Coupon not found.'),
    'expired'          => $this->problem(409, 'conflict', 'Coupon has expired.'),
    'exhausted'        => $this->problem(409, 'conflict', 'Coupon usage limit reached.'),
    'already_redeemed' => $this->problem(409, 'conflict', 'You have already redeemed this coupon.'),
    default            => $this->json(['message' => 'Coupon redeemed successfully.']),
};
```

所有业务规则失败都返回 **409 Conflict**（而非 422）。`match` 表达式是穷举的——默认分支仅在仓库返回成功的 `'redeemed'` 字符串时触发。

## 用户 ID 校验

```php
private function uid(ServerRequestInterface $req): ?int
{
    $raw = $req->getHeaderLine('X-User-Id');
    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
        return null;
    }
    $id = (int) $raw;
    return $id > 0 ? $id : null;
}
```

- `ctype_digit()`——只接受纯数字字符串（不含 `-`、`+`、空格）
- `strlen > 18`——防止 64 位 PHP 上的整数溢出（`PHP_INT_MAX` 是 19 位）
- `$id > 0`——零 ID 无效

返回 `null` → 如果头缺失或格式错误则返回 400 Bad Request。

## UNIQUE(coupon_id, user_id)——幂等兑换

数据库约束在存储层面防止重复兑换。应用在插入前也通过仓库检查，返回 `'already_redeemed'` 而非依赖数据库异常。

多个不同用户可以兑换同一优惠券（直至 `max_uses`）。只有同一用户尝试对同一优惠券兑换两次时才被阻止。

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 使用普通 `==` 进行管理员密钥比较 | 时序攻击揭示密钥长度/部分匹配 |
| 空 `adminKey` 允许管理员访问 | 配置错误的管理员密钥变成开放访问——默认关闭 |
| 区分大小写的优惠码查找 | `"summer50"` 和 `"SUMMER50"` 被视为不同优惠券 |
| `discount` 没有 `is_int()` | 接受浮点数 `9.99`；小数分破坏账本 |
| 过期/耗尽时返回 422 | 这些是业务状态冲突，而非校验错误——使用 409 |
| 无 UNIQUE(coupon_id, user_id) | 竞争条件允许同一用户并发兑换两次 |
| 无 `max_uses` 上限 | 攻击者创建 `max_uses: 999999999` 的优惠券，实现无限折扣 |
| 跳过用户 ID 的 `strlen > N` 检查 | 非常大的整数字符串在 `(int)` 转换时静默溢出 |
| `code` 列无索引 | 每次优惠券查找都是全表扫描 |
| 向非管理员返回兑换列表 | 泄露哪些用户 ID 已兑换——隐私泄露 |
