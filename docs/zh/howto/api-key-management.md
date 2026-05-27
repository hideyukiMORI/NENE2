# API 密钥管理

> **FT 参考**：FT266（`NENE2-FT/apikeylog`）——API 密钥生命周期：生成、SHA-256 哈希存储、基于前缀的查找、权限范围执行、轮换

本指南介绍如何在 NENE2 应用程序中实现 API 密钥管理：密钥生成、安全存储、基于权限范围的授权、撤销和轮换。

## 核心设计原则

1. **绝不存储原始密钥**——数据库中只存储 SHA-256 哈希值。
2. **原始密钥只返回一次**——仅在创建时，之后不再返回。
3. **基于前缀查找，基于哈希验证**——前缀缩小数据库查询范围；`hash_equals()` 执行实际认证。
4. **权限范围层级**——admin ⊃ write ⊃ read；按端点逐一检查。
5. **安全轮换**——在撤销旧密钥之前创建新密钥，防止锁定。

## 密钥格式

```
nk_Vf3aB2cX9dJkQmHpNrTsUvWxYzAeBfCg
^   ^----- 43 字符的 base64url(32 随机字节) -----^
|
类型前缀（在日志中可识别）
```

`random_bytes(32)` 提供 256 位熵。无论哈希速度如何，暴力破解在计算上都是不可行的，因此 SHA-256（快速，单用途）是合适的——与密码不同，API 密钥不受字典攻击。

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS api_keys (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id    INTEGER NOT NULL,
    prefix      TEXT    NOT NULL,     -- 原始密钥的前 16 个字符（查找索引）
    key_hash    TEXT    NOT NULL UNIQUE,
    scope       TEXT    NOT NULL DEFAULT 'read',
    description TEXT    NOT NULL DEFAULT '',
    expires_at  TEXT,
    revoked_at  TEXT,
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);
```

`prefix` 列存储**原始密钥的前 16 个字符**（不是类型前缀 `nk`）。这提供约 78 位的区分度，使每个前缀实际上唯一，并支持 O(1) 索引查找。

**重要提示**：不要使用类型前缀（`nk`）作为数据库查找前缀。所有密钥共享相同的类型前缀，因此 `WHERE prefix = 'nk'` 会扫描整张表——O(n) 查找，且时序信道与密钥数量成正比。

## 密钥生成

```php
final class ApiKeyGenerator
{
    private const string PREFIX = 'nk';
    private const int    BYTES  = 32;

