# TOTP 双因素认证实现指南

## 概述

本指南说明如何使用 NENE2 实现 RFC 6238 TOTP（基于时间的一次性密码）双因素认证。
提供与 Google Authenticator、Authy 兼容的密钥生成、代码验证、重放攻击防护和暴力破解锁定。

---

## 数据库结构

```sql
CREATE TABLE totp_secrets (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         INTEGER NOT NULL UNIQUE,
    secret          TEXT    NOT NULL,
    is_enabled      INTEGER NOT NULL DEFAULT 0,
    failed_attempts INTEGER NOT NULL DEFAULT 0,
    locked_until    TEXT,
    created_at      TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE used_totp_steps (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    time_step  INTEGER NOT NULL,
    used_at    TEXT    NOT NULL,
    UNIQUE (user_id, time_step),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`used_totp_steps` 表是**重放攻击防护**的核心。记录已使用的时间步骤。

---

## 端点设计

| 方法 | 路径 | 说明 |
|---|---|---|
| POST | `/users/{id}/totp/setup` | 生成 TOTP 密钥（返回后注册到 Authenticator 应用） |
| POST | `/users/{id}/totp/enable` | 验证代码并启用 2FA |
| POST | `/users/{id}/totp/verify` | 验证代码（登录流程） |
| DELETE | `/users/{id}/totp` | 禁用 2FA（需要有效代码） |
| GET | `/users/{id}/totp` | 获取 2FA 状态 |

---

## 框架内置原语（推荐）

自 v1.5 起，风险较高的加密部分可直接使用框架提供的 `Nene2\Auth\TotpAuthenticator` 和
`Nene2\Auth\RecoveryCodes`（ADR 0009 的稳定公开 API）。无需在每个应用中重新实现
RFC 6238 计算、Base32 和常量时间比较。
与 `SecureTokenHelper` 相同的分工：风险较高的加密由框架提供唯一实现，而注册/挑战的 HTTP 流程、
强制策略、密钥的静态加密以及重放防护的持久化则由应用负责。

```php
use Nene2\Auth\TotpAuthenticator;
use Nene2\Auth\RecoveryCodes;

$totp = new TotpAuthenticator();          // digits=6 / period=30 / sha1 / window=1

// 注册：生成密钥 → 用于二维码的 otpauth URI
$secret = $totp->generateSecret();
$uri    = $totp->provisioningUri($secret, 'alice@example.com', 'NENE2');

// 验证：返回匹配的 time_step（null 表示无效）。重放防护通过持久化 step 来判定。
$step = $totp->verify($secret, $submittedCode);
if ($step === null || $repo->isStepUsed($userId, $step)) {
    // 无效或重放
} else {
    $repo->markStepUsed($userId, $step);  // 记录已使用的 step（仅一次有效）
}

// 恢复码：生成后仅显示一次，只保存 hash，兑换时先 verify 再 consume
$codes = RecoveryCodes::generate();       // 例如 ["3f9ac-1b0e7-8d2f4-0a6c1", ...]（默认 80 位）
$repo->storeRecoveryHash($userId, RecoveryCodes::hash($codes[0]));
```

以下各节讲解这些原语在内部执行的计算与安全设计，供自行实现或加深理解时参考。

---

## RFC 6238 TOTP 实现

```php
class TotpGenerator
{
    private const int DIGITS = 6;
    private const int PERIOD = 30; // 秒

