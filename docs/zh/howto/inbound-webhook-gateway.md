# 操作指南：入站 Webhook 网关

> **FT 参考**：FT317（`NENE2-FT/inboundlog`）——带有每来源 HMAC-SHA256 签名验证的入站 Webhook 网关，基于 event_id 的幂等性去重，密钥从不在响应中暴露，17 个测试 / 18 个断言全部通过。

本指南展示如何构建多来源入站 Webhook 接收器，在处理前验证请求真实性。

## 数据库结构

```sql
CREATE TABLE sources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    secret     TEXT    NOT NULL,   -- HMAC 共享密钥
    active     INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL
);

CREATE TABLE webhook_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id   INTEGER NOT NULL REFERENCES sources(id),
    event_id    TEXT    NOT NULL,  -- 提供商提供的去重键
    event_type  TEXT    NOT NULL,
    payload     TEXT    NOT NULL,  -- 原始 JSON 请求体
    received_at TEXT    NOT NULL,
    UNIQUE(source_id, event_id)
);
```

## 端点

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/sources` | 注册新 Webhook 来源 |
| `POST` | `/sources/{id}/receive` | 接收 Webhook 事件 |
| `GET`  | `/sources/{id}/events` | 列出某来源的事件 |
| `GET`  | `/events/{id}` | 获取单个事件 |

## 来源注册

```php
POST /sources
{"name": "stripe", "secret": "whsec_abc123..."}

→ 201
{"id": 1, "name": "stripe", "active": true, "created_at": "..."}
// 密钥绝不返回
```

```php
POST /sources  {"secret": "abc"}   → 422  // 需要 name
POST /sources  {"name": "github"}  → 422  // 需要 secret
```

## HMAC-SHA256 签名验证

每个入站 Webhook 必须在 `X-Webhook-Signature` 请求头中包含原始请求体的 HMAC-SHA256 签名：

```
X-Webhook-Signature: sha256=<hex_digest>
```

```php
private function verifySignature(string $body, string $header, string $secret): bool
{
    if (!str_starts_with($header, 'sha256=')) {
        return false;
    }
    $expected = hash_hmac('sha256', $body, $secret);
    return hash_equals($expected, substr($header, 7));  // 常量时间比较
}
```

**重要**：使用 `hash_equals()` 而非 `===`，以防止时序攻击。

## 接收事件

```php
// 发送方（例如 Stripe）计算：
$sig = 'sha256=' . hash_hmac('sha256', $rawBody, $sharedSecret);

POST /sources/1/receive
X-Webhook-Signature: sha256=<digest>
Content-Type: application/json

{"event_id": "evt-001", "event_type": "payment.succeeded", "data": {...}}

→ 201  {"id": 5, "event_type": "payment.succeeded", "status": "processed"}
```

### 错误情况

```php
// 签名错误或缺失
POST /sources/1/receive  (错误签名)  → 401 Unauthorized

// 来源不存在
POST /sources/9999/receive          → 404 Not Found

// 载荷中缺少 event_id
POST /sources/1/receive  {"event_type": "x"}  → 422
```

## 重复事件幂等性

提供商重试很常见——`event_id` 去重防止重复处理：

```php
// 首次投递
POST /sources/1/receive  {"event_id": "evt-dup", "event_type": "order.created"}
→ 201  {"status": "processed", "id": 5}

// 重试（相同 event_id）
POST /sources/1/receive  {"event_id": "evt-dup", "event_type": "order.created"}
→ 200  {"status": "already_processed", "id": 5}
```

DB 中的 `UNIQUE(source_id, event_id)` 在存储层强制执行此约束。

## 查询事件

```php
GET /sources/1/events
→ 200  {"events": [...], "count": 2}

GET /events/5
→ 200  {"id": 5, "source_id": 1, "event_type": "payment.succeeded", ...}

GET /events/9999
→ 404
```

---

## 反模式

| 反模式 | 风险 |
|--------|------|
| 在来源响应中返回 `secret` | 将签名密钥泄露给能读取 API 响应的任何客户端 |
| 签名比较用 `===` 而非 `hash_equals()` | 时序攻击可逐字节还原 HMAC |
| 无 `event_id` 去重 | 提供商重试导致重复处理（重复扣款、重复邮件） |
| 在解析 JSON 后才验证签名 | 攻击者可构造能通过 JSON 解析但无法通过 HMAC 的请求体；务必先验证原始字节 |
| 所有来源共用一个全局密钥 | 一个集成被攻破会暴露所有来源 |
