# 操作指南：优惠券/折扣码兑换 API

本指南展示如何使用 NENE2 构建一个带使用次数限制和有效期的优惠券兑换系统。模式由 **couponlog** 字段测试（FT218）演示。

## 功能特性

- 创建带折扣金额、使用上限和有效期的优惠码（仅管理员）
- 可选的随机码自动生成（`bin2hex(random_bytes(6))`）
- 每个用户每张优惠券只能兑换一次（`UNIQUE(coupon_id, user_id)`）
- 使用次数限制执行（`max_uses`）
- 与当前 UTC 时间对比的有效期检查
- 仅管理员可查看兑换列表

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS coupons (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    code        TEXT    NOT NULL UNIQUE,
    discount    INTEGER NOT NULL,
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
```

## 端点

| 方法 | 路径 | 认证 | 描述 |
|------|------|------|------|
| `POST` | `/coupons` | Admin | 创建优惠券 |
| `GET` | `/coupons/{code}` | 公开 | 获取优惠券信息 |
| `POST` | `/coupons/{code}/redeem` | 用户 | 兑换优惠券 |
| `GET` | `/coupons/{code}/redemptions` | Admin | 列出兑换记录 |

## 优惠码校验

优惠码使用严格模式以防注入：

```php
/** 优惠码：大写字母和数字，4–32 个字符 */
private const string CODE_PATTERN = '/\A[A-Z0-9]{4,32}\z/';
```

路径参数在校验前规范化为大写：

```php
private function pathCode(ServerRequestInterface $req): ?string
{
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $code   = strtoupper(trim($params['code'] ?? ''));
    if (!preg_match(self::CODE_PATTERN, $code)) {
        return null; // → 404
    }
    return $code;
}
```

## 兑换逻辑

```php
/** @return 'ok'|'not_found'|'expired'|'exhausted'|'already_redeemed' */
public function redeem(string $code, int $userId): string
{
    $coupon = $this->findByCode($code);
    if ($coupon === null) return 'not_found';

    // 检查有效期
    if ($coupon['expires_at'] < $this->now()) return 'expired';

    // 检查使用次数上限
    if ((int) $coupon['used_count'] >= (int) $coupon['max_uses']) return 'exhausted';

    // 检查每用户限制
    $stmt = $this->pdo->prepare(
        'SELECT id FROM redemptions WHERE coupon_id = :cid AND user_id = :uid'
    );
    if ($stmt->fetch() !== false) return 'already_redeemed';

    // 记录兑换 + 递增计数器
    $this->pdo->prepare('INSERT INTO redemptions ...')->execute([...]);
    $this->pdo->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE id = :id')
        ->execute([':id' => $coupon['id']]);

    return 'ok';
}
```

路由处理器使用 `match` 表达式进行清晰的分支处理：

```php
return match ($result) {
    'not_found'        => $this->problem(404, 'not-found', 'Coupon not found.'),
    'expired'          => $this->problem(409, 'conflict', 'Coupon has expired.'),
    'exhausted'        => $this->problem(409, 'conflict', 'Coupon usage limit reached.'),
    'already_redeemed' => $this->problem(409, 'conflict', 'You have already redeemed this coupon.'),
    default            => $this->json(['message' => 'Coupon redeemed successfully.']),
};
```

## 自动生成优惠码

当请求体中未提供 `code` 时，会自动生成一个：

```php
$code = strtoupper(trim((string) ($body['code'] ?? '')));
if ($code === '') {
    $code = strtoupper(bin2hex(random_bytes(6))); // 12 位大写十六进制字符
}
```

## 安全模式

- **管理员默认关闭**：`if ($this->adminKey === '') return false;` 在 `hash_equals()` 之前
- **优惠码格式**：等同于 `ctype_digit()` 的优惠码方案——正则 `/\A[A-Z0-9]{4,32}\z/`
- **`is_int()`**：对 `discount` 和 `max_uses` 的严格类型检查——拒绝浮点数
- **ISO 8601 有效期**：正则校验 + 字典序比较（UTC 字符串）
- **原子递增**：`UPDATE SET used_count = used_count + 1` 防止竞争条件
- **UNIQUE 约束**：数据库层面的重复防止安全网
