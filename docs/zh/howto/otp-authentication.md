# 操作指南：OTP 认证系统

> **FT 参考**：FT290（`NENE2-FT/otplog`）——OTP 认证：6 位数字验证码配合 SHA-256 哈希存储，暴力破解锁定（3 次尝试 → 10 分钟），OTP TTL（5 分钟），通过 `used_at` 防重放攻击，会话令牌使用 SHA-256 + 吊销机制，通过 always-202 请求端点防止用户枚举，ATK-01~12 全部通过，35 个测试 / 44 个断言全部通过。

本指南展示如何构建无密码 OTP（一次性密码）认证系统，用户收到 6 位数字验证码后将其换取会话令牌。

## 数据库结构

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL
);

CREATE TABLE otp_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    code_hash TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    used_at TEXT,
    attempt_count INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE otp_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    session_token_hash TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    revoked_at TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

关键设计要点：
- `code_hash` 存储 OTP 的 SHA-256 哈希，而非原始验证码。
- `attempt_count` + `locked_until` 为每条 OTP 记录实现暴力破解锁定。
- `used_at` 防止重放攻击（OTP 只能使用一次）。
- `session_token_hash` 存储会话令牌的 SHA-256 哈希；`UNIQUE` 防止碰撞。
- `revoked_at` 支持显式注销而无需删除记录。

## 端点

| 方法 | 路径 | 认证 | 描述 |
|--------|------|------|-------------|
| `POST` | `/otp/request` | 无 | 请求 OTP（如需则创建用户） |
| `POST` | `/otp/verify` | 无 | 验证 OTP，接收会话令牌 |
| `GET` | `/otp/session` | `Bearer <token>` | 获取会话信息 |
| `DELETE` | `/otp/session` | `Bearer <token>` | 注销（吊销会话） |

## OTP 生成——绝不存储原始验证码

```php
private const int MAX_ATTEMPTS = 3;
private const int OTP_TTL_MINUTES = 5;
private const int LOCK_MINUTES = 10;
private const int SESSION_TTL_HOURS = 24;

$rawCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$codeHash = hash('sha256', $rawCode);
$this->repository->createOtp($userId, $codeHash, $now);
```

`str_pad` 确保前导零（例如 `random_int(0, 999999)` 返回 `42` → `'000042'`）。原始验证码发送到用户邮箱，只有哈希值存入数据库。`random_int()` 是密码学安全的。

## 用户枚举防护——始终返回 202

```php
// 始终返回 202 — 防止用户枚举
// 生产环境：发送邮件。本 FT 中返回验证码用于测试。
return $this->responseFactory->create([
    'message' => 'OTP code sent',
    'code' => $rawCode,  // 生产环境中移除
], 202);
```

无论邮箱是否存在，响应始终为 `202 Accepted`。攻击者无法区分"账户存在"和"账户不存在"。

## 首次请求时自动创建用户

```php
public function findOrCreateUser(string $email, string $now): int
{
    $user = $this->findUserByEmail($email);
    if ($user !== null) {
        return (int) $user['id'];
    }
    return $this->executor->insert(
        'INSERT INTO users (email, created_at) VALUES (?, ?)',
        [$email, $now]
    );
}
```

用户在首次 OTP 请求时隐式创建——无需单独的注册步骤。`UNIQUE(email)` 约束防止并发插入时产生重复。

## OTP 验证——按序检查

```php
// 1. 锁定检查（首先——在任何验证码比较之前）
if ($otp['locked_until'] !== null && $now < (string) $otp['locked_until']) {
    return $this->responseFactory->create(['error' => 'too many attempts, try again later'], 429);
}

// 2. 过期检查
if ($now > (string) $otp['expires_at']) {
    return $this->responseFactory->create(['error' => 'code expired'], 401);
}

// 3. 已使用检查
if ($otp['used_at'] !== null) {
    return $this->responseFactory->create(['error' => 'code already used'], 401);
}

// 4. 使用 hash_equals（时序安全）进行验证码检查
$codeHash = hash('sha256', $code);
if (!hash_equals((string) $otp['code_hash'], $codeHash)) {
    $this->repository->incrementAttempt((int) $otp['id'], $now);
    return $this->responseFactory->create(['error' => 'invalid code'], 401);
}
```

检查顺序很重要：锁定 → 过期 → 已使用 → 验证码。只在验证码错误时递增 `attempt_count`——不在锁定或过期时递增。

## 暴力破解锁定