    public function computeCode(string $base32Secret, int $timeStep): string
    {
        $secret = $this->base32Decode($base32Secret);

        // 将时间步骤打包为 8 字节大端序
        $msg = pack('N*', 0) . pack('N*', $timeStep);
        $hash = hash_hmac('sha1', $msg, $secret, true);

        // 动态截断（RFC 4226 §5.4）
        $offset = ord($hash[19]) & 0x0F;
        $code = ((ord($hash[$offset]) & 0x7F) << 24)
              | ((ord($hash[$offset + 1]) & 0xFF) << 16)
              | ((ord($hash[$offset + 2]) & 0xFF) << 8)
              | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($code % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public function verify(string $base32Secret, string $code, int $window = 1): ?int
    {
        $t = (int) floor(time() / self::PERIOD);
        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $t + $offset;
            $expected = $this->computeCode($base32Secret, $step);
            if (hash_equals($expected, $code)) {   // 防止时序攻击
                return $step;
            }
        }
        return null;
    }
}
```

---

## 设计要点

### 重放攻击防护

TOTP 代码在 30 秒内有效。相同代码被使用两次就可能被冒用。
通过 `used_totp_steps` 表记录已使用的 time_step，拒绝重复使用。

```php
$matchedStep = $this->totp->verify($secret, $code);
if ($matchedStep === null) {
    // 代码无效
    return 401;
}
if ($this->repo->isStepUsed($userId, $matchedStep)) {
    // 相同 time_step 的代码已被使用 → 重放攻击
    return 401;
}
// 记录为已使用
$this->repo->markStepUsed($userId, $matchedStep, $now);
```

### 时序攻击防护

TOTP 代码的比较使用 `hash_equals()`。`===` 或 `strcmp()` 会提前终止字符串比较，导致从响应时间推断出匹配位数。

```php
// 不安全：易受时序攻击
if ($expected === $inputCode) { ... }

// 安全：恒定时间比较
if (hash_equals($expected, $inputCode)) { ... }
```

### 时间窗口（时钟偏差容忍）

`window = 1` 允许当前步骤 ± 1（= ±30 秒）。
智能手机的时钟偏差几乎都在此范围内。
扩大窗口会降低安全性，推荐值为 1。

### 暴力破解锁定

3 次失败后锁定 15 分钟（423 Locked）。
锁定期间即使输入正确代码也会被拒绝（防止时序预测）：

```php
if ($this->repo->isLocked($userId, $now)) {
    return 423; // 已锁定 — 不检查代码是否正确
}
```

### 设置流程

1. `POST /users/{id}/totp/setup` 生成密钥
2. 将响应中的 `secret`（Base32）或 `otpauth_uri` 注册到 Authenticator 应用
3. `POST /users/{id}/totp/enable` 验证首次代码并激活
4. 激活前密钥保存在 DB 中但 `is_enabled = false`

```
otpauth://totp/NENE2:alice?secret=JBSWY3DPEHPK3PXP&issuer=NENE2&algorithm=SHA1&digits=6&period=30
```

### 重新设置使旧密钥失效

再次调用 `POST /users/{id}/totp/setup` 会覆盖旧密钥，
`used_totp_steps` 也会被删除。旧密钥生成的代码将无法认证。

---

## 安全检查清单（12 项漏洞诊断全部通过）

| # | 检查项 | 对策 |
|---|---|---|
| A | 重放攻击 | 通过 `used_totp_steps` 记录已使用的 time_step |
| B | 暴力破解 | 3 次失败后锁定 15 分钟（423） |
| C | 锁定期间的正确代码 | 优先进行锁定判断，完全不执行代码验证 |
| D | 非法禁用 2FA | DELETE 也需要有效代码 |
| E | 非法启用 2FA | enable 必须进行代码验证 |
| F | 旧密钥被利用 | 重新设置时删除旧密钥和已使用步骤 |
| G | IDOR | 代码使用每个用户独立的 secret 进行验证 |
| H | 密钥泄露 | verify/enable 响应中不包含 secret |
| I | 格式错误的代码 | 不匹配 → 401（格式验证可选） |
| J | 空代码 | required 验证返回 422 |
| K | 未启用时进行 verify | 通过 `is_enabled` 检查返回 409 |
| L | 不存在的用户 | findUser() → null → 404 |

---

## 测试注意事项

由于 TOTP 代码依赖时间，连续使用相同 time_step 的代码会被视为重放。
测试中使用 `TotpGenerator::computeCode($secret, $gen->currentTimeStep() + N)` 生成不同步骤的代码：

```php
$enableCode  = $gen->computeCode($secret, $gen->currentTimeStep());     // 用于 enable
$verifyCode  = $gen->computeCode($secret, $gen->currentTimeStep() + 1); // 用于 verify
$disableCode = $gen->computeCode($secret, $gen->currentTimeStep() + 2); // 用于 disable
```

---

## 参考实现

`../NENE2-FT/totplog/` — FT159 现场试验（21 项测试 + 12 项漏洞诊断 = 32 项测试）
