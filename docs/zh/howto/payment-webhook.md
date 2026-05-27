# 支付 Webhook 接收实现指南

## 概述

本指南说明如何使用 NENE2 实现支付 Webhook 接收 API。提供 HMAC-SHA256 签名验证、幂等处理（event_id UNIQUE 约束）以及状态转换守护。

---

## 数据库结构

```sql
CREATE TABLE payments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    external_id TEXT    NOT NULL UNIQUE,
    amount      INTEGER NOT NULL,               -- 最小货币单位（日元・分）
    currency    TEXT    NOT NULL DEFAULT 'usd',
    status      TEXT    NOT NULL DEFAULT 'pending'
                        CHECK (status IN ('pending', 'succeeded', 'failed', 'refunded')),
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);

CREATE TABLE webhook_events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id     TEXT    NOT NULL UNIQUE,   -- 幂等键
    event_type   TEXT    NOT NULL,
    payload      TEXT    NOT NULL,          -- JSON
    processed_at TEXT    NOT NULL
);
```

`webhook_events.event_id` 是**幂等处理**的核心。即使收到两次相同的 event_id 也只处理一次。

---

## 端点设计

| 方法 | 路径 | 描述 |
|---|---|---|
| POST | `/webhooks/payment` | 接收并处理 Webhook 事件 |
| GET | `/payments` | 支付列表 |
| GET | `/payments/{id}` | 支付详情 |

---

## 状态转换

```
[created] → pending → succeeded → refunded
                    ↘ failed
```

使用转换表管理：

```php
private const array VALID_TRANSITIONS = [
    'payment.succeeded' => ['from' => 'pending',   'to' => 'succeeded'],
    'payment.failed'    => ['from' => 'pending',   'to' => 'failed'],
    'payment.refunded'  => ['from' => 'succeeded', 'to' => 'refunded'],
];
```

非法转换（如 failed → succeeded）返回 409 Conflict。

---

## 设计要点

### HMAC-SHA256 签名验证

对整个请求体进行 HMAC-SHA256 验证。使用 Stripe 兼容的 `X-Webhook-Signature: sha256=<hex>` 请求头：

```php
private function verifySignature(string $body, string $header): bool
{
    if (!str_starts_with($header, 'sha256=')) {
        return false;
    }
    $provided = substr($header, 7);
    $expected = hash_hmac('sha256', $body, $this->webhookSecret);
    return hash_equals($expected, $provided); // 防止时序攻击
}
```

使用 `hash_equals()` 进行恒定时间比较。`===` 或 `strcmp()` 会提前退出，存在安全隐患。

### 幂等处理

Webhook 提供商会重试。使用 `event_id` 消除重复：

```php
// 处理前检查
if ($this->repo->isEventProcessed($eventId)) {
    return $this->json->create(['status' => 'already_processed']);
}

// 处理后记录
$this->repo->recordEvent($eventId, $eventType, $payload, $now);
```

### 处理顺序

```
1. 签名验证 → 401
2. event_id 重复检查 → 200 already_processed
3. 按事件类型处理
4. 记录到 webhook_events
5. 返回 200 processed
```

**签名验证必须最先执行**，以防止攻击者污染 event_id 表。

### 未知事件类型返回 200

当提供商添加新的事件类型时，返回 4xx 会触发重试。静默返回 200 并记录未知类型：

```php
// 未知事件类型——确认收到但不处理
return null; // → 200 processed
```

### 测试：使用注入 SECRET 生成签名

```php
private const string SECRET = 'test-webhook-secret';

private function signedReq(string $path, array $body): ResponseInterface
{
    $rawBody = json_encode($body);
    $sig     = 'sha256=' . hash_hmac('sha256', $rawBody, self::SECRET);
    // ...
}
```

使用 `AppFactory::createSqlite($dbFile, self::SECRET)` 将相同的 secret 传入应用。

---

## 事件载荷示例

### payment.created

```json
{
  "event_id": "evt_001",
  "event_type": "payment.created",
  "data": {"id": "pay_abc", "amount": 5000, "currency": "jpy"}
}
```

### payment.succeeded

```json
{
  "event_id": "evt_002",
  "event_type": "payment.succeeded",
  "data": {"id": "pay_abc"}
}
```

### 响应（成功）

```json
{"status": "processed", "event_type": "payment.succeeded"}
```

### 响应（幂等重发）

```json
{"status": "already_processed"}
```

---

## 参考实现

`../NENE2-FT/paymentlog/` — FT163 字段试验（18 个测试・签名验证・幂等处理・转换守护）
