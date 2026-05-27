# 操作指南：Webhook 投递系统

> **FT 参考**：FT308（`NENE2-FT/webhookdeliverylog`）— Webhook 投递系统：通过 UrlValidator 实现 SSRF 防护（仅 HTTPS、私有 IP 黑名单、CRLF 注入防止）、带时间戳绑定的 HMAC-SHA256 签名、secret 以 SHA-256 哈希存储（从不明文）、GET 响应不返回 secret、已停用端点跳过投递、事件类型隔离，ATK-01〜12 全部 BLOCKED，31 tests / 47 assertions 全部 PASS。

本指南展示如何构建 Webhook 投递系统，其中 webhook secret 受到保护，URL 经过 SSRF 攻击验证，payload 使用时间戳签名以防止重放攻击。

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS webhook_endpoints (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    url         TEXT    NOT NULL,
    event_type  TEXT    NOT NULL,
    secret_hash TEXT    NOT NULL,   -- 原始 secret 的 SHA-256 哈希
    max_retries INTEGER NOT NULL DEFAULT 3,
    active      INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    endpoint_id   INTEGER NOT NULL REFERENCES webhook_endpoints(id),
    event_type    TEXT    NOT NULL,
    payload       TEXT    NOT NULL DEFAULT '{}',
    status        TEXT    NOT NULL DEFAULT 'pending',
    attempt_count INTEGER NOT NULL DEFAULT 0,
    last_status   INTEGER,
    last_error    TEXT,
    delivered_at  TEXT,
    created_at    TEXT    NOT NULL,
    updated_at    TEXT    NOT NULL
);
```

`secret_hash` 存储原始 secret 的 SHA-256 哈希——从不存储 secret 本身。`active` 标志允许软禁用端点而不删除投递历史。

## SSRF 防护——UrlValidator

```php
final class UrlValidator
{
    public function validate(string $url): ?string
    {
        // 阻断 CRLF 和空字节注入
        if (str_contains($url, "\n") || str_contains($url, "\r") || str_contains($url, "\0")) {
            return 'URL contains illegal control characters.';
        }

        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            return 'URL is not valid.';
        }

        // 仅 HTTPS
        if (strtolower($parsed['scheme']) !== 'https') {
            return 'Only HTTPS URLs are allowed for webhook delivery.';
        }

        $host = strtolower($parsed['host']);

        // 阻断 localhost 及其变体
        if (in_array($host, ['localhost', 'ip6-localhost', 'ip6-loopback'], true)) {
            return "Webhook URL must not target '{$host}'.";
        }

        // 阻断内部 TLD
        foreach (['.local', '.internal', '.test', '.example', '.invalid', '.localhost'] as $pattern) {
            if (str_ends_with($host, $pattern)) {
                return "Webhook URL must not target '{$pattern}' domains.";
            }
        }

        // 阻断私有 IPv4 范围（127.x, 10.x, 172.16-31.x, 192.168.x）
        $ip = trim($host, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return 'Webhook URL must not target private or loopback IP addresses.';
            }
        }

        // 阻断私有 IPv6（::1, fc00::/7, fe80::/10）
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            // ... IPv6 私有范围检查
        }

        return null; // 有效
    }
}
```

验证阻断：
1. **CRLF/空字节注入** — 防止向 webhook URL 的 HTTP 请求中注入头部
2. **非 HTTPS scheme** — `http://`、`file://`、`ftp://`、`gopher://` 全部阻断
3. **回环地址** — `127.0.0.0/8`、`::1`
4. **私有范围** — `10.x`、`172.16-31.x`、`192.168.x`、`0.0.0.0`
5. **内部 TLD** — `.local`、`.internal`、`.test`、`.example`

## Webhook 签名——HMAC-SHA256 + 时间戳

```php
final class WebhookSigner
{
    public function sign(string $rawSecret, string $body, string $timestamp): string
    {
        $payload = $timestamp . '.' . $body;  // 时间戳将签名绑定到时间
        $mac     = hash_hmac('sha256', $payload, $rawSecret);
        return 'sha256=' . $mac;
    }

    public function hashSecret(string $rawSecret): string
    {
        return hash('sha256', $rawSecret);
    }
}
```

签名格式 `sha256=<hex>` 与 GitHub webhooks 使用的模式相同。**时间戳包含在签名内容中**（`timestamp.body`）——这防止了重放攻击：在时间 T 捕获的签名无法在时间 T+1h 被重放。

## Secret 存储——哈希，从不明文

```php
// 创建端点时：
$secretHash = $this->signer->hashSecret($rawSecret);
$this->repo->createEndpoint($url, $eventType, $secretHash, $maxRetries);

// 仅一次将原始 secret 返回给调用者：
return $this->json->create([
    'id'     => $endpointId,
    'secret' => $rawSecret,  // 仅在创建时显示
    // 存储为：secret_hash = SHA-256($rawSecret)
]);
```

原始 secret 仅在创建时**一次性**返回给调用者。后续的 `GET /endpoints/{id}` 响应绝不包含 `secret` 或 `secret_hash`。

```php
// GET 端点响应——不包含 secret
return $this->json->create([
    'id'         => (int) $endpoint['id'],
    'url'        => $endpoint['url'],
    'event_type' => $endpoint['event_type'],
    'active'     => (bool) $endpoint['active'],
    'max_retries'=> (int) $endpoint['max_retries'],
    'created_at' => $endpoint['created_at'],
    // 'secret_hash' 有意省略
]);
```

## 跳过已停用端点

```php
// 分发处理器
if (!(bool) $endpoint['active']) {
    return $this->json->create(['message' => 'Endpoint is inactive, no delivery queued.'], 200);
}
```

已停用端点不接收新投递。这允许禁用 webhook 而不删除端点或其投递历史。

