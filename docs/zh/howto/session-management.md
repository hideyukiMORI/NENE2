# 操作指南：多设备 Session 管理器

> **FT186 sessionlog 验证的模式** — 多设备 session 跟踪、IDOR 防护、批量赋值守护、无时序预言机的吊销机制。

---

## 本指南涵盖内容

多设备 session 管理器允许用户：

1. **创建 session**（登录时，每个设备获得独立令牌）
2. **列出活跃 session**（作用域限定为自己的用户 ID）
3. **吊销单个 session**（从一台设备登出）
4. **吊销除当前以外的所有 session**（从所有其他设备登出）

演示的安全保证：

| 关注点 | 技术 |
|---|---|
| IDOR 防护 | 所有变更操作都加上 `WHERE token = ? AND user_id = ?` |
| 批量赋值 | `token`、`user_id`、`created_at`、`revoked_at` 只在服务器端设置 |
| 时序预言机 | 所有失败均返回通用 404——不泄露所有权信息 |
| 整数溢出 | `V::queryInt()` 18 位数字长度守护 |
| 类型混淆 | `V::str()` 拒绝非字符串的 `device_name`/`ip_address` |
| 令牌熵 | `bin2hex(random_bytes(32))` — 256 位，64 个十六进制字符 |
| SQL 注入 | PDO 参数化查询 + `/^[0-9a-f]{64}$/` 格式门控 |

---

## 数据库结构

```sql
CREATE TABLE sessions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    token       TEXT    NOT NULL UNIQUE,
    device_name TEXT,
    ip_address  TEXT,
    last_active TEXT    NOT NULL,
    created_at  TEXT    NOT NULL,
    revoked_at  TEXT    -- NULL = 活跃
);
```

`revoked_at IS NULL` 是活跃 session 的判断条件。软删除可以保留审计历史记录。

---

## API 设计

| 方法 | 路径 | 请求头 | 说明 |
|---|---|---|---|
| `POST` | `/sessions` | `X-User-Id` | 创建 session |
| `GET` | `/sessions` | `X-User-Id` | 列出自己的活跃 session |
| `DELETE` | `/sessions/{token}` | `X-User-Id` | 吊销单个 session |
| `DELETE` | `/sessions` | `X-User-Id` + `X-Current-Session` | 吊销除当前以外的所有 session |

---

## 核心模式：256 位令牌生成

```php
public function create(int $userId, ?string $deviceName, ?string $ipAddress): Session
{
    $token = bin2hex(random_bytes(32)); // 256 位熵，64 个十六进制字符
    $now   = $this->now();

    $stmt = $this->pdo->prepare(
        'INSERT INTO sessions (user_id, token, device_name, ip_address, last_active, created_at)
         VALUES (:user_id, :token, :device_name, :ip_address, :now, :now2)',
    );
    $stmt->execute([...]);
    // ...
}
```

`bin2hex(random_bytes(32))` 从密码学安全源生成 64 个小写十六进制字符。永远不要接受来自用户输入的令牌。

---

## 核心模式：IDOR 防护

```php
// 错误——允许任何已认证用户吊销任意 session
UPDATE sessions SET revoked_at = ? WHERE token = ?

// 正确——必须是自己的 session
public function revokeForUser(string $token, int $userId): bool
{
    $stmt = $this->pdo->prepare(
        'UPDATE sessions SET revoked_at = :now
         WHERE token = :token AND user_id = :user_id AND revoked_at IS NULL',
    );
    $stmt->execute(['now' => $this->now(), 'token' => $token, 'user_id' => $userId]);

    return $stmt->rowCount() > 0;
}
```

当令牌存在但属于其他用户时，`rowCount() > 0` 返回 `false`——处理器以通用 404 响应（参见时序预言机章节）。

---

## 核心模式：批量赋值守护

```php
// POST /sessions 处理器——攻击者请求体：{"token": "custom", "user_id": 999, "revoked_at": "now"}
private function handleCreate(ServerRequestInterface $request): ResponseInterface
{
    // userId 来自 X-User-Id 请求头——绝不从请求体获取
    $userId = V::userId($request->getHeaderLine('X-User-Id'));

    $body = $this->parseBody($request);

    // 只传递经过安全校验的字段
    $deviceName = V::str($body['device_name'] ?? null, 200);
    $ipAddress  = V::str($body['ip_address'] ?? null, 45);

    // token、user_id、created_at、revoked_at 由 repository 设置——不来自请求体
    $session = $this->repository->create($userId, $deviceName, $ipAddress);

    return $this->responseFactory->create($session->toArray(), 201);
}
```

