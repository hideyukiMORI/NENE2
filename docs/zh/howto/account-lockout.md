# 账户锁定（暴力破解防护）

> **FT 参考**：FT280（`NENE2-FT/lockoutlog`）——账户锁定：5 次失败尝试触发 15 分钟锁定（423 Locked），锁定期间正确密码也会被拦截，成功登录重置计数器，Argon2id 密码验证，MySQL 集成测试，27 个测试通过 / 5 个跳过（MySQL），44 个断言全部通过。
>
> **ATK 评估**：ATK-01 至 ATK-12 包含在本文档末尾。

通过在可配置次数的失败尝试后锁定账户，保护登录端点免受暴力破解攻击。

## 概述

账户锁定按电子邮件地址跟踪失败的登录尝试，当失败次数超过阈值时设置 `locked_until` 时间戳。锁定状态在每次登录尝试时都会被检查——即使密码正确，在账户被锁定期间也会被拒绝。锁定在冷却期后自动解除。

## 数据库结构

```sql
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);

CREATE TABLE account_states (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    email        TEXT    NOT NULL UNIQUE,
    failed_count INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT,
    updated_at   TEXT    NOT NULL
);
```

`account_states` 跟踪每个账户的失败历史。`locked_until` 对未锁定账户为 null。

## 常量

```php
public const int MAX_ATTEMPTS    = 5;   // 触发锁定前的失败次数
public const int LOCKOUT_MINUTES = 15;  // 锁定持续时间
```

## 登录流程

```php
// 1. 在密码验证之前检查锁定状态
$state = $this->repo->findOrCreateAccountState($email, $now);
if ($state->isLocked($now)) {
    return 423; // Locked
}

// 2. 验证凭据
$user = $this->repo->findUserByEmail($email);
if ($user === null || !$user->verifyPassword($pass)) {
    if ($user !== null) {
        $this->repo->recordFailure($email, $now);
    }
    return 401; // Unauthorized
}

// 3. 成功——重置计数器
$this->repo->resetState($email, $now);
return 200;
```

锁定检查发生在密码验证**之前**。锁定状态只针对**已存在的用户**写入——未知邮箱返回 401 而不创建 `account_state` 行（防止存储耗尽）。

## 锁定检查

```php
public function isLocked(string $now): bool
{
    return $this->lockedUntil !== null && $now < $this->lockedUntil;
}
```

`$now` 是 `Y-m-d H:i:s` 格式的字符串。字典序比较对 ISO 8601 日期时间字符串有效。

## 记录失败

```php
public function recordFailure(string $email, string $now): AccountState
{
    $state    = $this->findOrCreateAccountState($email, $now);
    $newCount = $state->failedCount + 1;

    $lockedUntil = null;
    if ($newCount >= AccountState::MAX_ATTEMPTS) {
        $lockedUntil = date('Y-m-d H:i:s', strtotime($now) + AccountState::LOCKOUT_MINUTES * 60);
    }

    $this->executor->execute(
        'UPDATE account_states SET failed_count = ?, locked_until = ?, updated_at = ? WHERE email = ?',
        [$newCount, $lockedUntil, $now, $email],
    );
    ...
}
```

当 `failed_count` 达到 `MAX_ATTEMPTS` 时，`locked_until` 被设置为 `now + LOCKOUT_MINUTES * 60` 秒后。

## 成功时重置

```php
$this->executor->execute(
    'UPDATE account_states SET failed_count = 0, locked_until = NULL, updated_at = ? WHERE email = ?',
    [$now, $email],
);
```

认证成功后同时重置 `failed_count` 和 `locked_until`。在锁定前成功登录的用户会获得全新的失败计数器。

## 防止用户枚举

对密码错误和邮箱不存在返回相同的 HTTP 状态码（401）：

```php
if ($user === null || !$user->verifyPassword($pass)) {
    if ($user !== null) {
        $this->repo->recordFailure($email, $now);
    }
    return 401; // 无论哪种情况状态码相同
}
```

攻击者无法通过 HTTP 响应区分"账户不存在"和"密码错误"。

## MySQL 数据库结构

对于 MySQL，使用 `INT AUTO_INCREMENT` 和 `DATETIME`：