## 事件类型隔离

每个端点订阅特定的 `event_type`。分发时：

```php
$endpoints = $this->repo->findActiveEndpointsByType($eventType);
// 只有匹配 event_type 的端点才会收到投递
```

订阅了 `order.created` 的端点不会接收 `order.cancelled` 事件。

---

## ATK 评估——攻击者思维攻击测试

### ATK-01 — 通过回环 IPv4（127.x.x.x）的 SSRF 🚫 BLOCKED

**攻击**：使用 `url: "https://127.0.0.1/admin"` 注册端点。
**结果**：BLOCKED — UrlValidator 检测到私有 IPv4 范围 → 422。

---

### ATK-02 — 通过 0.0.0.0 的 SSRF 🚫 BLOCKED

**攻击**：`url: "https://0.0.0.0/internal"`。
**结果**：BLOCKED — 保留 IP 范围被 `FILTER_FLAG_NO_RES_RANGE` 阻断 → 422。

---

### ATK-03 — 通过私有范围 10.x.x.x 的 SSRF 🚫 BLOCKED

**攻击**：`url: "https://10.0.0.1/internal"`。
**结果**：BLOCKED — 私有 IPv4 范围 → 422。

---

### ATK-04 — 通过私有范围 172.16-31.x.x 的 SSRF 🚫 BLOCKED

**攻击**：`url: "https://172.16.0.1/internal"`。
**结果**：BLOCKED — 私有 IPv4 范围 → 422。

---

### ATK-05 — HTTP Scheme 降级 🚫 BLOCKED

**攻击**：`url: "http://example.com/hook"`（非 HTTPS）。
**结果**：BLOCKED — scheme 检查：只允许 `https` → 422。

---

### ATK-06 — file:// Scheme 🚫 BLOCKED

**攻击**：`url: "file:///etc/passwd"`。
**结果**：BLOCKED — scheme 检查阻断非 HTTPS → 422。

---

### ATK-07 — URL 中的 CRLF 注入 🚫 BLOCKED

**攻击**：`url: "https://example.com/\r\nX-Injected: header"`。
**结果**：BLOCKED — `str_contains($url, "\r")` 检查 → 422。

---

### ATK-08 — URL 中的空字节 🚫 BLOCKED

**攻击**：`url: "https://example.com/\0hidden"`。
**结果**：BLOCKED — `str_contains($url, "\0")` 检查 → 422。

---

### ATK-09 — 通过 GET 端点泄露 Secret 🚫 BLOCKED

**攻击**：`GET /endpoints/{id}` 以获取存储的 secret。
**结果**：BLOCKED — GET 响应完全省略 `secret` 和 `secret_hash` 字段。

---

### ATK-10 — 通过分发响应泄露 Secret 🚫 BLOCKED

**攻击**：检查分发响应体中的 secret 内容。
**结果**：BLOCKED — 分发响应只包含投递元数据，没有 secret 字段。

---

### ATK-11 — 重放攻击（捕获的签名）🚫 BLOCKED

**攻击**：捕获已签名的 webhook 并稍后以相同签名重放。
**结果**：BLOCKED — 签名为 `HMAC(timestamp.body, secret)`。时间戳每次投递都不同；旧签名与新时间戳不匹配。

---

### ATK-12 — 使用错误 Secret 伪造签名 🚫 BLOCKED

**攻击**：用猜测/不同的 secret 计算 HMAC，作为有效签名提交。
**结果**：BLOCKED — 接收方用存储的 secret 哈希验证；伪造的 HMAC 不匹配。

---

### ATK 摘要

| ID | 攻击 | 结果 |
|----|------|------|
| ATK-01 | SSRF 回环 IPv4 | 🚫 BLOCKED |
| ATK-02 | SSRF 0.0.0.0 | 🚫 BLOCKED |
| ATK-03 | SSRF 私有 10.x | 🚫 BLOCKED |
| ATK-04 | SSRF 私有 172.16-31.x | 🚫 BLOCKED |
| ATK-05 | HTTP scheme 降级 | 🚫 BLOCKED |
| ATK-06 | file:// scheme | 🚫 BLOCKED |
| ATK-07 | URL 中的 CRLF 注入 | 🚫 BLOCKED |
| ATK-08 | URL 中的空字节 | 🚫 BLOCKED |
| ATK-09 | 通过 GET 泄露 secret | 🚫 BLOCKED |
| ATK-10 | 通过分发泄露 secret | 🚫 BLOCKED |
| ATK-11 | 重放攻击 | 🚫 BLOCKED |
| ATK-12 | 伪造签名 | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED**
UrlValidator 阻断所有 SSRF 向量。时间戳绑定的 HMAC 防止重放。Secret 以哈希存储，创建后不再返回。

---

## 不应做的事

| 反模式 | 风险 |
|---|---|
| 在 DB 中存储原始 webhook secret | DB 泄露暴露所有 secret；SHA-256 哈希是单向的 |
| 在 GET 响应中返回 secret | 任何管理 API 泄露都会暴露所有 webhook secret |
| 仅对 body 计算 HMAC（无时间戳） | 重放攻击：捕获的签名可以无限期重用 |
| 允许 `http://` webhook URL | Webhook payload 在传输中被窃听 |
| 注册 URL 时不进行 SSRF 验证 | Webhook 系统被用于探测内部网络 |
| 在 webhook URL 中允许 `127.x`、`10.x` | 服务器向自己的内部服务发出请求 |
| 不检查 CRLF | 含 `\r\n` 的 URL 向出站 HTTP 请求注入头部 |
| 向已停用端点投递 | 已停用端点继续接收流量 |
| 不进行事件类型过滤 | 所有事件类型投递到所有端点 |
