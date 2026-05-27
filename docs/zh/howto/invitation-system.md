# 操作指南：邀请系统

> **FT 参考**：FT283（`NENE2-FT/invitelog`）——邀请码系统：32 字符十六进制令牌（128 位熵），ISO 8601 日期时间校验，pending→used 状态生命周期，match 表达式状态映射，IDOR 防护的邀请列表，23 个测试 / 47 个断言全部通过。

本指南展示如何构建安全的邀请系统——生成一次性使用的令牌，兑换后可获得访问权限。

## 使用场景

用户创建邀请链接（令牌）并分享。收件人兑换令牌加入系统。每个令牌只能使用一次，且有时间限制。

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS invitations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    token       TEXT    NOT NULL UNIQUE,
    inviter_id  INTEGER NOT NULL,
    invitee_id  INTEGER,
    status      TEXT    NOT NULL DEFAULT 'pending',
    expires_at  TEXT    NOT NULL,
    used_at     TEXT,
    created_at  TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_invitations_token ON invitations (token);
CREATE INDEX IF NOT EXISTS idx_invitations_inviter ON invitations (inviter_id, id DESC);
```

关键点：
- `token TEXT UNIQUE` — 在 DB 层面确保每行一个令牌
- `invitee_id` 兑换前为 `NULL`
- `status` — `'pending'` | `'used'`
- `used_at` — 兑换时设置，提供审计时间戳

## 端点

| 方法 | 路径 | 认证 | 描述 |
|------|------|------|------|
| `POST` | `/invitations` | `X-User-Id` | 创建邀请 |
| `GET` | `/invitations/{token}` | 无 | 通过令牌查找邀请 |
| `POST` | `/invitations/{token}/use` | `X-User-Id` | 兑换邀请 |
| `GET` | `/users/{userId}/invitations` | `X-User-Id`（仅自己） | 列出用户的邀请 |

## 令牌生成

```php
/** Token: 32 个小写十六进制字符（16 个随机字节 = 128 位熵） */
public const string TOKEN_PATTERN = '/\A[0-9a-f]{32}\z/';

$token = bin2hex(random_bytes(16));
```

`random_bytes(16)` 生成 128 位密码学安全随机数据。十六进制表示为 32 个字符。这与 UUID v4 的熵级别相同（可用 122 位）。

## expires_at 校验

```php
private const string ISO_DATE_PATTERN = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/';

$expiresAt = trim((string) ($body['expires_at'] ?? ''));
if (!preg_match(self::ISO_DATE_PATTERN, $expiresAt)) {
    return $this->problem(422, 'validation-failed', 'expires_at must be ISO 8601 datetime.');
}
```

正则仅验证格式。时区处理（UTC 与本地时间）由应用程序负责——使用一致的时区（例如 UTC）可避免边界情况。

## 状态生命周期

```
pending → used（单向，不可逆）
```

只有 `pending` 状态的邀请才能被兑换。一旦使用，邀请将被永久消费。

## 使用 match 表达式兑换

```php
$result = $this->repo->use($token, $uid);

return match ($result) {
    'not_found'    => $this->problem(404, 'not-found', 'Invitation not found.'),
    'already_used' => $this->problem(409, 'conflict', 'Invitation already used.'),
    'expired'      => $this->problem(409, 'conflict', 'Invitation has expired.'),
    default        => $this->json(['message' => 'Invitation accepted.']),
};
```

`match` 是穷举的（不像 `switch`）：无穿透，必须处理所有情况。数据仓库返回字符串结果类型；处理器将其整洁地映射到 HTTP 响应。

## 数据仓库——原子兑换

```php
/** @return 'ok'|'not_found'|'already_used'|'expired' */
public function use(string $token, int $inviteeId): string
{
    $inv = $this->findByToken($token);
    if ($inv === null) {
        return 'not_found';
    }
    if ($inv['status'] === 'used') {
        return 'already_used';
    }
    // 检查过期
    $now = $this->now();
    if ($inv['expires_at'] < $now) {
        return 'expired';
    }

    // 标记为已使用
    $this->pdo->prepare('UPDATE invitations SET status = \'used\', invitee_id = ?, used_at = ? WHERE token = ?')
        ->execute([$inviteeId, $now, $token]);

    return 'ok';
}
```

对于同一令牌的并发兑换，检查后更新的序列是潜在的 TOCTOU 竞态。生产环境应使用 DB 级事务或 `UPDATE WHERE status = 'pending'` 并检查受影响的行数。

## IDOR——邀请列表

只有邀请者才能查看自己的邀请：

```php
$callerUid = $this->uid($req);
if ($callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

返回 404（而非 403）以隐藏目标用户是否存在。

## X-User-Id 请求头校验

```php
private function uid(ServerRequestInterface $req): ?int
{
    $raw = $req->getHeaderLine('X-User-Id');
    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) {
        return null;
    }
    $id = (int) $raw;
    return $id > 0 ? $id : null;
}
```

`ctype_digit()` 是 ReDoS 安全的；`strlen > 18` 防止 64 位 PHP 整数溢出；`> 0` 拒绝用户 ID 0。

---

## 反模式

| 反模式 | 风险 |
|--------|------|
| 使用短/顺序令牌（4 位 PIN） | 可在毫秒内暴力破解；使用 ≥128 位随机数 |
| 存储令牌时不加 `UNIQUE` 约束 | 令牌碰撞导致兑换混乱 |
| 用宽松比较检查 `status === 'pending'` | PHP `'0' == false`；始终使用严格 `===` |
| 兑换时不校验过期 | 过期邀请永远可被兑换 |
| IDOR 检查返回 403 | 暴露目标用户存在；用 404 隐藏枚举 |
| 原子兑换不使用事务 | 并发请求都能看到 `pending` 并都成功——重复兑换 |
| 用软删除（`deleted_at`）代替状态列 | 状态列更自文档化；`pending`/`used` 比 null/非 null 更清晰 |
| 接受任意字符串作为 `expires_at` | 不参数化时可被 SQL 注入；使用参数化查询 + 格式校验 |
| 过期令牌重置状态为 `pending` | 允许合法过期的令牌被重复使用 |
