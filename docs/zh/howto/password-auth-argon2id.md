# 操作指南：使用 Argon2id 的密码认证

> **FT 参考**：FT331（`NENE2-FT/pwdlog`）——使用 Argon2id 密码哈希的用户注册和登录，响应中绝不暴露密码/哈希，用户枚举防护（错误密码和未知邮箱都返回相同的 401），算法迁移重新哈希，14 个测试 / 40 个断言全部通过。

本指南展示如何构建安全的基于密码的认证：使用 Argon2id 安全存储密码，在响应中绝不泄露凭证，并防止攻击者枚举已注册的邮箱地址。

## 数据库结构

```sql
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);
```

`password_hash` 存储完整的 Argon2id 输出字符串（如 `$argon2id$v=19$m=65536,...`）。**绝不存储明文或 MD5/SHA-1。**

## 端点

| 方法 | 路径 | 描述 |
|--------|------|-------------|
| `POST` | `/register` | 注册新用户 |
| `POST` | `/login` | 认证并返回用户数据 |

## 注册

```php
POST /register
{"email": "alice@example.com", "password": "correct-horse"}

→ 201
{"id": 1, "email": "alice@example.com", "created_at": "2026-05-27T09:00:00Z"}
```

**`password` 和 `password_hash` 绝不在响应中返回**——即使是掩码或截断形式也不行。

### 校验

```php
POST /register  {"email": "alice@example.com", "password": "short"}
→ 422  // 密码太短（最少 8 个字符）

POST /register  {"email": "not-an-email", "password": "correct-horse"}
→ 422  // 无效邮箱格式

POST /register  {"email": "alice@example.com"}
→ 400  // 缺少 password 字段

POST /register  {"email": "alice@example.com", "password": "battery-staple"}
// （alice 已注册）
→ 409  {"type": ".../email-taken", "detail": "Email already registered"}
```

## 登录

```php
POST /login
{"email": "alice@example.com", "password": "correct-horse"}

→ 200
{"id": 1, "email": "alice@example.com", "created_at": "..."}
// 不返回 password_hash
```

### 用户枚举防护

```php
// 已知邮箱的错误密码
POST /login  {"email": "alice@example.com", "password": "wrong"}
→ 401  {"type": ".../invalid-credentials", "detail": "Invalid email or password"}

// 未知邮箱
POST /login  {"email": "ghost@example.com", "password": "any"}
→ 401  {"type": ".../invalid-credentials", "detail": "Invalid email or password"}
```

**两种情况都返回带相同 `detail` 消息的 401。** 对未知邮箱返回 404 会让攻击者能够探测用户数据库。

```php
// 测试：相同的 detail 字符串
$this->assertSame($wrongPasswordBody['detail'], $unknownEmailBody['detail']);
```

## 实现

### 密码存储——Argon2id

```php
// 注册
$hash = password_hash($plaintext, PASSWORD_ARGON2ID);
// 存储：$argon2id$v=19$m=65536,t=4,p=1$...

// 绝不存储：
// md5($plaintext)          — 可在数秒内逆向
// sha1($plaintext)         — 彩虹表攻击
// $plaintext               — 明文存储
```

PHP 的 `password_hash(PASSWORD_ARGON2ID)` 自动：
- 为每个哈希生成随机盐
- 将算法、参数、盐和摘要存储在一个字符串中
- 抵抗 GPU 暴力破解（内存密集型）

### 验证——恒定时间

```php
$row = $this->repo->findByEmail($email);

if ($row === null || !password_verify($plaintext, $row['password_hash'])) {
    // 无论邮箱未知还是密码错误，响应相同
    return $this->problems->create('invalid-credentials', 'Invalid email or password', 401);
}
```

`password_verify()` 是恒定时间的，适用于所有算法族（bcrypt、Argon2id 等）。

### 算法迁移重新哈希

从 bcrypt 升级到 Argon2id 时，在成功登录时重新哈希：

```php
if (password_needs_rehash($row['password_hash'], PASSWORD_ARGON2ID)) {
    $newHash = password_hash($plaintext, PASSWORD_ARGON2ID);
    $this->repo->updateHash($row['id'], $newHash);
}
```

用户在下次登录时静默迁移到更强的算法——无需强制重置密码。

### 绝不在响应中返回凭证

```php
private function toPublic(array $user): array
{
    // 显式删除敏感字段
    unset($user['password_hash']);
    return $user;
}
```

在每个响应中应用 `toPublic()`：注册 201、登录 200 以及任何个人资料端点。

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 登录时对未知邮箱返回 404 | 用户枚举：攻击者发现哪些邮箱已注册 |
| 错误密码与未知邮箱返回不同的 `detail` 消息 | 泄露哪个条件失败 |
| 将密码存储为 MD5 或 SHA-1 | 彩虹表攻击可在数小时内破解所有密码 |
| 使用 bcrypt 存储密码但无迁移路径 | 无法在不强制重置的情况下升级到更强算法 |
| 在任何响应中返回 `password_hash` | 哈希可用于离线暴力破解 |
| 登录时跳过 `password_needs_rehash()` | 即使在算法升级后，旧的弱哈希也会永久保留 |
| 使用 `===` 比较哈希 | 时序攻击揭示哈希字节；始终使用 `password_verify()` |
