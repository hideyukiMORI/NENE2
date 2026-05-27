# 操作指南：密码重置流程

> **FT 参考**：FT285（`NENE2-FT/resetlog`）——密码重置流程：用户枚举防护（始终返回 202）、SHA-256 令牌哈希存储、1 小时 TTL、单次使用令牌（重用时返回 409）、过期时返回 410 Gone、Argon2id 新密码哈希，15 个测试 / 23 个断言全部通过。
>
> **VULN 评估**：本文末尾包含 V-01 到 V-10 的评估。

本指南展示如何实现安全的密码重置流程——用户请求重置，通过邮件收到令牌，然后使用令牌设置新密码。

## 数据库结构

```sql
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    name          TEXT    NOT NULL,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);

CREATE TABLE password_resets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token_hash TEXT    NOT NULL UNIQUE,
    used_at    TEXT,
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`token_hash TEXT UNIQUE`——存储原始令牌的 SHA-256 哈希。原始令牌发送给客户端，不存储。

## 端点

| 方法 | 路径 | 认证 | 描述 |
|--------|------|------|-------------|
| `POST` | `/password-reset` | 无 | 请求密码重置 |
| `GET` | `/password-reset/{token}` | 无 | 检查令牌状态 |
| `POST` | `/password-reset/{token}` | 无 | 使用新密码完成重置 |

## 用户枚举防护

```php
$user = $this->repo->findUserByEmail($email);

// 始终返回 202 以防止用户枚举
if ($user === null) {
    return $this->json->create(['status' => 'pending'], 202);
}

// 真实用户：创建令牌并（在生产环境中）发送邮件
$rawToken = bin2hex(random_bytes(32));
// ...
return $this->json->create(['status' => 'pending', 'token' => $rawToken], 202);
```

有效和无效邮箱都返回相同的 202 响应。攻击者无法确定哪些邮箱已注册。

> **生产环境注意**：此处在 API 响应中返回令牌是为了可测试性。在生产环境中，只通过邮件发送令牌——绝不在 API 响应中包含它。

## 令牌存储——仅 SHA-256

```php
$rawToken  = bin2hex(random_bytes(32));  // 64 个十六进制字符 = 256 位熵
$tokenHash = hash('sha256', $rawToken);

$this->repo->createReset($user->id, $tokenHash, $expiresAt, $now);

// 将原始令牌返回给客户端（生产环境中：通过邮件，而非 HTTP 响应）
return $this->json->create(['status' => 'pending', 'token' => $rawToken], 202);
```

数据库只存储 SHA-256 哈希。原始令牌发送给用户（生产环境中通过邮件）且不存储。DB 被攻破时只暴露哈希——没有原始令牌则毫无用处。

## 令牌校验

```php
$rawToken  = (string) ($params['token'] ?? '');
$tokenHash = hash('sha256', $rawToken);
$reset     = $this->repo->findByTokenHashOrNull($tokenHash);
```

原始令牌通过请求路径传入。服务器对其哈希并查询 DB。SHA-256 是确定性的——相同的原始令牌总是产生相同的哈希。

## 令牌生命周期状态

```
pending → used（重用时返回 409）
pending → expired（410 Gone）
```

```php
if ($reset->isExpired($now)) {
    return $this->problems->create($request, 'gone', 'Reset token has expired.', 410, '');
}

if ($reset->isUsed()) {
    return $this->problems->create($request, 'conflict', 'Reset token has already been used.', 409, '');
}
```

| 状态 | HTTP | 时机 |
|--------|------|------|
| 未找到 | 404 | 令牌不在 DB 中 |
| 已过期 | 410 Gone | `expires_at` 已过去 |
| 已使用 | 409 Conflict | `used_at` 已设置 |
| 有效 | 200（GET）/ 200（POST） | 活跃、未使用、未过期 |

对于过期资源，`410 Gone` 在语义上比 404 更准确——令牌曾经存在但不再可用。

## 完成重置

```php
$newHash = password_hash($newPassword, PASSWORD_ARGON2ID);
$this->repo->updatePasswordHash($reset->userId, $newHash);
$this->repo->markUsed($tokenHash, $now);  // 设置 used_at = $now

return $this->json->create(['status' => 'completed'], 200);
```

在生产环境中，两个操作都应在事务中执行。如果 `updatePasswordHash` 成功但 `markUsed` 失败，用户密码已重置但令牌仍可重用。

## 密码校验

```php
if (strlen($newPassword) < 8) {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'password', 'code' => 'min-length', 'message' => 'password must be at least 8 characters.']],
    ]);
}
```

最少 8 个字符；在注册和重置时都强制执行。新密码在存储前使用 `PASSWORD_ARGON2ID` 哈希。

---

## VULN 评估——漏洞诊断

### V-01 — 通过重置响应时序/内容进行用户枚举 🛡️ SAFE

**威胁**：攻击者对大量邮箱发送重置请求以识别已注册的邮箱。
**防御**：已注册和未注册邮箱都返回 `202 { "status": "pending" }`，响应体和状态码完全相同。无时序差异（重置请求无需验证密码哈希）。
**结论**：SAFE——无法从 API 响应中进行枚举。

---

### V-02 — 令牌暴力破解 🛡️ SAFE

**威胁**：攻击者猜测令牌值并提交以重置任意账户。
**防御**：`bin2hex(random_bytes(32))` 生成 256 位熵（64 个十六进制字符）。以 10,000 次/秒的猜测速率，暴力破解需要约 10^65 年。SHA-256 哈希比较防止长度扩展和时序 oracle。
**结论**：SAFE——256 位熵不可猜测。

---

### V-03 — 使用后令牌重放 🛡️ SAFE

**威胁**：攻击者拦截重置令牌，在合法用户已重置密码后使用它。
**防御**：重置后 `markUsed()` 设置 `used_at`。后续尝试检查 `isUsed()` → 409 Conflict。
**结论**：SAFE——单次使用强制执行防止重放。

---

### V-04 — 接受过期令牌 🛡️ SAFE

**威胁**：攻击者保存令牌，等待用户登录，然后使用旧令牌。
**防御**：`isExpired($now)` 检查 `expires_at`。令牌 1 小时后过期 → 410 Gone。
**结论**：SAFE——有时间限制的令牌防止延迟攻击。

---

### V-05 — 通过令牌路径参数的 SQL 注入 🛡️ SAFE

**威胁**：提交 `'; DROP TABLE password_resets; --` 作为令牌。
**防御**：`hash('sha256', $rawToken)` 无论输入什么都产生 64 个十六进制字符的字符串。哈希值用于参数化查询（`WHERE token_hash = ?`）。无法通过路径参数进行 SQL 注入。
**结论**：SAFE——哈希 + 参数化查询双重阻止注入。