```php
public function incrementAttempt(int $otpId, string $now): void
{
    $otp = $this->executor->fetchOne('SELECT * FROM otp_codes WHERE id = ?', [$otpId]);
    if ($otp === null) {
        return;
    }
    $newCount = (int) $otp['attempt_count'] + 1;
    $lockedUntil = null;
    if ($newCount >= self::MAX_ATTEMPTS) {
        $lockedUntil = date('c', strtotime($now) + self::LOCK_MINUTES * 60);
    }
    $this->executor->execute(
        'UPDATE otp_codes SET attempt_count = ?, locked_until = ? WHERE id = ?',
        [$newCount, $lockedUntil, $otpId]
    );
}
```

错误验证码达到 `MAX_ATTEMPTS`（3）次后，`locked_until` 被设置为未来 10 分钟。锁定检查发生在任何验证码比较之前，因此锁定期间的尝试不会重置计时器。

## 最新 OTP 优先——新请求使旧 OTP 失效

```php
public function findLatestOtpForUser(int $userId): ?array
{
    return $this->executor->fetchOne(
        'SELECT * FROM otp_codes WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        [$userId]
    );
}
```

多次 OTP 请求会创建多条记录，但只有最新的一条用于验证。旧 OTP 实际上已失效——提交它们会返回 401。

## 会话令牌——SHA-256 + 吊销

```php
// 签发会话令牌
$rawToken = bin2hex(random_bytes(32));   // 256 位熵，64 个十六进制字符
$tokenHash = hash('sha256', $rawToken);
$this->repository->createSession((int) $user['id'], $tokenHash, $now);

return $this->responseFactory->create([
    'session_token' => $rawToken,
    'user_id' => (int) $user['id'],
], 200);
```

只存储 SHA-256 哈希。即使数据库被攻破，原始令牌也不会泄露。

## Bearer 令牌提取

```php
private function extractBearerToken(ServerRequestInterface $request): string
{
    $header = $request->getHeaderLine('Authorization');
    if (!str_starts_with($header, 'Bearer ')) {
        return '';
    }
    return trim(substr($header, 7));
}
```

`Bearer ` 之后的空字符串（如 `Authorization: Bearer `）被视为缺失——返回 401。

## 注销——静默成功

```php
$session = $this->repository->findSessionByTokenHash($tokenHash);
if ($session !== null && $session['revoked_at'] === null) {
    $this->repository->revokeSession($tokenHash, date('c'));
}

return $this->responseFactory->create(['message' => 'logged out'], 200);
```

注销始终返回 200——不透露令牌是否有效。这防止攻击者通过注销端点探测令牌有效性。

---

## ATK 评估——破解者思维攻击测试

### ATK-01 — 暴力破解 OTP 🚫 BLOCKED

**攻击**：按顺序尝试所有 `000000`–`999999` 组合。
**结论**：🚫 BLOCKED——错误验证码达到 `MAX_ATTEMPTS`（3）次后，`locked_until` 被设置为未来 10 分钟。后续尝试返回 429，直到锁定过期。

---

### ATK-02 — 重放攻击（重用已使用的 OTP）🚫 BLOCKED

**攻击**：捕获有效的 OTP，在已使用后再次提交。
**结论**：🚫 BLOCKED——首次成功验证时设置 `used_at`。第二次尝试发现 `used_at !== null` → 401。

---

### ATK-03 — 通过 /otp/request 进行用户枚举 🚫 BLOCKED

**攻击**：用已知和未知邮箱探测 `/otp/request` 以发现哪些账户存在。
**结论**：🚫 BLOCKED——已存在和不存在的邮箱始终返回带相同响应体的 `202 Accepted`。

---

### ATK-04 — 为不存在的用户验证 🚫 BLOCKED

**攻击**：用没有账户的邮箱调用 `/otp/verify`。
**结论**：🚫 BLOCKED——返回 401（`invalid code`），而非 404 或 500。响应中无堆栈跟踪或账户存在性信号。

---

### ATK-05 — 邮箱字段中的 SQL 注入 🚫 BLOCKED

**攻击**：提交 `'; DROP TABLE users; --` 作为邮箱。
**结论**：🚫 BLOCKED——`filter_var($email, FILTER_VALIDATE_EMAIL)` 在任何 DB 查询之前就拒绝了注入字符串（无效邮箱格式）。所有查询均使用参数化语句。

---

### ATK-06 — 5 位验证码（太短）🚫 BLOCKED

**攻击**：提交 5 个字符的验证码以绕过 OTP 格式检查。
**结论**：🚫 BLOCKED——`/^\d{6}$/` 要求恰好 6 位数字。返回 422。

---

### ATK-07 — 7 位验证码（太长）🚫 BLOCKED

