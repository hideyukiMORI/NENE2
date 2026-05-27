# 操作指南：签名 URL 安全下载

> **FT 参考**：FT338（`NENE2-FT/signedlog`）— HMAC-SHA256 签名 URL 生成（带 TTL）、篡改检测（401）、过期检测（410 Gone）、资源绑定令牌、错误密钥拒绝，16 tests / 40+ assertions 全部 PASS。

本指南展示如何生成有时间限制的签名 URL，允许未认证用户下载私有文件——而无需暴露长期有效的凭据。

## 数据库结构

```sql
CREATE TABLE files (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    owner_id   INTEGER NOT NULL,
    mime_type  TEXT    NOT NULL DEFAULT 'application/octet-stream',
    created_at TEXT    NOT NULL
);
```

## 端点

| 方法 | 路径 | 说明 |
|------|------|------|
| `POST` | `/files` | 注册文件记录 |
| `POST` | `/files/{id}/sign` | 生成签名下载 URL |
| `GET` | `/download?token=...` | 使用签名令牌下载 |

## 注册文件

```php
POST /files
{"name": "report.pdf", "owner_id": 1}
→ 201
{
  "id": 1,
  "name": "report.pdf",
  "owner_id": 1,
  "mime_type": "application/octet-stream",
  "created_at": "..."
}

// 自定义 MIME 类型
POST /files  {"name": "image.png", "owner_id": 2, "mime_type": "image/png"}
→ 201  {"mime_type": "image/png", ...}

// 校验
POST /files  {"owner_id": 1}     → 422  // name 必填
POST /files  {"name": "f.pdf"}   → 422  // owner_id 必填
```

## 生成签名 URL

```php
POST /files/1/sign
{"ttl_seconds": 300}
→ 200
{
  "token": "1|2026-05-27 09:05:00|a3f9e2...",
  "expires_at": "2026-05-27T09:05:00Z",
  "url": "/download?token=1%7C2026-05-27+09%3A05%3A00%7Ca3f9e2...",
  "ttl_seconds": 300
}

// 省略时默认 TTL = 3600（1 小时）
POST /files/1/sign  {}
→ 200  {"ttl_seconds": 3600}

// 未知文件
POST /files/999/sign  {"ttl_seconds": 60}
→ 404
```

## 使用令牌下载

```php
GET /download?token=1|2026-05-27+09:05:00|a3f9e2...
→ 200  {"id": 1, "name": "report.pdf", "mime_type": "application/octet-stream"}

// 缺少令牌
GET /download
→ 401

// 被篡改的令牌（最后 4 个字符被修改）
GET /download?token=1|2026-05-27+09:05:00|XXXX
→ 401

// 已过期的令牌（expires_at 在过去）
GET /download?token=1|2020-01-01+00:00:00|...valid_hmac...
→ 410 Gone

// 随机无效字符串
GET /download?token=totally-invalid-garbage
→ 401
```

**410 Gone**（不是 401）用于已过期令牌：URL 曾经存在且有效——只是已过期。这让客户端能够区分"从未有效"和"曾经有效，现已过时"。

## 令牌格式——HMAC-SHA256

```
token = "{file_id}|{expires_at}|{hmac}"

hmac = HMAC-SHA256(key=server_secret, message="{file_id}|{expires_at}")
```

```php
class HmacSigner
{
    public function __construct(private readonly string $secret)
    {
    }

    public function sign(int $fileId, string $expiresAt): string
    {
        $payload = "{$fileId}|{$expiresAt}";
        $hmac    = hash_hmac('sha256', $payload, $this->secret);
        return "{$payload}|{$hmac}";
    }

    public function verify(string $token, string $now): ?int
    {
        $parts = explode('|', $token, 3);
        if (count($parts) !== 3) {
            return null;
        }

        [$fileIdStr, $expiresAt, $receivedHmac] = $parts;
        $fileId  = (int) $fileIdStr;
        $payload = "{$fileId}|{$expiresAt}";

        // 恒定时间比较
        $expected = hash_hmac('sha256', $payload, $this->secret);
        if (!hash_equals($expected, $receivedHmac)) {
            return null;  // 被篡改或密钥错误
        }

        // 验证 HMAC 之后再检查过期时间
        if ($expiresAt < $now) {
            return -1;  // 已过期——调用者返回 410
        }

        return $fileId;
    }
}
```

**关键顺序**：始终先验证 HMAC，再检查过期时间。对无效令牌先检查过期时间会让攻击者探测过期行为。

### 资源绑定

每个令牌编码了 `file_id`。不同文件的令牌产生不同的 HMAC 摘要：

```php
$token1 = $signer->sign(1, $future);
$token2 = $signer->sign(2, $future);
// $token1 !== $token2 — 无法复用 file-1 的令牌访问 file-2
```

### 错误密钥

使用不同密钥签名的令牌在 `verify()` 时返回 null：

```php
$otherSigner = new HmacSigner('different-secret');
$token = $otherSigner->sign(1, $future);
$signer->verify($token, $now);  // null — HMAC 不匹配
```

---

## 不应做的事

| 反模式 | 风险 |
|---|---|
| HMAC 比较用 `===` 而非 `hash_equals()` | 时序攻击逐字节泄露 HMAC |
| 验证 HMAC 之前先检查过期时间 | 攻击者对伪造令牌探测过期时间以获知服务器时钟 |
| 令牌载荷只包含 user_id 而非 file_id | 用户 1 文件 1 的令牌可复用来访问用户 1 文件 2 |
| 使用 `md5()` 或 `sha1()` 代替 HMAC-SHA256 | 需要带密钥的哈希；无密钥哈希可被轻易伪造 |
| 已过期令牌返回 401 | 410 告知客户端"令牌曾经有效但已过时"；允许正确的重新签名流程 |
| 在访问日志中记录令牌值 | 令牌可授予访问权限——像对待密码一样；日志中掩码或省略 |
| 使用弱密钥或可预测密钥 | 密钥至少需要 32 个随机字节；永远不要从时间戳或主机名派生 |