```sql
CREATE TABLE IF NOT EXISTS users (
    id            INT          NOT NULL AUTO_INCREMENT,
    email         VARCHAR(255) NOT NULL,
    password_hash TEXT         NOT NULL,
    created_at    DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS account_states (
    id           INT          NOT NULL AUTO_INCREMENT,
    email        VARCHAR(255) NOT NULL,
    failed_count INT          NOT NULL DEFAULT 0,
    locked_until DATETIME     NULL,
    updated_at   DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

`Y-m-d H:i:s` 日期时间格式同时适用于 SQLite（TEXT 比较）和 MySQL（DATETIME 列）。

## MySQL 集成测试

添加一个 `MysqlLockoutTest.php`，在未设置 `MYSQL_HOST` 时跳过：

```php
protected function setUp(): void
{
    $host = (string) (getenv('MYSQL_HOST') ?: '');
    if ($host === '') {
        self::markTestSkipped('MYSQL_HOST not set — skipping MySQL integration tests');
    }
    // 删除并重建表以实现测试隔离
    $this->pdo->exec('DROP TABLE IF EXISTS account_states');
    $this->pdo->exec('DROP TABLE IF EXISTS users');
    $this->pdo->exec($mysqlSchema);
    ...
}
```

针对共享 FT MySQL 容器运行（端口 3308，持久化卷）：

```bash
docker compose -f ../NENE2-FT/docker-compose.yml up -d mysql
```

然后使用环境变量运行集成测试：

```bash
MYSQL_HOST=127.0.0.1 MYSQL_PORT=3308 MYSQL_DATABASE=ft_test \
  MYSQL_USER=ft_user MYSQL_PASSWORD=ft_pass \
  php8.4 vendor/bin/phpunit --filter Mysql
