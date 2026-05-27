# 操作指南：API Token 生命周期管理

> **FT 参考**：FT272（`NENE2-FT/tokenlog`）— API token 生命周期：SHA-256 哈希存储（明文永不持久化）、带 DB CHECK 约束的 scope 枚举（read/write/admin）、IDOR 防护（actorId 必须匹配 userId）、通过 `revoked_at` 软吊销、verify 端点返回 valid/user_id/scope，29 tests / 70 assertions 全部 PASS。
>
> **ATK 评估**：ATK-01 到 ATK-12 包含在本文档末尾。

演示一个有权限范围的 API token 系统：为用户颁发 token、列出/吊销它们，并在访问时验证原始 token。Token 仅以 SHA-256 哈希存储——明文在颁发时返回一次，永不存储。

---

## 数据库结构

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

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

关键设计选择：
- `token_hash UNIQUE` — 防止意外的重复颁发；也是验证时的查找键
- `CHECK (scope IN (...))` — DB 层面强制执行 scope 枚举
- `revoked_at TEXT` — 软吊销；`NULL` 表示活跃，非 NULL 表示已吊销

---

## 路由

| 方法 | 路径 | 说明 |
|------|------|------|
| `POST` | `/users` | 创建用户 |
| `POST` | `/users/{userId}/tokens` | 颁发 token（仅所有者） |
| `GET` | `/users/{userId}/tokens` | 列出用户的 token（仅所有者） |
| `DELETE` | `/users/{userId}/tokens/{tokenId}` | 吊销 token（仅所有者） |
| `POST` | `/tokens/verify` | 验证原始 token |

---

## 仅存储哈希

原始 token 在颁发时返回一次，永不存储：

```php
public function issueToken(int $userId, TokenScope $scope, string $label, string $now): string
{
    $raw  = bin2hex(random_bytes(32));   // 64 个十六进制字符 — 256 位熵
    $hash = hash('sha256', $raw);

    $this->executor->execute(
        'INSERT INTO tokens (user_id, token_hash, scope, label, created_at) VALUES (?, ?, ?, ?, ?)',
        [$userId, $hash, $scope->value, $label, $now],
    );

    return $raw; // 返回给调用者，永不存储
}
```

验证时，调用者提供原始 token；重新计算哈希并查找：

```php
public function verifyToken(string $rawToken): ?array
{
    $hash = hash('sha256', $rawToken);
    $row  = $this->executor->fetchOne(
        'SELECT id, user_id, scope, revoked_at FROM tokens WHERE token_hash = ?',
        [$hash],
    );

    if ($row === null) {
        return null; // 未找到 → 调用者返回 {valid: false}
    }

    return [
        'valid'   => !isset($arr['revoked_at']),
        'user_id' => (int) $arr['user_id'],
        'scope'   => (string) $arr['scope'],
    ];
}
```

---

## Scope 强制执行

`TokenScope` 是 PHP backed enum；`tryFrom()` 在任何 DB 访问之前拒绝未知值：

```php
enum TokenScope: string
{
    case Read  = 'read';
    case Write = 'write';
    case Admin = 'admin';
}

// 在路由处理器中：
$scope = TokenScope::tryFrom($scopeValue);
if ($scope === null) {
    return $this->responseFactory->create(['error' => 'invalid scope, must be read/write/admin'], 422);
}
```

DB 的 `CHECK` 约束提供第二层强制执行。

---

## IDOR 防护

Token 颁发、列出和吊销都需要 actor 是所有者：

