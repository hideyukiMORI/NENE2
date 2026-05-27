# 操作指南：邀请/推荐 API

本指南展示如何使用 NENE2 构建带过期时间和一次性使用功能的基于令牌的邀请系统。
模式由 **invitelog** 字段试验（FT221）演示。

## 功能特性

- 生成邀请令牌（`bin2hex(random_bytes(16))` = 32 个小写十六进制字符）
- 设置每个邀请的过期日期（ISO 8601）
- 接受/使用邀请（一次性，记录被邀请人）
- 用户范围的邀请列表（IDOR：只有自己可以查看）
- 状态生命周期：`pending → used`（使用时检测是否过期）

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
```

## 端点

| 方法 | 路径 | 认证 | 描述 |
|------|------|------|------|
| `POST` | `/invitations` | 用户 | 创建邀请（返回令牌） |
| `GET` | `/invitations/{token}` | 公开（令牌即密钥） | 获取邀请状态 |
| `POST` | `/invitations/{token}/use` | 用户 | 接受邀请 |
| `GET` | `/users/{userId}/invitations` | 用户（仅自己） | 列出自己的邀请 |

## 令牌生成

```php
$token = bin2hex(random_bytes(16)); // 32 个小写十六进制字符，密码学安全
```

路径参数中的令牌格式校验：

```php
/** Token: 32 个小写十六进制字符（16 个随机字节） */
public const string TOKEN_PATTERN = '/\A[0-9a-f]{32}\z/';
```

## 一次性使用逻辑

```php
/** @return 'ok'|'not_found'|'already_used'|'expired' */
public function use(string $token, int $inviteeId): string
{
    $inv = $this->findByToken($token);
    if ($inv === null) return 'not_found';
    if ($inv['status'] === 'used') return 'already_used'; // → 409
    if ($inv['expires_at'] < $this->now()) return 'expired'; // → 409

    // 标记为已使用并记录被邀请人
    $this->pdo->prepare(
        "UPDATE invitations SET status = 'used', invitee_id = :iid, used_at = :now WHERE token = :token"
    )->execute([...]);

    return 'ok';
}
```

## IDOR 防护

邀请列表端点强制执行仅自己可访问：

```php
$callerUid = $this->uid($req);
if ($callerUid !== $targetUid) {
    return $this->problem(404, 'not-found', 'User not found.');
}
```

`GET /invitations/{token}` 端点将令牌本身作为密钥——知晓令牌即可访问。这是"令牌即能力"模式。

## 安全模式

- **`bin2hex(random_bytes(16))`**：密码学安全，128 位熵令牌
- **令牌格式校验**：`/\A[0-9a-f]{32}\z/`——拦截 SQL 注入和超大令牌
- **`ctype_digit()`**：用户 ID 路径参数的 ReDoS 安全整数校验
- **ISO 8601 过期校验**：正则模式 + 字典序比较（UTC）
- **使用时检查过期**：不预先过滤——令牌查找返回结果后再检查过期
