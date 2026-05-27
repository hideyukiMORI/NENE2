# PIN 认证与锁定

> **FT 参考**：FT252（`NENE2-FT/pinverifylog`）——带锁定的 PIN 验证
> **ATK**：FT252——破解者思维攻击测试（ATK-01 到 ATK-12）

6 位 PIN 的暴力破解防护、时序攻击对策以及管理员解锁的实现指南。解说 HMAC-SHA256 哈希存储、恒定时间比较以及尝试次数锁定。

**FT192 安全性已验证**：VULN-A~L 全部通过 / ATK-01~12 全部通过。

## 概述

- 管理员创建 PIN（HMAC-SHA256 哈希存储——不存储明文）
- 用户验证 PIN（失败次数超过上限则锁定）
- 管理员解除锁定
- 尝试历史记录为审计日志

## 端点

| 方法 | 路径 | 认证 | 描述 |
|---|---|---|---|
| `POST` | `/pins` | `X-Admin-Key` | 创建 PIN |
| `POST` | `/pins/{id}/verify` | — | 验证 PIN |
| `GET` | `/pins/{id}` | `X-Admin-Key` | 状态确认（剩余尝试次数・锁定期限） |
| `POST` | `/pins/{id}/unlock` | `X-Admin-Key` | 解除锁定 |
| `DELETE` | `/pins/{id}` | `X-Admin-Key` | 删除 PIN |

## 数据库设计

```sql
CREATE TABLE IF NOT EXISTS pins (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    label        TEXT    NOT NULL,
    pin_hash     TEXT    NOT NULL,        -- HMAC-SHA256(pin, secret)
    attempts     INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 5,
    locked_until TEXT,                    -- ISO 8601 UTC，NULL = 未锁定
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS pin_attempts (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    pin_id       INTEGER NOT NULL,
    success      INTEGER NOT NULL DEFAULT 0,
    attempted_at TEXT    NOT NULL
);
```

`locked_until` 以 ISO 8601 字符串存储，通过与当前时间的字符串比较（`$lockedUntil > $now`）判断锁定状态。无转换开销。

## HMAC-SHA256 PIN 哈希

PIN 不以明文存储，使用 HMAC-SHA256 进行哈希。混入服务器端密钥（`$hmacSecret`）使 DB 泄露时的暴力破解更加困难：

```php
private function hashPin(string $pin): string
{
    return hash_hmac('sha256', $pin, $this->hmacSecret);
}
```

## 恒定时间比较（VULN-E / ATK-02）

`===` 在字节比较中途会短路，通过时序攻击可以推测正确的哈希。`hash_equals()` 始终比较所有字节：

```php
// ❌ 危险：时序攻击可推测
if ($stored === $provided) { ... }

// ✅ 安全：恒定时间比较
$provided = $this->hashPin($pin);
$success  = hash_equals($pin1->pinHash, $provided);
```

## 暴力破解防护（ATK-01）

失败次数达到 `max_attempts` 时设置 `locked_until`，此后拒绝所有尝试（包括正确的 PIN），返回 423：

```php
public function verify(int $id, string $pin): string
{
    $now  = $this->now();
    $pin1 = $this->findById($id);

    // 1. 在尝试之前先检查锁定
    if ($pin1->isLocked($now)) {
        return 'locked'; // → 423
    }

    // 2. 恒定时间比较
    $provided = $this->hashPin($pin);
    $success  = hash_equals($pin1->pinHash, $provided);

    if ($success) {
        // 成功后重置尝试次数
        $this->resetAttempts($id, $now);
        return 'success'; // → 200
    }

    // 3. 失败：计数增加 → 达到上限时锁定
    $newAttempts = $pin1->attempts + 1;
    $lockedUntil = null;

    if ($newAttempts >= $pin1->maxAttempts) {
        $lockedUntil = $this->lockUntil($now); // 5 分钟后
    }

    $this->incrementAttempts($id, $newAttempts, $lockedUntil, $now);

    return $newAttempts >= $pin1->maxAttempts ? 'locked' : 'wrong'; // → 423 或 401
}
```

**重要**：锁定检查必须在尝试之前进行。如果在尝试之后检查，到达锁定状态的最后一次尝试可能会通过。