---

## 核心模式：防止时序预言机

```php
private function handleRevokeOne(ServerRequestInterface $request): ResponseInterface
{
    $userId   = V::userId($request->getHeaderLine('X-User-Id'));
    $rawToken = Router::param($request, 'token');

    // VULN-I：格式无效 → 立即返回 404（不查询 DB）
    if ($rawToken === null || !preg_match('/^[0-9a-f]{64}$/', $rawToken)) {
        return $this->responseFactory->create(['error' => 'Session not found.'], 404);
    }

    // IDOR 守护：revokeForUser 在以下情况返回 false：
    //   - 令牌不存在
    //   - 令牌属于其他用户
    //   - 令牌已被吊销
    // 所有情况都返回相同的 404——不泄露所有权信息
    $revoked = $this->repository->revokeForUser($rawToken, $userId);

    if (!$revoked) {
        return $this->responseFactory->create(['error' => 'Session not found.'], 404);
    }

    return $this->responseFactory->create([], 204);
}
```

永远不要在响应中区分"未找到"和"用户不匹配"。知道受害者令牌的攻击者不应能判断它是否活跃或属于哪个用户。

---

## 核心模式：吊销除当前以外的所有 Session

```php
public function revokeAllExcept(int $userId, string $currentToken): int
{
    $stmt = $this->pdo->prepare(
        'UPDATE sessions SET revoked_at = :now
         WHERE user_id = :user_id AND token != :current AND revoked_at IS NULL',
    );
    $stmt->execute(['now' => $this->now(), 'user_id' => $userId, 'current' => $currentToken]);

    return $stmt->rowCount();
}
```

调用者传入 `X-Current-Session` 请求头。`user_id` 和排除条件都在单次查询中强制执行。

---

## 核心模式：防溢出的 limit 校验

```php
// VULN-A：V::queryInt 拒绝超过 18 位数字——防止 PHP 整数静默溢出
// VULN-F：ctype_digit 是 O(n)——无正则回溯风险
$limit = V::queryInt($params, 'limit', 1, self::MAX_LIMIT, self::DEFAULT_LIMIT);

if ($limit === null) {
    return $this->responseFactory->create(
        ['error' => sprintf('limit must be between 1 and %d.', self::MAX_LIMIT)],
        422,
    );
}
```

`V::queryInt()` 拒绝负数、浮点数、十六进制字符串（`0x10`）以及超过 18 位数字的数字。

---

## 路由层令牌校验

在查询 DB 之前，始终在路由层校验令牌格式：

```php
private const TOKEN_PATTERN = '/^[0-9a-f]{64}$/';

// 在处理器中：
if ($rawToken === null || !preg_match(self::TOKEN_PATTERN, $rawToken)) {
    return $this->responseFactory->create(['error' => 'Session not found.'], 404);
}
```

这在任何数据库交互之前就阻断了 SQL 注入字符串、路径遍历尝试以及过短/过长的令牌。

---

## 测试结果（FT186）

```
54 tests / 116 assertions — 全部 PASS
PHPStan level 8 — 无错误
PHP CS Fixer — 干净
```

VULN-A~L 覆盖：

| 漏洞 | 模式 | 测试 |
|---|---|---|
| A | limit 19 位数字溢出 | `testVulnALimitOverflow19Digits` |
| B | device_name 类型混淆 | `testVulnBDeviceNameAsInteger` |
| C | token 中的 SQL 注入 | `testVulnCSqlInjectionToken` |
| D | 负数/浮点数/十六进制 limit | `testVulnDNegativeLimitRejected` |
| E | IDOR 吊销 | `testVulnECannotRevokeOtherUsersSession` |
| F | ReDoS 风格的超长 limit | `testVulnFVeryLongLimitRejected` |
| H | 时序预言机 | `testVulnHSameResponseForAlreadyRevokedAndCrossUser` |
| I | 空/短/路径遍历令牌 | `testVulnIEmptyTokenSegmentNotMatched` |
| L | 批量赋值 | `testVulnLTokenFromBodyIsIgnored` |

源码：[`../NENE2-FT/sessionlog/`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/sessionlog)
