# 操作指南：Session/令牌管理 API（ATK-01~12）

本指南演示一个覆盖所有 ATK-01~12 攻击者思维攻击向量的安全 session 令牌 API。

## 模式概述

- `POST /sessions` — 为用户签发新的不透明令牌（需要 `X-User-Id`）。
- `GET /sessions/{token}` — 校验令牌（已吊销或已过期返回 404）。
- `DELETE /sessions/{token}` — 吊销令牌（所有者或管理员）。
- `GET /users/{userId}/sessions` — 列出活跃 session（所有者或管理员）。

令牌为 `bin2hex(random_bytes(32))`——64 个小写十六进制字符。

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS sessions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token      TEXT    NOT NULL UNIQUE,
    label      TEXT    NOT NULL DEFAULT '',
    revoked    INTEGER NOT NULL DEFAULT 0,
    expires_at TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions (token);
CREATE INDEX IF NOT EXISTS idx_sessions_user  ON sessions (user_id, revoked);
```

## 令牌生成

```php
$token = bin2hex(random_bytes(32));  // 64 个小写十六进制字符
```

`random_bytes()` 使用 CSPRNG；令牌不可猜测且非顺序。

## 令牌格式校验

在任何 DB 查询之前，使用严格正则校验令牌格式：

```php
private const string TOKEN_PATTERN = '/\A[0-9a-f]{64}\z/';

private function pathToken(ServerRequestInterface $req): ?string
{
    $token = $params['token'] ?? '';
    if (!preg_match(self::TOKEN_PATTERN, $token)) {
        return null;  // 404 — 永远不会触及 DB
    }
    return $token;
}
```

在任何 DB 查询之前，这会拒绝 SQL 注入负载、超大输入、大写十六进制以及非十六进制字符串。

## ATK-01：令牌路径中的 SQL 注入

令牌格式正则立即拒绝 `' OR '1'='1`。即使通过了，DB 查询也使用预处理语句：

```php
$stmt = $this->pdo->prepare('SELECT * FROM sessions WHERE token = :token');
$stmt->execute([':token' => $token]);
```

## ATK-02~04：令牌格式攻击

全部被正则 `/\A[0-9a-f]{64}\z/` 拒绝：
- 空字符串（长度 0 ≠ 64）
- 超大字符串（256 个字符 ≠ 64）
- 非十六进制字符（`g`、大写 `A`-`F`、特殊字符）
- 错误长度（63 或 65 个字符）

## ATK-05：X-User-Id 中的整数溢出

```php
if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
    return null;
}
```

19 位整数超出 18 个字符限制，在任何 `(int)` 转换之前被拒绝。

## ATK-06：负数/零用户 ID

```php
$id = (int) $raw;
return $id > 0 ? $id : null;
```

`0` 和负值返回 `null`，触发 400。

## ATK-07：管理员密钥故障关闭

空的 `adminKey` 永远不会授予管理员权限：

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

`hash_equals()` 在比较密钥时防止时序攻击。

## ATK-08：通过 X-User-Id: 0 绕过认证

`uid()` 对 ID=0 返回 `null` → 400，而不是 200。

## ATK-09：列表路径中的非数字用户 ID

`ctype_digit()` 拒绝 `abc`、`1.5`、`-1`：

```php
if (!ctype_digit($raw) || strlen($raw) > 18) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

## ATK-10：浮点数 TTL

`is_int()` 是 PHP 的严格类型检查——`60.5` 返回 `false`：

```php
if (!is_int($ttlRaw) || $ttlRaw < 1 || $ttlRaw > self::MAX_TTL) {
    return $this->problem(422, ...);
}
```

## ATK-11：双重吊销返回 404

repository 在更新前检查 `revoked === 1`：

```php
if ($session === null || (int) $session['revoked'] === 1) {
    return false;
}
```

已被吊销的 session 无法通过重新发送 DELETE 来"取消吊销"。

## ATK-12：暴力破解令牌格式拒绝

任何不完全匹配 64 个小写十六进制字符的令牌在触及数据库之前就以 404 被拒绝。暴力破解尝试撞到的是正则墙，而不是 DB。

## IDOR：所有者与管理员

- 吊销或列出其他用户 session 的非所有者得到 404（而非 403）。
- 管理员使用 `X-Admin-Key` 请求头；未配置密钥时故障关闭。

## 参见

- FT208 源码：`../NENE2-FT/sessionlog/`
- 相关：`docs/howto/rate-limiting.md`（FT200，ATK）
- 相关：`docs/howto/coupon-redemption.md`（FT204，VULN + ATK）