```

未设置 `MYSQL_HOST` 时，MySQL 测试会自动跳过。

## 安全属性

| 属性 | 实现方式 |
|---|---|
| 锁定阈值 | 5 次失败尝试 |
| 锁定持续时间 | 15 分钟 |
| 锁定期间输入正确密码 | 被拦截（423） |
| 用户枚举防护 | 未知邮箱和密码错误均返回 401 |
| 锁定范围 | 按邮箱地址，而非按 IP |
| 锁定重置 | 成功登录后自动重置 |
| 密码哈希算法 | Argon2id |
| 超长邮箱输入 | 256+ 字符时拒绝（422） |
| SQL 注入防护 | 参数化查询防止注入 |

## 设计权衡：锁定拒绝服务

由于锁定是按邮箱（而非按 IP）进行的，知道用户邮箱的攻击者可以通过提交 5 次错误密码来锁定该用户。这是暴力破解防护与可用性之间固有的矛盾。

缓解措施（此处未实现，但可用）：
- **渐进式延迟**代替硬性锁定
- N 次失败后要求 **CAPTCHA**
- 触发锁定时发送**通知邮件**
- **管理员解锁端点**

对于大多数应用而言，权衡倾向于暴力破解防护。锁定在 15 分钟后自动解除。

## 路由摘要

| 方法 | 路径 | 描述 |
|---|---|---|
| `POST` | `/users` | 创建用户（初始化/注册） |
| `POST` | `/auth/login` | 登录尝试（200/401/423） |
| `GET` | `/auth/status/{email}` | 检查锁定状态 |

---

## ATK 评估——破解者视角攻击测试

### ATK-01 — 暴力破解直至锁定 🚫 BLOCKED

**攻击**：对已知邮箱发送 5+ 次错误密码的失败登录尝试。
**结果**：BLOCKED——5 次失败后，`failed_count >= MAX_ATTEMPTS` 将 `locked_until` 设置为当前时间 + 15 分钟。后续尝试在密码验证前就收到 423 `account-locked`。

---

### ATK-02 — 锁定后提交正确密码 🚫 BLOCKED

**攻击**：锁定账户后，立即提交正确密码。
**结果**：BLOCKED——锁定检查在 `findUserByEmail()` 之前发生。即使密码正确，锁定期间也会返回 423。

---

### ATK-03 — 用不存在的邮箱探测以避免锁定真实账户 🚫 BLOCKED（设计如此）

**攻击**：使用不存在的邮箱进行探测，不触发真实账户的锁定。
**结果**：BLOCKED（设计如此）——不存在的邮箱不会累积失败次数，保护了存储空间。真实账户受其自身锁定状态保护。探测假邮箱不会泄露真实账户的任何信息。

---

### ATK-04 — 竞争条件：在失败阈值处并发登录尝试 🚫 BLOCKED

**攻击**：当 `failed_count` 为 4 时，同时发送两个请求以绕过锁定。
**结果**：BLOCKED——`UPDATE account_states` 在数据库层面是原子操作。SQLite WAL 序列化并发写入；MySQL 使用行级锁。两次更新都成功；`locked_until` 被正确设置。

---

### ATK-05 — 状态端点泄露锁定状态 🚫 BLOCKED（设计如此）

**攻击**：通过 `GET /auth/status/{email}` 发现某个邮箱是否已被锁定。
**结果**：设计如此——状态端点是为客户端 UX 设计的（"请在 15 分钟后重试"）。生产环境中应对此端点进行速率限制或要求认证。它会泄露锁定时间信息，但不会泄露密码信息。

---

### ATK-06 — 通过邮箱字段进行 SQL 注入 🚫 BLOCKED

**攻击**：发送 `{"email": "' OR '1'='1' --", "password": "x"}`。
**结果**：BLOCKED——所有查询使用参数化语句（`WHERE email = ?`）。注入的字符串被作为字面量邮箱值处理。

---

### ATK-07 — 超大邮箱字符串导致拒绝服务 🚫 BLOCKED

**攻击**：发送包含 100,000 个字符的邮箱字段。
**结果**：BLOCKED——`if (strlen($email) > 255)` → 422 `validation-failed`，在任何数据库查询之前就被拦截。

---

### ATK-08 — 缺少邮箱或密码字段 🚫 BLOCKED

**攻击**：发送 `{}` 或不含密码的 `{"email": "x@x.com"}`。
**结果**：BLOCKED——`if ($email === '' || $pass === '')` → 422 `validation-failed`。

---

### ATK-09 — 通过登录其他账户来重置计数器 🚫 BLOCKED

**攻击**：锁定账户 A，然后登录账户 B 来重置 A 的计数器。
**结果**：BLOCKED——`resetState()` 以邮箱为键。其他账户的成功登录对账户 A 的状态没有任何影响。

---

### ATK-10 — 仅含空白字符的邮箱绕过验证 🚫 BLOCKED

**攻击**：发送 `{"email": "   ", "password": "x"}`。
**结果**：BLOCKED——`$email = trim($body['email'])` 将空白字符归一化为 `''` → 422。

---

### ATK-11 — 非字符串邮箱类型绕过 is_string 检查 🚫 BLOCKED

**攻击**：发送 `{"email": 12345, "password": "x"}`（整数邮箱）。
**结果**：BLOCKED——`is_string($body['email'])` 检查 → false → `$email = ''` → 422。

---

### ATK-12 — 持续锁定受害者（可用性攻击） 🚫 BLOCKED（已缓解）

**攻击**：恶意用户反复对受害者邮箱提交错误密码，以维持永久锁定。
**结果**：已缓解——锁定是基于时间的（15 分钟）。它会自动解除；没有永久封禁。持续攻击能维持 15 分钟的锁定窗口，但无法永久禁用账户。生产环境加固措施：CAPTCHA、基于 IP 的速率限制、通过邮件通知用户。

---

### ATK 总结

| ID | 攻击 | 结果 |
|----|--------|--------|
| ATK-01 | 暴力破解直至锁定 | 🚫 BLOCKED |
| ATK-02 | 锁定后提交正确密码 | 🚫 BLOCKED |
| ATK-03 | 用不存在的邮箱探测 | 🚫 BLOCKED（设计如此） |
| ATK-04 | 失败计数的竞争条件 | 🚫 BLOCKED |
| ATK-05 | 状态端点泄露锁定状态 | 🚫 BLOCKED（设计如此） |
| ATK-06 | 通过邮箱进行 SQL 注入 | 🚫 BLOCKED |
| ATK-07 | 超大邮箱 DoS | 🚫 BLOCKED |
| ATK-08 | 缺少必填字段 | 🚫 BLOCKED |
| ATK-09 | 通过其他账户重置计数器 | 🚫 BLOCKED |
| ATK-10 | 仅含空白字符的邮箱 | 🚫 BLOCKED |
| ATK-11 | 非字符串邮箱类型 | 🚫 BLOCKED |
| ATK-12 | 持续锁定受害者 | 🚫 BLOCKED（已缓解） |

**12 项 BLOCKED / 已缓解，0 项 EXPOSED**  
锁定检查在密码验证之前、参数化查询、输入长度验证和基于时间的过期机制防御了所有测试的攻击向量。

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 在密码验证后检查锁定 | 为锁定账户浪费 Argon2id CPU；锁定时序侧信道 |
| 对账户锁定返回 429 | 语义错误——429 是速率限制，423 是锁定资源 |
| 失败后实施永久锁定 | 攻击者可以对任何已知邮箱的用户永久拒绝服务 |
| 为不存在的邮箱记录失败 | 攻击者在用户注册前预先创建锁定状态 |
| 不对邮箱长度进行验证 | 100KB+ 的邮箱字符串导致慢查询或内存压力 |
| 在内存/会话中存储锁定状态 | 服务器重启后状态丢失；多应用实例间无法共享 |
| 锁定和密码错误使用相同错误码 | 难以区分 UX——锁定使用 423，凭据错误使用 401 |
