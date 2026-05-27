# 出站 Webhook 投递

出站 Webhook 在应用中发生事件时通知第三方系统。主要安全关注点是 SSRF（向内部基础设施发送请求）、secret 泄露和签名完整性。

## 核心组件

- **端点注册表**：每个订阅者存储 URL、事件过滤器和哈希 secret。
- **投递队列**：每个（端点、事件）对一条记录，跟踪尝试次数和状态。
- **签名器**：生成接收方可以验证的 HMAC-SHA256 签名。
- **URL 验证器**：在存储端点之前阻断 SSRF 目标。

## 数据库结构

```sql
CREATE TABLE webhook_endpoints (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    url         TEXT    NOT NULL,
    event_type  TEXT    NOT NULL,
    secret_hash TEXT    NOT NULL,       -- 原始 secret 的 SHA-256；原始 secret 从不存储
    max_retries INTEGER NOT NULL DEFAULT 3,
    active      INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT    NOT NULL
);

CREATE TABLE webhook_deliveries (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    endpoint_id   INTEGER NOT NULL,
    event_type    TEXT    NOT NULL,
    payload       TEXT    NOT NULL,
    status        TEXT    NOT NULL DEFAULT 'pending',  -- pending | delivered | failed
    attempt_count INTEGER NOT NULL DEFAULT 0,
    last_status   INTEGER,                             -- 最后的 HTTP 响应码
    last_error    TEXT,
    delivered_at  TEXT,
    created_at    TEXT    NOT NULL,
    updated_at    TEXT    NOT NULL
);
```

只存储 secret 的 SHA-256 哈希。原始 secret 从不持久化——如果数据库被攻破，哈希无法被还原以伪造签名（对于随机 32 字节的 secret，不带 HMAC 的 SHA-256 是不可逆的）。

## 签名格式

```
X-Webhook-Signature: sha256={hex}
X-Webhook-Timestamp: {unix_timestamp}
```

签名内容：`{timestamp}.{body}` — 将签名绑定到 payload 和时间点。

```php
public function sign(string $rawSecret, string $body, string $timestamp): string
{
    $payload = $timestamp . '.' . $body;
    $mac     = hash_hmac('sha256', $payload, $rawSecret);

    return 'sha256=' . $mac;
}
```

在签名内容中包含时间戳可以防止重放攻击：捕获有效 webhook 的攻击者无法稍后重用它，因为时间戳会过期。接收方应拒绝超过阈值（例如 5 分钟）的旧签名。

## SSRF 防护

在存储之前验证每个 webhook URL。至少阻断：

```php
final class UrlValidator
{
    public function validate(string $url): ?string
    {
        // 阻断 CRLF/空字节注入
        if (str_contains($url, "\n") || str_contains($url, "\r") || str_contains($url, "\0")) {
            return 'URL contains illegal control characters.';
        }

        // 仅 HTTPS
        if (strtolower(parse_url($url, PHP_URL_SCHEME) ?? '') !== 'https') {
            return 'Only HTTPS URLs are allowed.';
        }

        // 阻断私有/回环 IP 和保留主机名
        // ...
    }
}
```

需要阻断的私有 IPv4 范围：`127.0.0.0/8`、`10.0.0.0/8`、`172.16.0.0/12`、`192.168.0.0/16`、`169.254.0.0/16`、`0.0.0.0`。

需要阻断的主机名：`localhost`、`*.local`、`*.internal`、`*.test`、`*.invalid`。

IPv6：`::1`、`fc00::/7`（ULA）、`fe80::/10`（链路本地）。

**DNS 重绑定**：仅在注册时验证 URL 是不够的——DNS 记录可能在注册和投递之间发生变化，指向内部 IP。在生产环境中，还需要在投递时、建立 TCP 连接之前验证解析后的 IP。

## 响应过滤——永远不暴露 secret

`WebhookEndpoint` 的 `toArray()` 方法必须省略 `secret` 和 `secret_hash`：

```php
public function toArray(): array
{
    return [
        'id', 'url', 'event_type', 'max_retries', 'active', 'created_at',
        // secret_hash 有意缺失
    ];
}
```

适用于：GET /webhooks/{id}、列出端点，以及任何记录端点元数据的审计日志。

## 重试逻辑

```php
public function markFailed(int $id, string $error, ?int $httpStatus, string $now, int $maxRetries): ?WebhookDelivery
{
    $newCount  = $delivery->attemptCount + 1;
    $newStatus = $newCount >= $maxRetries ? 'failed' : 'pending';

    $this->executor->execute(
        'UPDATE webhook_deliveries SET status = ?, attempt_count = ?, last_error = ?, updated_at = ? WHERE id = ?',
        [$newStatus, $newCount, $error, $now, $id],
    );
}
```

- `attempt_count < max_retries` → 状态保持 `pending` → worker 再次处理。
- `attempt_count >= max_retries` → 状态变为 `failed` → 不再重试。

Worker 应实现指数退避（例如 `2^attempt_count` 秒）以避免持续冲击挣扎中的接收方。

## 停用

在分发时，停用端点（`active = 0`）被排除在扇出查询之外：

```sql
SELECT * FROM webhook_endpoints WHERE event_type = ? AND active = 1
```

这为订阅者提供了一种暂停投递而不删除其注册的方式。

## 设计决策

**为什么存储 `secret_hash` 而不是原始 secret？**
如果 DB 被攻破，攻击者无法提取 secret 来伪造发送给接收方的 webhook 签名。原始 secret 在创建时一次性返回，必须由调用方安全存储。

**为什么在签名中包含时间戳？**
没有时间戳的签名可以无限期重放。在 HMAC 中包含 `{timestamp}.{body}` 意味着拦截 webhook 的攻击者无法重新发送它——接收方可以拒绝 ±5 分钟窗口之外的时间戳。

**为什么使用 `hash_equals()` 而不是 `===`？**
PHP 的 `===` 是短路比较：一旦两个字符不同就停止。攻击者可以测量比较两个字符串所需的时间，推断有多少前导字符匹配，从而实现时序预言攻击，逐字节暴力破解 secret。`hash_equals()` 无论字符串在哪里不同都以恒定时间运行。
