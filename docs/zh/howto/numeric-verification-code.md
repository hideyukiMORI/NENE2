# 操作指南：数字验证码

> **经 FT188 verifylog 验证的模式**——带暴力破解保护、恒定时间比较和重放防御的 6 位短信/邮件验证码。ATK-01〜12 全部通过。

---

## 覆盖范围

联系方式验证流程（邮箱或手机号）：

1. **请求验证码**——服务器生成随机 6 位验证码，通过带外方式发送
2. **提交验证码**——用户提交验证码；最多 3 次尝试后锁定
3. **状态查询**——查询验证是否已完成

安全保证：

| 关注点 | 技术 |
|---|---|
| 暴力破解 | 最多 3 次尝试 → 429 Locked |
| 时序攻击 | `hash_equals()` 恒定时间比较 |
| 验证码重放 | 已验证的验证码返回 410 Gone |
| 用户枚举 | `POST /verifications` 始终返回 202 |
| 批量赋值 | `code_hash/verified_at` 仅由服务端设置 |
| SQL 注入 | 仅整数路径参数（ctype_digit + strlen > 18 守护） |
| 类型混淆 | `ctype_digit()` 之前检查 `is_string()` |
| ReDoS | `ctype_digit()` O(n)——不使用正则 |

---

## 数据库结构

```sql
CREATE TABLE verifications (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    contact        TEXT    NOT NULL,
    code_hash      TEXT    NOT NULL,   -- 6 位验证码的 SHA-256
    attempts_count INTEGER NOT NULL DEFAULT 0,
    max_attempts   INTEGER NOT NULL DEFAULT 3,
    verified_at    TEXT,               -- NULL = 待验证
    expires_at     TEXT    NOT NULL,
    created_at     TEXT    NOT NULL
);
```

`code_hash` 存储 `hash('sha256', $code)`——绝不存储明文验证码。

---

## API

| 方法 | 路径 | 描述 |
|---|---|---|
| `POST` | `/verifications` | 请求验证码（始终 202） |
| `POST` | `/verifications/{id}/check` | 提交验证码（最多 3 次） |
| `GET` | `/verifications/{id}` | 状态查询（不泄露验证码） |

---

## 核心模式：验证码生成与哈希存储

```php
// 生成加密安全的随机 6 位验证码
$plainCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$codeHash  = hash('sha256', $plainCode);

// 存储哈希——绝不存储明文
INSERT INTO verifications (contact, code_hash, expires_at, created_at)
VALUES (:contact, :code_hash, :expires_at, :now)

// 将 plainCode 返回给调用者（用于发送）——绝不存储或记录日志
return ['verification' => $v, 'plainCode' => $plainCode];
```

`random_int(0, 999999)` 使用 CSPRNG。`str_pad(..., 6, '0', STR_PAD_LEFT)` 确保前导零（例如 `000042`）。

---

## 核心模式：恒定时间比较

```php
// ATK-10：hash_equals 防止时序攻击
// $v->codeHash = 从 DB 存储的 SHA-256
// $submittedCode = 用户输入（6 位字符串）
$valid = hash_equals($v->codeHash, hash('sha256', $submittedCode));
```

**为什么不用 `===`**：`===` 在第一个不匹配处短路——攻击者可以通过测量"第一个字节错误"和"所有字节都错误"之间的时间差逐字节确定正确验证码。`hash_equals()` 无论在哪里出现不匹配，始终是恒定时间。

---

## 核心模式：先失败后计数

```php
public function check(int $id, string $submittedCode): string
{
    $v = $this->fetchById($id);

    if ($v === null)        return 'not_found';
    if ($v->isVerified())   return 'already';   // ATK-11：重放守护
    if ($v->isLocked())     return 'locked';    // ATK-05：暴力破解守护
    if ($v->isExpired())    return 'expired';

    // 在检查之前递增——防止竞态利用
    UPDATE verifications SET attempts_count = attempts_count + 1 WHERE id = :id

    // ATK-10：恒定时间比较
    $valid = hash_equals($v->codeHash, hash('sha256', $submittedCode));

    if ($valid) {
        UPDATE verifications SET verified_at = :now WHERE id = :id
        return 'verified';
    }

    return 'wrong';
}
```

