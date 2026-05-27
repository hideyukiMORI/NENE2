# 操作指南：商品目录 API（ATK-01~12）

本指南演示带管理员专属写操作、关键词搜索和软删除的商品目录 API，涵盖 ATK-01~12 攻击向量。

## 模式概述

- 目录读取操作公开开放；写操作（创建、删除）需要管理员权限（`X-Admin-Key`）。
- SKU 为大写字母数字加连字符格式（`/\A[A-Z0-9\-]{1,32}\z/`）。
- 软删除（`active = 0`）隐藏商品而不丢失历史数据。
- 关键词搜索使用带长度限制的 `LIKE`，防止关键词炸弹攻击。

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS products (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    sku         TEXT    NOT NULL UNIQUE,
    name        TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    price_cents INTEGER NOT NULL,
    active      INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT    NOT NULL
);
```

## ATK-01：搜索关键词中的 SQL 注入

```php
$kw   = '%' . $keyword . '%';
$stmt = $this->pdo->prepare(
    'SELECT * FROM products WHERE active = 1 AND (name LIKE :kw OR ...) LIMIT :lim OFFSET :off'
);
$stmt->bindValue(':kw', $kw, PDO::PARAM_STR);
```

`%` 通配符作为字面值传递给参数化查询——不会发生字符串插值。

## ATK-02：管理员故障关闭

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

管理员密钥为空时 → 始终返回 403。密钥错误时 → `hash_equals()` 防止时序泄露。

## ATK-03：商品 ID 整数溢出

```php
if (!ctype_digit($raw) || strlen($raw) > 18) {
    return null;  // → 404
}
```

超过 18 位的 ID 字符串在执行任何 `(int)` 转换或 DB 查询之前就被拒绝。

## ATK-04：负数 ID

`ctype_digit()` 对 `-1` 失败（含非数字字符）→ 返回 404。

## ATK-05：浮点价格

```php
if (!is_int($priceCents) || $priceCents < 0) {
    return $this->problem(422, ...);
}
```

`is_int(9.99)` 返回 `false`——浮点价格被拒绝。

## ATK-06：SKU 注入

SKU 正则 `/\A[A-Z0-9\-]{1,32}\z/` 拒绝 `; DROP TABLE`、引号、空格和小写字母。只接受精确匹配的格式。

## ATK-07：通配符搜索注入

搜索关键词中的 `%` 被视为 SQL LIKE 通配符——匹配所有内容。这是有意为之的（用户可以搜索全部）。LIKE 是参数化的，因此 `%; DROP TABLE products; --` 不会作为 SQL 执行：

```sql
WHERE name LIKE '%%; DROP TABLE products; --%'
```

结果只是更宽泛的 LIKE 匹配，而非注入攻击。

## ATK-08：重复删除

仓储层的 `delete()` 方法首先调用 `findById()`（仅限 active=1）。软删除的商品返回 null → 第二次删除时返回 404。

## ATK-09：SKU 过长

正则量词 `{1,32}` 在到达 DB 之前就拒绝超过 32 个字符的 SKU。

## ATK-10：错误的管理员密钥

无论有多少字符匹配，`hash_equals()` 比较始终耗时相同。

## 关键词长度限制

```php
if ($keyword !== null && strlen($keyword) > 100) {
    return $this->problem(422, 'validation-failed', 'q too long (max 100).');
}
```

防止向数据库发送 10 MB 的 LIKE 模式。

## 软删除

```php
$this->pdo->prepare('UPDATE products SET active = 0 WHERE id = :id')->execute([':id' => $id]);
```

所有读取操作均包含 `WHERE active = 1`。删除的商品在不物理删除的情况下变得不可见。

## 路由

```
POST   /products      创建商品（仅管理员）
GET    /products      列出/搜索商品（公开）
GET    /products/{id} 获取商品（公开）
DELETE /products/{id} 软删除商品（仅管理员）
```

## 参阅

- FT212 源码：`../NENE2-FT/productlog/`
- 相关：[`inventory-management.md`](inventory-management.md)（FT203，基于 SKU 的库存）
- 相关：[`session-token-management.md`](session-token-management.md)（FT208，也含 ATK）