    public function generate(): string
    {
        $raw = random_bytes(self::BYTES);
        return self::PREFIX . '_' . rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public function hash(string $rawKey): string
    {
        return hash('sha256', $rawKey);
    }

    public function extractPrefix(string $rawKey): string
    {
        // 完整密钥的前 16 个字符——每个密钥唯一，可安全建索引
        return substr($rawKey, 0, 16);
    }

    public function verify(string $rawKey, string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hash($rawKey));
    }
}
```

`hash_equals()` 是必须的。使用 `===` 或 `==` 进行哈希比较会泄露时序信息：用 `===` 比较 64 字符十六进制字符串时，第一次不匹配就退出，揭示了前导字符有多少是匹配的。

## 认证流程

```php
public function authenticate(string $rawKey, string $now): ?ApiKey
{
    $prefix = $this->generator->extractPrefix($rawKey);

    $rows = $this->executor->fetchAll(
        'SELECT * FROM api_keys WHERE prefix = ?',
        [$prefix],
    );

    foreach ($rows as $row) {
        $key = $this->hydrate($row);
        if ($this->generator->verify($rawKey, $key->keyHash) && $key->isActive($now)) {
            return $key;
        }
    }

    return null;
}
```

两步方法：
1. 按前缀索引查找（快速数据库查询）
2. 针对存储哈希的 `hash_equals()` 验证

对所有失败情况（未找到、哈希错误、已过期、已撤销）返回相同的 `null` 和 `401`——调用者不得区分它们。

## 权限范围层级

```php
enum ApiKeyScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';

    public function allows(self $required): bool
    {
        return match ($required) {
            self::Read  => true,
            self::Write => $this === self::Write || $this === self::Admin,
            self::Admin => $this === self::Admin,
        };
    }
}
```

在端点层面强制执行权限范围：

```php
private function requireScope(ServerRequestInterface $request, ApiKeyScope $required): ApiKey|ResponseInterface
{
    $rawKey = $request->getHeaderLine('X-Api-Key');
    if ($rawKey === '') {
        return $this->problems->create($request, 'unauthorized', 'Missing X-Api-Key header.', 401, '');
    }

    $key = $this->repo->authenticate($rawKey, $now);
    if ($key === null) {
        return $this->problems->create($request, 'unauthorized', 'Invalid or expired API key.', 401, '');
    }

    if (!$key->scope->allows($required)) {
        return $this->problems->create($request, 'forbidden', 'Insufficient scope.', 403, '');
    }

    return $key;
}
```

未认证时返回 `401`，已认证但权限范围不足时返回 `403`——绝不泄露密钥是否存在。

## 响应过滤

`ApiKey` 上的 `toArray()` 方法**不得**包含 `key_hash`。原始密钥只能通过 `ApiKeyCreateResult::toArray()` 在创建后立即获得。

```php
// ApiKey::toArray() — 可从任何端点安全返回
public function toArray(): array
{
    return [
        'id', 'owner_id', 'prefix', 'scope', 'description',
        'expires_at', 'revoked_at', 'created_at', 'updated_at',
        // key_hash 被故意省略
    ];
}

// ApiKeyCreateResult::toArray() — 仅用于创建端点
public function toArray(): array
{
    return array_merge($this->key->toArray(), ['key' => $this->rawKey]);
}
```

## 密钥轮换——安全的操作顺序

**始终先创建新密钥，再撤销旧密钥。**

```php
public function rotate(int $oldId, int $ownerId, string $now): ?ApiKeyCreateResult
{
    $old = $this->findById($oldId);
    if ($old === null || $old->ownerId !== $ownerId || $old->isRevoked()) {
        return null;
    }

    // 先创建——如果失败，旧密钥仍然有效（不会锁定）
    $result = $this->create($ownerId, $old->scope, $old->description, $now, $old->expiresAt);

    // 再撤销——如果失败，两个密钥暂时同时存在（可通过列表恢复）
    $this->executor->execute(
        'UPDATE api_keys SET revoked_at = ?, updated_at = ? WHERE id = ?',
        [$now, $now, $oldId],
    );

    return $result;
}
```

先撤销再创建是危险的：如果在撤销后创建失败，所有者将被永久锁定。反向操作（先创建再撤销）意味着最坏情况是暂时有两个有效密钥——可观察且可恢复。

## 过期

将 `expires_at` 存储为 ISO 日期时间字符串。在 `isActive()` 中进行检查：

```php
public function isActive(string $now): bool
{
    return !$this->isRevoked() && !$this->isExpired($now);
}

public function isExpired(string $now): bool
{
    return $this->expiresAt !== null && $this->expiresAt < $now;
}
```

认证流程将 `$now` 作为参数传递，使逻辑可以用固定时间戳进行测试。

## 反模式

| 反模式 | 风险 |
|---|---|
| 在数据库中存储原始密钥 | 数据库泄露时完全暴露 |
| 使用 `===` 进行哈希比较 | 时序攻击泄露哈希前缀长度 |
| 使用类型前缀（`nk`）作为数据库查找索引 | O(n) 全表扫描；时序信道 |
| 在列表/详情响应中返回 `key_hash` | 对哈希值进行离线字典攻击 |
| 在轮换中先撤销旧密钥再创建新密钥 | 数据库错误时所有者被锁定 |
| 对"密钥不存在"和"密钥已过期"返回不同错误 | 密钥存在性的预言机 |
| 记录 `X-Api-Key` 请求头 | 密钥泄露进日志存储 |