在比较**之前**递增尝试次数，确保并发竞争同一验证码的检查无法绕过限制。

---

## 核心模式：用户枚举防护

```php
// POST /verifications——始终返回 202
// 即使联系方式无效或发送失败
private function handleRequest(ServerRequestInterface $request): ResponseInterface
{
    $contact = V::str($body['contact'] ?? null, self::MAX_CONTACT_LEN);

    if ($contact === null || $contact === '') {
        return $this->responseFactory->create(['error' => '...'], 422); // 仅对空/null
    }

    // 发送成功或失败对调用者不可见
    $this->repository->create($contact);

    return $this->responseFactory->create(['id' => $v->id, 'expires_in' => 600], 202);
}
```

未知联系方式返回 404 或 422 会泄露"此联系方式未注册"。始终返回 202。

---

## 核心模式：验证码类型和格式校验

```php
$raw = $body['code'] ?? null;

// ATK-07：类型混淆——验证码必须是字符串
if (!is_string($raw)) {
    return $this->responseFactory->create(['error' => 'code must be a 6-digit string.'], 422);
}

// ATK-09：ReDoS 风格——ctype_digit 是 O(n)，不使用正则
// ATK-09：精确长度检查——不是"至少 6 位"
if (!ctype_digit($raw) || strlen($raw) !== 6) {
    return $this->responseFactory->create(['error' => 'code must be exactly 6 digits.'], 422);
}
```

`ctype_digit()` 之前的 `is_string()` 拒绝 JSON 整数、布尔值和数组。`ctype_digit()` 对 ReDoS 安全（线性时间）。

---

## 响应设计

| 场景 | 状态码 | 响应体 |
|---|---|---|
| 验证码正确 | 200 | `{verified: true}` |
| 验证码错误，还有剩余尝试 | 422 | `{error: "Incorrect code.", attempts_left: N}` |
| 达到最大尝试次数 | 429 | `{error: "Too many failed attempts. Request a new code."}` |
| 已验证（重放） | 410 | `{error: "This verification has already been completed."}` |
| 已过期 | 410 | `{error: "Verification has expired. Request a new code."}` |
| 未找到 | 404 | `{error: "Verification not found."}` |

---

## ATK-01〜12 全部通过

| ATK | 攻击 | 防御 |
|---|---|---|
| 01 | `{id}` 中的 SQL 注入 | `ctype_digit()` + strlen > 18 守护 |
| 02 | IDOR——使用他人的 verification ID 检查 | 相同 404——无所有权 oracle |
| 03 | 批量赋值（从 body 设置 code_hash/verified_at） | 仅由服务端设置 |
| 04 | contact 中的 XSS | 仅 JSON 输出——不渲染 HTML。contact 不返回在响应中 |
| 05 | 暴力破解 6 位验证码 | 3 次失败后 429 Locked |
| 06 | 认证绕过 | verified_at 仅由服务端设置 |
| 07 | 类型混淆（code 为 int/bool/array） | `is_string()` + `ctype_digit()` |
| 08 | `{id}` 整数溢出 | strlen > 18 守护 |
| 09 | ReDoS 风格的 code 输入 | `ctype_digit()` O(n) |
| 10 | 验证码比较时序攻击 | `hash_equals()` 恒定时间 |
| 11 | 成功后验证码重放 | 410 Gone |
| 12 | 请求头中的 CRLF 注入 | PSR-7 在 HTTP 层拒绝 |

---

## 测试结果（FT188）

```
48 个测试 / 103 个断言——全部通过
PHPStan level 8——无错误
PHP CS Fixer——干净
ATK-01〜12 全部通过
```

源码：[`../NENE2-FT/verifylog/`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/verifylog)
