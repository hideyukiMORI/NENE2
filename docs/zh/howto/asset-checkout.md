# 操作指南：资产借出/归还管理

演示带只追加审计日志的独占资产持有跟踪。
Field Trial：FT194（`../NENE2-FT/assetlog/`）。

---

## 模式摘要

| 关注点 | 实现方式 |
|---|---|
| 独占持有 | `holder_id INTEGER`——NULL = 可用，非空 = 已被持有 |
| 借出冲突 | 如果更新前 `holder_id IS NOT NULL`，返回 409 |
| 非持有者归还 | 如果 `holder_id != userId`，返回 403 |
| 审计日志 | 每次状态变更时追加 `asset_history` 行 |
| IDOR 防护 | 公共 API 隐藏 `holder_id`；查看需要管理员密钥 |
| 管理员密钥 | `hash_equals()` 常量时间比较，空密钥时失败关闭 |
| 用户身份 | `X-User-Id` 请求头；`ctype_digit()` + 长度限制，不使用正则 |

---

## 路由

| 方法 | 路径 | 认证 | 描述 |
|---|---|---|---|
| `POST` | `/assets` | `X-Admin-Key` | 创建资产 |
| `GET` | `/assets` | — | 列出所有资产 |
| `GET` | `/assets/{id}` | — | 获取单个资产 |
| `POST` | `/assets/{id}/checkout` | `X-User-Id` | 借出资产 |
| `POST` | `/assets/{id}/checkin` | `X-User-Id` | 归还资产 |
| `GET` | `/assets/{id}/history` | — | 审计历史 |

---

## 数据库结构

```sql
CREATE TABLE assets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    holder_id  INTEGER,           -- NULL = 可用
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE asset_history (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    asset_id INTEGER NOT NULL,
    user_id  INTEGER NOT NULL,
    action   TEXT    NOT NULL,   -- 'checkout' | 'checkin'
    acted_at TEXT    NOT NULL,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
);
```

---

## 独占借出模式

```php
public function checkout(int $assetId, int $userId): string
{
    $asset = $this->findById($assetId);
    if ($asset === null) return 'not_found';
    if (!$asset->isAvailable()) return 'unavailable';   // 409

    $now = $this->now();
    $this->pdo->prepare(
        'UPDATE assets SET holder_id = :uid, updated_at = :now WHERE id = :id AND holder_id IS NULL'
    )->execute([...]);

    $this->appendHistory($assetId, $userId, 'checkout', $now);
    return 'success';
}
```

`WHERE holder_id IS NULL` 条件即使在并发请求下也能防止双重借出（SQLite 序列化写入；MySQL/PgSQL 需要事务或 `SELECT FOR UPDATE`）。

---

## IDOR 防护

```php
// 公共响应——无 holder_id
public function toPublicArray(): array
{
    return ['id' => $this->id, 'name' => $this->name, 'available' => $this->isAvailable(), ...];
}

// 管理员响应——包含 holder_id
public function toAdminArray(): array
{
    return [..., 'holder_id' => $this->holderId];
}
```

处理器检查 `isAdmin()` 并选择正确的投影：

```php
fn (Asset $a) => $isAdmin ? $a->toAdminArray() : $a->toPublicArray()
```

---

## 管理员密钥（失败关闭）

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    if ($this->adminKey === '') return false;   // 未配置密钥 → 拒绝
    $provided = $request->getHeaderLine('X-Admin-Key');
    return $provided !== '' && hash_equals($this->adminKey, $provided);
}
```

---

## 用户 ID 验证

```php
private function resolveUserId(ServerRequestInterface $request): ?int
{
    $raw = $request->getHeaderLine('X-User-Id');
    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) return null;
    $id = (int) $raw;
    return $id > 0 ? $id : null;
}
```

`ctype_digit()` 是 O(n) 且抗 ReDoS 的。长度限制防止整数溢出。

---

## 错误映射

| 仓库返回值 | HTTP 状态码 |
|---|---|
| `success` | 200 / 201 |
| `not_found` | 404 |
| `unavailable` | 409 Conflict |
| `not_holder` | 403 Forbidden |
| `already_available` | 409 Conflict |

---

## 测试说明

- `AppFactory::create(?PDO, ?string)` 接受内存 SQLite 用于单元测试。
- 测试请求必须调用 `withParsedBody($body)`——Nyholm PSR-7 不会自动解析 JSON。
- 公共列表/获取断言验证 `holder_id` 键不存在（`assertArrayNotHasKey`）。
- 生命周期测试：借出 → 冲突 → 归还 → 由另一个用户重新借出。
