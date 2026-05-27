# 操作指南：一次性秘密 API 与 ATK-01~12 破解者攻击测试

> **NENE2 字段试验 184** — 破解者攻击测试循环（ATK-01~12）。
> 令牌即凭证。原子消费防止竞态条件。

---

## 本试验证明了什么

一次性秘密存储只能读取一次的加密消息。第一次成功读取后，秘密永久消费。

安全要求：
1. **256 位令牌熵** — 暴力破解在计算上不可行
2. **原子消费** — `UPDATE WHERE consumed=0` 防止双读竞态
3. **IDOR 防护** — 删除需要令牌 + 用户所有权双重验证
4. **批量赋值阻止** — consumed/token/created_at 仅由服务端设置
5. **类型安全** — V::str() / V::userId() / V::queryInt() 拒绝非字符串输入

---

## API

| 方法 | 路径 | 认证 | 描述 |
|---|---|---|---|
| `POST` | `/secrets` | X-User-Id | 创建一次性秘密 |
| `GET` | `/secrets` | X-User-Id | 列出自己的秘密（仅元数据，不含消息） |
| `GET` | `/secrets/{token}` | — | 读取并消费（令牌即凭证） |
| `DELETE` | `/secrets/{token}` | X-User-Id | 读取前取消（必须是所有者） |

---

## ATK-01~12 结果

| ID | 攻击向量 | 防御 | 结果 |
|---|---|---|---|
| ATK-01 | token 中的 SQL 注入 | PDO 参数化查询 | ✅ PASS |
| ATK-02 | IDOR 跨用户删除 | `WHERE token=? AND user_id=?` | ✅ PASS |
| ATK-03 | 批量赋值（body 中的 `consumed=1`） | 仅服务端字段 | ✅ PASS |
| ATK-04 | message 中的 XSS 载荷 | JSON API——不渲染 HTML | ✅ PASS |
| ATK-05 | 双重编码 / 格式错误的 token | `/^[0-9a-f]{64}$/` 格式检查 | ✅ PASS |
| ATK-06 | 读取时的认证绕过 | 令牌即凭证——设计如此 | ✅ PASS |
| ATK-07 | message/password 为非字符串 | `V::str()` 强制 `is_string()` | ✅ PASS |
| ATK-08 | limit/offset 中的 20 位溢出 | `V::queryInt()` strlen > 18 守护 | ✅ PASS |
| ATK-09 | limit 参数的 ReDoS | `ctype_digit()`——O(n)，无回溯 | ✅ PASS |
| ATK-10 | 暴力破解 token | `random_bytes(32)` = 2^256 熵 | ✅ PASS |
| ATK-11 | 竞态条件双读 | `UPDATE WHERE consumed=0` + rowCount 检查 | ✅ PASS |
| ATK-12 | X-User-Id 中的请求头注入 | `V::userId()` 强制 `ctype_digit()` | ✅ PASS |

**12/12：全部通过**

---

## 核心模式：原子消费

关键安全不变条件——秘密只能读取一次：

```php
// SecretRepository::consumeByToken()

// 步骤 1：获取秘密（普通 SELECT——不是守护）
$row = $pdo->prepare('SELECT * FROM secrets WHERE token = :token');
$row->execute(['token' => $token]);
$secret = $row->fetch(PDO::FETCH_ASSOC);

// 步骤 2：检查 consumed 标志（常见情况的早期退出）
if ($secret['consumed']) return null;

// 步骤 3：原子 UPDATE——这才是真正的守护
$update = $pdo->prepare(
    'UPDATE secrets SET consumed = 1 WHERE token = :token AND consumed = 0'
);
$update->execute(['token' => $token]);

// 步骤 4：rowCount() === 0 表示另一个读取者赢得了竞争
if ($update->rowCount() === 0) {
    return null; // 在我们的 SELECT 和本次 UPDATE 之间，有人消费了它
}

// 步骤 5：我们赢了——返回秘密
return Secret::fromRow($secret);
```

**为什么有效：** SQLite 和大多数 RDBMS 保证 `UPDATE WHERE consumed=0` 是原子的。只有一个并发写入者可以将 `consumed` 从 0 改为 1。失败者的 `rowCount()` 返回 0。

---

## 令牌生成

```php
$token = bin2hex(random_bytes(32)); // 64 个十六进制字符 = 32 字节 = 256 位
```