**攻击**：提交 7 位验证码以绕过格式校验。
**结论**：🚫 BLOCKED——相同的正则表达式拒绝不恰好为 6 位的验证码。返回 422。

---

### ATK-08 — 注销后重用会话令牌 🚫 BLOCKED

**攻击**：注销后使用令牌以维持访问。
**结论**：🚫 BLOCKED——`revokeSession()` 设置 `revoked_at`。GET 处理器检查 `$session['revoked_at'] !== null` → 401。

---

### ATK-09 — 随机令牌猜测 🚫 BLOCKED

**攻击**：提交随机的 64 个十六进制字符的字符串作为 Bearer 令牌。
**结论**：🚫 BLOCKED——随机令牌的 SHA-256 哈希不匹配任何 `session_token_hash`。返回 401。令牌空间为 2^256。

---

### ATK-10 — 空 Bearer 令牌 🚫 BLOCKED

**攻击**：发送 `Authorization: Bearer `（Bearer 前缀后为空）。
**结论**：🚫 BLOCKED——`trim(substr($header, 7))` 返回空字符串 → `if ($token === '') return 401`。

---

### ATK-11 — 字母验证码（非数字）🚫 BLOCKED

**攻击**：提交 `abcdef` 作为 OTP 验证码。
**结论**：🚫 BLOCKED——`/^\d{6}$/` 只允许十进制数字。在任何 DB 交互之前返回 422。

---

### ATK-12 — 新 OTP 请求使旧验证码失效 🚫 BLOCKED（设计如此）

**攻击**：获取有效的 OTP，让受害者请求新的，然后提交原始验证码。
**结论**：🚫 BLOCKED——`findLatestOtpForUser()` 只检索 `ORDER BY id DESC LIMIT 1`。旧 OTP 被取代；提交它返回 401（最新 OTP 的验证码哈希不匹配）。

---

### ATK 汇总

| ID | 攻击 | 结论 |
|----|--------|--------|
| ATK-01 | 暴力破解 OTP | 🚫 BLOCKED |
| ATK-02 | 重放攻击（已使用的 OTP） | 🚫 BLOCKED |
| ATK-03 | 通过 /otp/request 进行用户枚举 | 🚫 BLOCKED |
| ATK-04 | 验证不存在的用户 | 🚫 BLOCKED |
| ATK-05 | 邮箱中的 SQL 注入 | 🚫 BLOCKED |
| ATK-06 | 5 位验证码（太短） | 🚫 BLOCKED |
| ATK-07 | 7 位验证码（太长） | 🚫 BLOCKED |
| ATK-08 | 注销后重用会话 | 🚫 BLOCKED |
| ATK-09 | 随机令牌猜测 | 🚫 BLOCKED |
| ATK-10 | 空 Bearer 令牌 | 🚫 BLOCKED |
| ATK-11 | 字母验证码 | 🚫 BLOCKED |
| ATK-12 | 新请求使旧 OTP 失效 | 🚫 BLOCKED |

**12 BLOCKED，0 EXPOSED**
基于哈希的存储、暴力破解锁定、`used_at` 重放防护、格式校验以及 always-202 枚举防护涵盖了所有关键 OTP 攻击向量。

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 在 DB 中存储原始 OTP 验证码 | DB 被攻破时暴露所有活跃 OTP；始终使用 SHA-256 哈希 |
| 无暴力破解锁定 | 6 位 OTP 有 10^6 种组合——无锁定时数秒内可被暴力破解 |
| 验证时对未知邮箱返回 404 | 暴露哪些邮箱拥有账户（用户枚举） |
| /request 对已知和未知邮箱返回不同状态 | 同样的枚举风险；始终返回 202 |
| 无 `used_at` 标志 | OTP 可被无限重放直到过期 |
| 接受字母或非 6 位验证码 | 绕过格式契约；添加 `/^\d{6}$/` 检查 |
| 在 DB 中存储原始会话令牌 | DB 被攻破时暴露所有会话；只存储 SHA-256 哈希 |
| 注销时删除会话记录 | 无法检测已吊销的令牌；使用 `revoked_at` 软吊销 |
| 根据令牌有效性透露注销成功/失败 | 攻击者通过注销端点探测令牌有效性；始终返回 200 |
| 使用 `findAllOtpsForUser()` 并选择有效的 | 多个活跃 OTP 导致状态混乱；使用 `ORDER BY id DESC LIMIT 1` |
| 无邮箱长度限制 | RFC 5321 最大 254 个字符；超长输入导致 DB/邮件问题 |
