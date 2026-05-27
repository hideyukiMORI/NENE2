# 使用 NENE2 构建访问令牌管理

本指南介绍如何构建个人访问令牌（PAT）系统——用户可以签发、列出和撤销自己的 API 令牌，每个令牌具有一个权限范围（`read`/`write`/`admin`）。令牌不以明文存储，只保存其 SHA-256 哈希值。

**Field Trial**：FT136  
**NENE2 版本**：^1.5  
**涵盖主题**：令牌哈希、权限范围枚举、所有权验证、撤销幂等性、验证端点

---

## 我们要构建的内容

- `POST /users/{id}/tokens` — 签发令牌（仅限所有者，原始令牌只返回一次）
- `GET /users/{id}/tokens` — 列出令牌（仅限所有者，响应中不包含原始令牌）
- `DELETE /users/{id}/tokens/{tokenId}` — 撤销令牌（仅限所有者，已撤销时返回 409）
- `POST /tokens/verify` — 验证原始令牌（返回有效/无效及权限范围）

---

## 数据库结构

```sql
CREATE TABLE tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token_hash TEXT    NOT NULL UNIQUE,
    scope      TEXT    NOT NULL DEFAULT 'read',
    label      TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    revoked_at TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id),
    CHECK (scope IN ('read', 'write', 'admin'))
);
```

- `token_hash` — 原始令牌的 SHA-256 哈希值；不存储原始令牌
- `revoked_at` — 可为空的时间戳；`NULL` 表示有效，非空表示已撤销
- `CHECK (scope IN (...))` — 数据库层面的权限范围约束，作为纵深防御

---

## 令牌权限范围枚举

```php
enum TokenScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';
}
```

`TokenScope::tryFrom($value)` 对未知权限范围返回 `null`——在存储前使用此方法验证输入。

---

## 签发令牌

```php
public function issueToken(int $userId, TokenScope $scope, string $label, string $now): string
{
    $raw  = bin2hex(random_bytes(32)); // 64 字符十六进制字符串
    $hash = hash('sha256', $raw);

    $this->executor->execute(
        'INSERT INTO tokens (user_id, token_hash, scope, label, created_at) VALUES (?, ?, ?, ?, ?)',
        [$userId, $hash, $scope->value, $label, $now],
    );

    return $raw; // 只返回一次，不存储
}
```

原始令牌只返回给调用方一次。此后数据库中只保存哈希值——无法恢复原始令牌。

---

## 验证令牌

```php
public function verifyToken(string $rawToken): ?array
{
    $hash = hash('sha256', $rawToken);
    $row  = $this->executor->fetchOne(
        'SELECT id, user_id, scope, revoked_at FROM tokens WHERE token_hash = ?',
        [$hash],
    );

    if ($row === null) {
        return null;
    }

    $arr = (array) $row;

    return [
        'valid'   => !isset($arr['revoked_at']),
        'user_id' => isset($arr['user_id']) ? (int) $arr['user_id'] : 0,
        'scope'   => isset($arr['scope']) && is_string($arr['scope']) ? $arr['scope'] : 'read',
    ];
}
```

**为什么使用 `!isset($arr['revoked_at'])` 而不是 `=== null`？** 当 `isset()` 返回 true 后，PHPStan 会从类型中排除 `null`——与 `null` 比较会被报告为 `identical.alwaysFalse`。单独使用 `isset()` 来检查 null。

验证端点对未知或已撤销的令牌始终返回 200 并附带 `{ "valid": false }`——绝不返回 404。这可以防止令牌枚举攻击。

---

## 所有权验证

每个变更端点都会验证经过认证的操作者与资源所有者是否匹配：

```php
$actorId = $this->resolveActorId($request); // 来自 X-User-Id 请求头

if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

对于撤销操作，还需对令牌本身进行第二次所有权验证：

```php
$token = $this->repo->findTokenById($tokenId);

if ($token['user_id'] !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

这可以防止 ATK-04——Bob 使用自己的用户路径但撤销 Alice 的令牌 ID。

---

## 撤销——已撤销时返回 409

```php
public function revokeToken(int $tokenId, string $now): bool
{
    $count = $this->executor->execute(
        'UPDATE tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
        [$now, $tokenId],
    );

    return $count > 0;
}
```

`WHERE revoked_at IS NULL` 条件确保如果令牌已被撤销，UPDATE 不会产生任何效果。处理器将 `$count === 0` 映射为 409 Conflict。

---

## 列出令牌——不包含原始令牌

列表响应包含 `id`、`scope`、`label`、`created_at`、`revoked`（布尔值）。原始令牌在初始签发调用后不再返回。

---

## PHPStan level 8 陷阱：isset 与 null 比较

```php
// 错误——PHPStan 报告 `notIdentical.alwaysTrue`
'revoked' => isset($arr['revoked_at']) && $arr['revoked_at'] !== null,

// 正确——isset() 已经隐含非空
'revoked' => isset($arr['revoked_at']),

// 错误——PHPStan 报告 `identical.alwaysFalse`
'valid' => !isset($arr['revoked_at']) || $arr['revoked_at'] === null,

// 正确
'valid' => !isset($arr['revoked_at']),
```

---

## 破解攻击测试结果（FT136）

| 攻击 | 预期结果 | 实际结果 |
|--------|----------|--------|
| ATK-01：为其他用户签发令牌（IDOR） | 403 | 通过 |
| ATK-02：列出其他用户的令牌（IDOR） | 403 | 通过 |
| ATK-03：通过对方路径撤销其令牌 | 403 | 通过 |
| ATK-04：通过自己路径撤销对方令牌 | 403 | 通过 |
| ATK-05：无效权限范围（`superuser`） | 422 | 通过 |
| ATK-06：使用已撤销令牌进行验证 | valid=false | 通过 |
| ATK-07：暴力破解随机令牌 | valid=false | 通过 |
| ATK-08：验证请求体中的 SQL 注入 | valid=false | 通过 |
| ATK-09：非数字 X-User-Id（`admin`） | 非 201 | 通过 |
| ATK-10：负数用户 ID | 404 | 通过 |
| ATK-11：10KB 的权限范围字符串 | 422 | 通过 |
| ATK-12：空或仅含空白字符的令牌 | 422 | 通过 |

全部 12 项攻击测试通过。

---

## 常见陷阱

| 陷阱 | 修复方法 |
|---------|-----|
| `isset($x) && $x !== null` | 单独使用 `isset($x)`——PHPStan level 8 会拒绝冗余检查 |
| 在数据库中存储原始令牌 | 只存储 `hash('sha256', $raw)` |
| 在列表响应中返回原始令牌 | 只在签发响应中返回原始令牌 |
| 撤销时不检查令牌所有权 | 找到令牌后检查 `token['user_id'] === userId` |
| 验证时对无效令牌返回 404 | 始终返回 200 并附带 `valid: false`——防止枚举攻击 |