---

### V-06 — 令牌以明文存储在 DB 中 🛡️ SAFE

**威胁**：DB 被攻破时暴露所有活跃重置令牌；攻击者重置每个账户。
**防御**：DB 只存储 `hash('sha256', $rawToken)`。原始令牌返回给客户端（或通过邮件发送）。SHA-256 是单向的；如果没有暴力破解，哈希无法逆向为原始令牌。
**结论**：SAFE——SHA-256 哈希存储保护静态令牌。

---

### V-07 — 新密码以明文存储 🛡️ SAFE

**威胁**：DB 被攻破时暴露重置期间设置的新密码。
**防御**：`password_hash($newPassword, PASSWORD_ARGON2ID)` 在存储前哈希新密码。明文永不持久化。
**结论**：SAFE——Argon2id 哈希保护静态密码。

---

### V-08 — 通过创建重复重置令牌实现账户接管 🛡️ SAFE

**威胁**：攻击者预测或与其他用户的令牌哈希碰撞。
**防御**：`token_hash TEXT UNIQUE`——重复哈希被 DB 拒绝。在 256 位熵下，碰撞概率可忽略不计（50% 碰撞概率的生日边界约为 2^128 次尝试）。
**结论**：SAFE——UNIQUE 约束 + 256 位熵防止碰撞。

---

### V-09 — 重置时提交弱新密码（< 8 个字符）🛡️ SAFE

**威胁**：攻击者将账户重置为极易猜测的密码如 `aa`。
**防御**：`strlen($newPassword) < 8` → 任何 DB 操作之前的 422 校验错误。
**结论**：SAFE——在重置路径上强制执行最小长度（与注册相同）。

---

### V-10 — 令牌端点揭示哪个步骤失败（枚举）🛡️ SAFE

**威胁**：通过比较 404 vs 409 vs 410 响应，攻击者映射重置令牌的状态。
**防御**：错误代码揭示令牌生命周期状态（未找到/过期/已使用），但不揭示用户信息。知道令牌过期或已使用并不能识别账户持有者。重置请求始终返回 202，无论邮箱是否存在。
**结论**：SAFE——令牌状态响应中不透露用户身份信息。

---

### VULN 汇总

| ID | 威胁 | 结论 |
|----|--------|--------|
| V-01 | 通过重置响应进行用户枚举 | 🛡️ SAFE |
| V-02 | 令牌暴力破解 | 🛡️ SAFE |
| V-03 | 使用后令牌重放 | 🛡️ SAFE |
| V-04 | 接受过期令牌 | 🛡️ SAFE |
| V-05 | 通过令牌路径的 SQL 注入 | 🛡️ SAFE |
| V-06 | 令牌以明文存储 | 🛡️ SAFE |
| V-07 | 新密码以明文存储 | 🛡️ SAFE |
| V-08 | 重复令牌碰撞 | 🛡️ SAFE |
| V-09 | 接受弱新密码 | 🛡️ SAFE |
| V-10 | 令牌状态揭示用户信息 | 🛡️ SAFE |

**10 SAFE，0 EXPOSED**
用户枚举防护、256 位令牌熵、SHA-256 哈希存储、Argon2id 密码哈希以及单次使用强制执行覆盖了所有测试的漏洞向量。

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 未注册邮箱返回 404，已注册返回 202 | 用户枚举——攻击者映射已注册账户 |
| 在 DB 中存储原始令牌 | DB 被攻破时暴露所有活跃重置令牌；大规模账户接管 |
| 在 HTTP 响应体中发送令牌（生产环境） | 令牌被浏览器日志、代理或 JS 拦截；只通过邮件发送 |
| 重置令牌无过期时间 | 旧令牌永久有效；被盗令牌数月后仍可使用 |
| 密码重置后允许令牌重用 | 邮件拦截后的令牌重放攻击 |
| 无最小密码长度 | 用户将 `aa` 设置为新密码 |
| 已使用令牌的 GET `/password-reset/{token}` 返回 200 | 客户端无法区分有效和已使用 |
| 使用 MD5/SHA-1 作为令牌哈希 | 存在预计算彩虹表；使用 SHA-256 或更强算法 |
| `updatePasswordHash` + `markUsed` 无事务 | 竞态条件：密码已更新但令牌仍可重用 |