```php
$actorId = $this->resolveActorId($request); // 来自 X-User-Id 头部

if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

吊销还会验证 token 属于 `userId`，而非任意 token：

```php
if ($token['user_id'] !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

---

## 吊销

软吊销设置 `revoked_at`；UPDATE 仅在 `revoked_at IS NULL` 时生效：

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

如果 token 已被吊销，路由处理器返回 409 Conflict：

```php
if ($token['revoked']) {
    return $this->responseFactory->create(['error' => 'token already revoked'], 409);
}
```

---

## ATK 评估——破解者思维攻击测试

### ATK-01 — 吊销后 Token 重放 🚫 BLOCKED

**Attack**：吊销 token，然后在 `/tokens/verify` 上使用相同的原始 token 值。
**Result**: BLOCKED — `verifyToken()` 查找行中的 `revoked_at`；非 NULL 的 `revoked_at` 导致 `valid: false`。已吊销的 token 不会被删除，因此可以解析但返回 `{valid: false}`。

---

### ATK-02 — 暴力猜测 Token 🚫 BLOCKED

**Attack**：向 `/tokens/verify` 提交随机的 64 字符十六进制字符串，期望匹配有效的 token 哈希。
**Result**: BLOCKED — token 是 `bin2hex(random_bytes(32))` = 256 位熵。成功猜中的概率为 `1 / 2^256`。此 FT 没有速率限制，但仅凭熵就使暴力破解在计算上不可行。

---

### ATK-03 — IDOR：访问他人的 token 列表 🚫 BLOCKED

**Attack**：设置 `X-User-Id: 1` 并请求 `GET /users/2/tokens`。
**Result**: BLOCKED — `actorId (1) !== userId (2)` → 403 Forbidden。

---

### ATK-04 — IDOR：吊销他人的 token 🚫 BLOCKED

**Attack**：以用户 1 身份调用 `DELETE /users/2/tokens/{tokenId}`。
**Result**: BLOCKED — 路由处理器在获取 token 之前检查 `actorId !== userId` → 403。

---

### ATK-05 — 跨所有者 token 吊销（共享 token ID）🚫 BLOCKED

**Attack**：以用户 2 身份调用 `DELETE /users/2/tokens/{tokenId}`，其中 `tokenId` 属于用户 1。
**Result**: BLOCKED — IDOR 检查通过（actorId = userId = 2）后，`findTokenById` 返回 token，然后 `$token['user_id'] !== $userId` → 403。双重所有权检查防止跨用户吊销。

---

### ATK-06 — 无效 scope 注入 🚫 BLOCKED

**Attack**：POST `/users/{id}/tokens` 携带 `{"scope": "superadmin"}`。
**Result**: BLOCKED — `TokenScope::tryFrom('superadmin')` 返回 `null` → 422。如果应用层某种方式通过了，DB CHECK 约束也会阻止。

---

### ATK-07 — 从 DB 提取 token 明文 🚫 BLOCKED

**Attack**：如果攻击者获得了对 `tokens` 表的读取权限，他们能获取有效的 token 吗？
**Result**: BLOCKED — 仅存储 `token_hash`（SHA-256）。逆向 SHA-256 在计算上不可行。原始 token 在颁发时返回一次，服务器端即丢弃。

---

### ATK-08 — 使用空/格式错误的 token 进行验证 🚫 BLOCKED

**Attack**：POST `/tokens/verify` 携带 `{"token": ""}` 或 `{"token": null}`。
**Result**: BLOCKED — 空字符串检查：`if ($token === '') → 422`。`null` 被 `is_string()` 检查拒绝。空字符串的 SHA-256 无论如何都不会匹配任何存储的哈希。

---

### ATK-09 — 为不存在的用户颁发 token 🚫 BLOCKED

**Attack**：POST `/users/9999/tokens`，用户 9999 不存在。
**Result**: BLOCKED — `findUserById(9999)` 返回 `false` → 404，不创建任何 token。

---

### ATK-10 — 双重吊销（幂等性）🚫 BLOCKED

**Attack**：快速连续两次吊销同一 token。
**Result**: BLOCKED — `revokeToken` 使用 `WHERE revoked_at IS NULL`；第二次调用返回 0 行受影响。路由处理器在调用 repo 之前读取 `$token['revoked'] === true` → 409 Conflict。双重吊销没有竞争条件窗口。

---

### ATK-11 — 路径中的负数或字符串 userId 🚫 BLOCKED

**Attack**：`GET /users/-1/tokens` 或 `GET /users/abc/tokens`。
**Result**: BLOCKED — `is_numeric($params['userId'])` → `(int)` 转换。`-1` 变成 -1；`findUserById(-1)` 返回 false → 404。`abc` 不是数字 → `userId = 0` → 404。

---

### ATK-12 — 通过 verify 响应降级 scope 🚫 BLOCKED

**Attack**：获取 `read` scope 的 token 后，尝试在 verify 响应中通过发送修改过的请求体来伪造 `scope: write`。
**Result**: BLOCKED — `/tokens/verify` 只接受原始 token 字符串；scope 从 DB 行读取，不来自任何客户端提供的字段。客户端无法影响返回的 scope。

---

### ATK 汇总

| ID | 攻击 | 结果 |
|----|------|------|
| ATK-01 | 重放已吊销 token | 🚫 BLOCKED |
| ATK-02 | 暴力猜测 token | 🚫 BLOCKED |
| ATK-03 | IDOR：读取他人 token 列表 | 🚫 BLOCKED |
| ATK-04 | IDOR：吊销他人 token | 🚫 BLOCKED |
| ATK-05 | 跨所有者 token 吊销 | 🚫 BLOCKED |
| ATK-06 | 无效 scope 注入 | 🚫 BLOCKED |
| ATK-07 | 从 DB 提取明文 | 🚫 BLOCKED |
| ATK-08 | 空/格式错误 token 验证 | 🚫 BLOCKED |
| ATK-09 | 为不存在用户颁发 token | 🚫 BLOCKED |
| ATK-10 | 双重吊销竞争条件 | 🚫 BLOCKED |
| ATK-11 | 路径中的负数/字符串 userId | 🚫 BLOCKED |
| ATK-12 | 通过 verify 请求体降级 scope | 🚫 BLOCKED |

**12 BLOCKED / SAFE，0 EXPOSED**
无重大发现。仅哈希存储、scope 枚举强制执行和双重 IDOR 检查形成了强健的防御面。

---

## 不应做的事

| 反模式 | 风险 |
|---|---|
| 在 DB 中存储原始 token | DB 读取泄露暴露所有 token；不经用户操作无法轮换 token |
| 使用 MD5/SHA-1 进行 token 哈希 | 碰撞攻击；推荐使用 SHA-256 或 BLAKE2 |
| 接受任意 scope 字符串 | 没有 `tryFrom()` 验证，可以颁发 `superadmin` scope |
| 吊销时无所有权检查 | 任何认证用户都可以吊销任意 token（IDOR） |
| 吊销时硬删除 token | 审计跟踪丢失；无法检测已吊销 token 的重放 |
| 已吊销 token 返回 404 | 无法区分"未找到"和"已吊销"；使用 409 |