## 管理员密钥故障关闭（VULN-H / ATK-03）

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    if ($this->adminKey === '') {
        return false; // 空 adminKey 始终拒绝
    }

    $provided = $request->getHeaderLine('X-Admin-Key');

    return $provided !== '' && hash_equals($this->adminKey, $provided);
}
```

`adminKey` 为空字符串时无条件返回 `false`（防止环境变量未设置时成为开放管理员）。

## ID 校验（VULN-A / ATK-07）

```php
private function resolveId(ServerRequestInterface $request): ?int
{
    $raw = Router::param($request, 'id');

    if ($raw === null || !ctype_digit($raw) || strlen($raw) > 18) {
        return null; // → 422
    }

    $id = (int) $raw;

    return $id > 0 ? $id : null;
}
```

`strlen($raw) > 18` 防止 64 位整数溢出（`PHP_INT_MAX` 是 19 位，留有安全余量）。

## PIN 校验（VULN-D）

使用 `ctype_digit()`。正则表达式（`/^[0-9]+$/`）可能存在 ReDoS 风险，最坏情况为 O(n²)，而 `ctype_digit()` 是 O(n) 且安全：

```php
private function validatePin(mixed $pin): ?string
{
    if (!is_string($pin)) {
        return 'pin must be a string.'; // VULN-G：防止类型混淆
    }

    $len = strlen($pin);
    if ($len < self::MIN_PIN_LEN || $len > self::MAX_PIN_LEN) {
        return 'pin must be between 4 and 8 digits.';
    }

    if (!ctype_digit($pin)) { // O(n)，无 ReDoS
        return 'pin must contain only digits.';
    }

    return null;
}
```

## 响应设计

**PIN 哈希绝对不能包含在响应中。** 管理员响应也不例外：

```php
public function toAdminArray(): array
{
    return [
        'id'                 => $this->id,
        'label'              => $this->label,
        'attempts'           => $this->attempts,
        'max_attempts'       => $this->maxAttempts,
        'locked_until'       => $this->lockedUntil,
        'remaining_attempts' => $this->remainingAttempts(),
        'created_at'         => $this->createdAt,
        // 不包含 pin_hash
        // 不包含 updated_at（内部信息）
    ];
}
```

## 响应示例

```json
// POST /pins (201)
{
    "pin": {
        "id": 1,
        "label": "vault",
        "attempts": 0,
        "max_attempts": 5,
        "locked_until": null,
        "remaining_attempts": 5,
        "created_at": "2026-05-26T10:00:00+00:00"
    }
}

// POST /pins/1/verify — 成功 (200)
{ "success": true, "locked": false }

// POST /pins/1/verify — 失败 (401)
{ "success": false, "locked": false }

// POST /pins/1/verify — 已锁定 (423)
{ "success": false, "locked": true, "error": "PIN is locked due to too many failed attempts." }

// POST /pins/1/unlock (200)
{ "unlocked": true }
```

## 安全要点（VULN-A~L / ATK-01~12 全部通过）

| 威胁 | 类别 | 对策 |
|---|---|---|
| 暴力破解 | ATK-01 | `max_attempts` 上限 → `locked_until` 锁定 5 分钟 |
| 时序攻击（PIN） | ATK-02 / VULN-E | `hash_equals()` 恒定时间比较 |
| 管理员密钥绕过 | ATK-03 / VULN-H | `adminKey = ''` → false（故障关闭） |
| ID 枚举 | ATK-04 | 不存在的 ID 返回 404（不泄露信息） |
| SQL 注入（PIN 值） | ATK-05 / VULN-B | `ctype_digit` 只允许数字通过 → PDO 预处理语句 |
| SQL 注入（ID） | ATK-06 / VULN-B | `ctype_digit + strlen > 18` 守护 → 422 |
| 整数溢出 | ATK-07 / VULN-A / VULN-J | `strlen > 18` 守护 |
| 锁定绕过 | ATK-08 | 锁定检查在尝试之前、DB 持久化 |
| 解锁后重新攻击 | ATK-09 | 解锁后 attempts = 0 重置（正常行为） |
| 请求体注入 | ATK-10 / VULN-I | 只接受明确字段 |
| 管理员密钥时序 | ATK-11 | `hash_equals()` 恒定时间比较 |
| BIDI/Unicode 标签 | ATK-12 / VULN-L | `mb_strlen` 长度检查，存储通过 PDO 安全进行 |
| ReDoS | VULN-D | `ctype_digit()` O(n)，无正则表达式 |
| 类型混淆 | VULN-G | `!is_string($pin)` 检查 |
| max_attempts 溢出 | VULN-F | 范围检查 1~20 |
| SSRF | VULN-K | 无外部 HTTP 通信（不适用） |
| 路径遍历 | VULN-C | 无文件操作（不适用） |

## 相关指南

- [账户锁定](account-lockout.md) — 每账户失败计数・423 设计
- [OTP 认证系统](otp-authentication.md) — 相同的锁定模式（只有最新 OTP 有效）
- [Webhook 签名验证](webhook-signature.md) — `hash_equals()` 模式
- [数字验证码](numeric-verification-code.md) — 6 位验证码的生成・验证流程