- `random_bytes()` 使用 OS CSPRNG（等效于 `/dev/urandom`）
- 以 10^12 次/秒猜测速率，2^256 个令牌需要约 10^60 年暴力破解
- 令牌在 DB 中唯一（`UNIQUE` 约束）

---

## 令牌格式校验

```php
private const TOKEN_PATTERN = '/^[0-9a-f]{64}$/';

// 拒绝：大写十六进制、路径遍历 ../../、URL 编码、整数、空字符串
if (!preg_match(self::TOKEN_PATTERN, $rawToken)) {
    return $this->responseFactory->create(['error' => 'Secret not found.'], 404);
}
```

---

## IDOR 防护（ATK-02）

```php
// DELETE 同时需要 token 所有权和 user_id 匹配
$stmt = $pdo->prepare(
    'DELETE FROM secrets WHERE token = :token AND user_id = :user_id AND consumed = 0'
);
$stmt->execute(['token' => $token, 'user_id' => $userId]);

// 无论原因如何都返回 404——避免枚举 oracle
return $stmt->rowCount() > 0;
```

---

## 批量赋值防护（ATK-03）

服务端字段**绝不从请求体读取**：

```php
// POST /secrets 处理器——只接受 body 中的 message、password、expires_at
$token        = bin2hex(random_bytes(32));  // 服务端生成
$consumed     = 0;                          // 始终从未消费开始
$createdAt    = (new DateTimeImmutable())->format(DateTimeInterface::ATOM); // 服务端时间
$passwordHash = $password !== null ? hash('sha256', $password) : null;     // 服务端哈希

// body['consumed']、body['token']、body['user_id']、body['created_at'] 被静默忽略
```

---

## V.php 校验链

```php
// ATK-07：message 必须是字符串（拒绝 int、bool、null、array）
$message = V::str($body['message'] ?? null, 10000);

// ATK-12：X-User-Id 必须是 ctype_digit + 正整数 + 最多 18 字符
$userId = V::userId($request->getHeaderLine('X-User-Id'));

// ATK-08/09：limit 必须是数字，最多 18 位，范围 1–100
$limit = V::queryInt($params, 'limit', 1, 100, 20);
```

---

## 可选密码保护

```php
// 存储：只存 SHA-256 哈希（不存明文）
$passwordHash = $password !== null ? hash('sha256', $password) : null;

// 验证：恒定时间比较（防时序攻击）
if (!hash_equals($secret->passwordHash, hash('sha256', $submittedPassword))) {
    return null; // 密码错误 → 静默 404（无 oracle）
}
```

> **注意：** 密码错误返回 404（而非 403）以防止 oracle 攻击。
> 密码错误时秘密**不被消费**——只有正确密码才会消费它。

---

## 元数据列表（不泄露消息）

```php
// GET /secrets——只返回元数据，绝不返回消息
private function secretToMetadata(Secret $secret): array
{
    return [
        'token'        => $secret->token,
        'has_password' => $secret->passwordHash !== null,
        'consumed'     => $secret->consumed,
        'expires_at'   => $secret->expiresAt,
        'created_at'   => $secret->createdAt,
        // 'message' 故意省略
    ];
}
```

---

## 测试结果

```
85 个测试 / 209 个断言——全部通过
PHPStan level 8——无错误
PHP CS Fixer——干净
```

---

## 关键要点

| 模式 | 规则 |
|---|---|
| 原子消费 | `UPDATE WHERE consumed=0` + `rowCount()` 检查——不是 SELECT 再 UPDATE |
| 令牌熵 | 最少 `random_bytes(32)`（256 位）——绝不使用顺序 ID |
| 令牌格式 | 两端锚定的允许列表正则（`/^[0-9a-f]{64}$/`） |
| IDOR | 所有写操作按 `token AND user_id` 限定范围 |
| 批量赋值 | token、consumed、created_at——仅服务端，绝不从 body 读取 |
| 密码时序 | `hash_equals()` 进行恒定时间比较 |
| 密码错误 | 404 而非 403——避免确认秘密是否存在 |
| 元数据列表 | 从列表端点省略 message——只在消费时读取 |

完整示例：[`../NENE2-FT/onetimelog/`](https://github.com/hideyukiMORI/NENE2-examples)
