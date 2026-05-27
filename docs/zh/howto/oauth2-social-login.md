# OAuth2 社交登录实现指南

## 概述

本指南说明如何使用 NENE2 实现基于 OAuth2 Authorization Code Flow 的社交登录。包含 CSRF 防护（state 参数）、授权码重放防护、会话失效以及破解者攻击测试（ATK-01〜12）。

---

## 数据库结构

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    provider   TEXT    NOT NULL,
    subject    TEXT    NOT NULL,  -- OAuth 提供商发放的用户标识符
    name       TEXT    NOT NULL,
    email      TEXT,
    created_at TEXT    NOT NULL,
    UNIQUE (provider, subject)
);

CREATE TABLE oauth_states (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    state      TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL,
    expires_at TEXT    NOT NULL,
    used_at    TEXT    -- NULL = 未使用, NOT NULL = 已使用（不可重复使用）
);

CREATE TABLE sessions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token      TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL,
    expires_at TEXT    NOT NULL,
    revoked_at TEXT,   -- NULL = 有效, NOT NULL = 已注销
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE used_oauth_codes (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    code     TEXT    NOT NULL UNIQUE,
    used_at  TEXT    NOT NULL
);
```

`oauth_states.used_at` 和 `used_oauth_codes` 是**防止 CSRF 和授权码重放攻击**的核心。

---

## 端点设计

| 方法 | 路径 | 描述 |
|---|---|---|
| POST | `/auth/oauth/start` | 生成 state，返回授权 URL |
| POST | `/auth/oauth/callback` | 验证 state/code，创建用户，签发会话 |
| POST | `/auth/logout` | 使会话失效 |
| GET | `/me` | 获取已认证用户信息 |

---

## Authorization Code Flow

```
Client                 Server                   OAuth Provider
  |                      |                            |
  |-- POST /start -----→ |                            |
  |← {state, auth_url} --|                            |
  |                      |                            |
  |-- 用户访问 auth_url →→→→→→→→→→→→→→→→→→→→→→→→→|
  |←←←←←←←←←←←←←←←←←←← redirect with ?code=XXX&state=YYY |
  |                      |                            |
  |-- POST /callback ──→ |                            |
  |   {state, code}      |-- code exchange →→→→→→→→ |
  |                      |← {subject, name, email} ---|
  |← {token, user} -----.|                            |
  |                      |                            |
  |-- GET /me ─────────→ |                            |
  |   Authorization: Bearer <token>                   |
  |← {id, name, email} - |                            |
```

---

## 设计要点

### CSRF 防护（state 参数）

OAuth2 回调通过 URL 参数传递，攻击者可以诱导受害者访问恶意回调 URL（CSRF）。使用 `state` 防护：

1. 在 `/auth/oauth/start` 中将随机 state 保存到 DB
2. 在回调中验证 state
3. **将已使用的 state 标记为不可重用**（记录 `used_at`）

```php
if (!$this->repo->isStateValid($state, $now)) {
    return $this->json->create(['error' => 'Invalid, expired, or already used state'], 400);
}
```

### 授权码重放防护

Authorization Code 只能使用一次（RFC 6749 §4.1.2）。使用 `used_oauth_codes` 表记录已使用的授权码，拒绝重复使用：

```php
if ($this->repo->isCodeUsed($code)) {
    return $this->json->create(['error' => 'Authorization code already used'], 400);
}
// ... 提供商验证 ...
$this->repo->markCodeUsed($code, $now);
```

### state 与 code 的消费顺序

验证 state → 验证 code → **向提供商查询 → 同时标记 state 和 code 为已使用**。如果提供商失败，state 和 code 都不消费（可以重试）。

### Bearer 令牌认证

```php
private function bearerToken(ServerRequestInterface $request): ?string
{
    $header = $request->getHeaderLine('Authorization');
    if (!str_starts_with($header, 'Bearer ')) {
        return null;
    }
    return substr($header, 7) ?: null;
}
```

### 用户 Upsert

同一提供商的同一 subject 再次登录时，更新现有用户：

```php
public function upsertUser(array $info, string $now): int
{
    $row = $this->db->fetchOne(
        'SELECT id FROM users WHERE provider = ? AND subject = ?',
        [$info['provider'], $info['subject']],
    );
    if ($row !== null) {
        // 更新名称和邮箱到最新值
        $this->db->insert('UPDATE users SET name = ?, email = ? WHERE id = ?', [...]);
        return (int) $row['id'];
    }
    return $this->db->insert('INSERT INTO users ...', [...]);
}
```

### state 有效期

state 有效期为 5 分钟。过期的 state 通过 `expires_at > $now` 检查拒绝：

```php
public function isStateValid(string $state, string $now): bool
{
    $row = $this->findState($state);
    if ($row === null || $row['used_at'] !== null) return false;
    return (string) $row['expires_at'] > $now;
}
```

---

## 破解者攻击测试 ATK-01〜12（全部通过）

| # | 攻击场景 | 对策 | 预期状态码 |
|---|---|---|---|
| ATK-01 | CSRF：缺少 state 参数 | required 校验 | 422 |
| ATK-02 | CSRF：伪造 state 值 | DB 匹配 → 拒绝未知 state | 400 |
| ATK-03 | 重复使用已用 state | 记录 `used_at` 后不可重用 | 400 |
| ATK-04 | 横取正规 state 重用 | 使用一次后立即失效 | 400 |
| ATK-05 | 授权码重放 | 通过 `used_oauth_codes` 记录 | 400 |
| ATK-06 | 无效授权码 | 模拟提供商返回 null | 401 |
| ATK-07 | 开放重定向注入 | start 不接受 redirect_uri | auth_url 中不含恶意域名 |
| ATK-08 | 注销后会话重用 | 设置 `revoked_at` → findSession 失败 | 401 |
| ATK-09 | 无效会话令牌 | DB 匹配 → 拒绝未注册 token | 401 |
| ATK-10 | 未认证访问 /me | 未设置 Bearer → 401 | 401 |
| ATK-11 | state 参数中的 SQL 注入 | prepared statement 使其无效 | 400/422 |
| ATK-12 | 使用其他用户的会话访问 /me | token 与 user_id 绑定 | 不同的 user.id |

---

## 测试结构

```
tests/
  OAuth/
    OAuthTest.php   — 功能测试 10 件
    AttackTest.php  — 破解者攻击测试 12 件（ATK-01〜12）
```

共 22 个测试 / 36 个断言。

---

## 参考实现

`../NENE2-FT/oauthlog/` — FT160 字段试验（22 个测试 + 破解者攻击测试 12 件）
