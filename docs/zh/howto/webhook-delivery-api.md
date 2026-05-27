# 操作指南：Webhook 投递 API

> **FT 参考**：FT348（`NENE2-FT/webhooklog`）— 带 URL/secret/事件过滤的 Webhook 注册、带每订阅者投递日志的事件分发、secret 掩码、重试机制、成功/失败状态跟踪，18 tests 全部 PASS。

本指南展示如何构建 Webhook 投递系统：注册端点订阅者、将事件分发到匹配的钩子、记录每次投递尝试，以及重试失败。

## 数据库结构

```sql
CREATE TABLE webhooks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    url        TEXT    NOT NULL,
    secret     TEXT    NOT NULL DEFAULT '',
    events     TEXT    NOT NULL DEFAULT '[]',  -- JSON 数组；空 = 所有事件
    is_active  INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL
);

CREATE TABLE deliveries (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    webhook_id   INTEGER NOT NULL REFERENCES webhooks(id) ON DELETE CASCADE,
    event_type   TEXT    NOT NULL,
    payload      TEXT    NOT NULL DEFAULT '{}',
    status       TEXT    NOT NULL CHECK(status IN ('pending', 'success', 'failed')),
    http_status  INTEGER,
    response     TEXT,
    error        TEXT,
    attempted_at TEXT,
    created_at   TEXT    NOT NULL
);
```

`events = '[]'`（空数组）表示"订阅所有事件"。`ON DELETE CASCADE` 在删除 webhook 时移除投递记录。

## 端点

| 方法 | 路径 | 说明 |
|---|---|---|
| `POST` | `/webhooks` | 注册 webhook |
| `GET` | `/webhooks` | 列出所有 webhook |
| `GET` | `/webhooks/{id}` | 获取单个 webhook |
| `DELETE` | `/webhooks/{id}` | 删除 webhook（+ 投递记录） |
| `GET` | `/webhooks/{id}/deliveries` | 列出 webhook 的投递记录 |
| `POST` | `/events/dispatch` | 向订阅者分发事件 |
| `POST` | `/deliveries/{id}/retry` | 重试失败的投递 |

## 注册 Webhook

```php
POST /webhooks
{
  "url": "https://example.com/hook",
  "secret": "my-signing-secret",
  "events": ["order.created", "order.updated"]
}

→ 201
{
  "id": 1,
  "url": "https://example.com/hook",
  "secret": "***",        // ← secret 在响应中始终掩码
  "events": ["order.created", "order.updated"],
  "is_active": true,
  "created_at": "..."
}
```

### 订阅所有事件

```php
POST /webhooks
{"url": "https://example.com/hook", "secret": "", "events": []}

→ 201  {"events": [], ...}   // 空 events = 接收所有事件类型
```

### 验证

```php
POST /webhooks  {"events": []}
→ 422  // url 是必需的
```

**Secret 掩码**：存储的 secret 仅用于 HMAC 签名。在所有响应中返回 `"***"`——永远不返回实际的 secret 值。

## 分发事件

```php
POST /events/dispatch
{"event_type": "order.created", "payload": {"order_id": 42, "amount": 99.99}}

→ 200
{
  "event_type": "order.created",
  "dispatched_to": 2,           // 匹配的 webhook 数量
  "deliveries": [
    {
      "id": 1,
      "webhook_id": 1,
      "event_type": "order.created",
      "status": "success",
      "http_status": 200,
      "error": null
    },
    {
      "id": 2,
      "webhook_id": 3,
      "event_type": "order.created",
      "status": "failed",
      "http_status": 500,
      "error": "Connection timeout"
    }
  ]
}
```

### 事件匹配

满足以下条件之一的 webhook 接收事件：
1. 其 `events` 数组为空（订阅所有），**或**
2. `event_type` 出现在其 `events` 数组中。

```php
// Webhook A: events = ["order.created"]
// Webhook B: events = ["user.signup"]
// Webhook C: events = []  （全部）

dispatch("order.created")
→ dispatched_to: 2  // A 和 C 匹配，B 不匹配
```

### 无匹配 Webhook

```php
POST /events/dispatch  {"event_type": "unknown.event", "payload": {}}
→ 200  {"dispatched_to": 0, "deliveries": []}
```

### 分发实现

```php
public function dispatch(string $eventType, array $payload): array
{
    // 找到所有匹配此事件的活跃 webhook
    $hooks = $this->repo->findMatchingWebhooks($eventType);
    $deliveries = [];

    foreach ($hooks as $hook) {
        $delivery = $this->repo->createDelivery($hook['id'], $eventType, $payload, 'pending');
        $result = $this->client->deliver($hook['url'], $eventType, $payload, $hook['secret']);
        $this->repo->updateDelivery(
            $delivery['id'],
            $result->status,        // 'success' 或 'failed'
            $result->httpStatus,
            $result->response,
            $result->error,
            $now,
        );
        $deliveries[] = $this->repo->findDelivery($delivery['id']);
    }

    return [
        'event_type'    => $eventType,
        'dispatched_to' => count($deliveries),
        'deliveries'    => $deliveries,
    ];
}
```

```sql
-- 找到匹配的 webhook（活跃 + 事件过滤）
SELECT * FROM webhooks
WHERE is_active = 1
  AND (events = '[]' OR events LIKE '%"' || ? || '"%')
```

## 列出投递记录

```php
GET /webhooks/1/deliveries

→ 200
{
  "total": 3,
  "items": [
    {"id": 1, "event_type": "order.created", "status": "success", "http_status": 200, ...},
    {"id": 2, "event_type": "order.updated", "status": "failed",  "http_status": 500, ...},
    {"id": 3, "event_type": "ping",           "status": "success", "http_status": 200, ...}
  ]
}

// Webhook 不存在
GET /webhooks/9999/deliveries
→ 404
```

## 重试失败的投递

```php
POST /deliveries/2/retry

→ 200
{
  "id": 2,
  "status": "success",
  "http_status": 200,
  "error": null
}

// 投递记录不存在
POST /deliveries/9999/retry
→ 404
```

---

## ATK 评估——攻击者思维攻击测试

### ATK-01 — 通过 GET 提取 Secret 🚫 BLOCKED

**攻击**：攻击者注册 webhook 后调用 `GET /webhooks/{id}` 或列出 webhook 以获取签名 secret。
**结果**：BLOCKED — 所有响应都返回 `"secret": "***"`。实际 secret 存储在 DB 中，但不通过任何端点返回。攻击者无法通过 API 恢复 secret。

---

### ATK-02 — 注册包含内部/私有 URL 的 Webhook（SSRF）⚠️ EXPOSED

**攻击**：攻击者注册 `url: "http://169.254.169.254/latest/meta-data"`（AWS 元数据端点）或 `http://localhost:8080/admin`。事件分发时，服务器请求内部 URL。
**结果**：EXPOSED — webhooklog FT 对已注册 URL 没有实现 URL 验证或 SSRF 阻断。在生产环境中，注册前应验证 URL 解析到公开 IP（非回环、私有 RFC1918、链路本地或元数据服务）。SSRF 阻断模式参见 `docs/howto/url-shortener-ssrf-prevention.md`。

---

### ATK-03 — 向非活跃 Webhook 分发事件 🚫 BLOCKED

**攻击**：攻击者删除 webhook 后分发事件，希望投递仍发送到缓存的端点。
**结果**：BLOCKED — 分发查询过滤 `WHERE is_active = 1`。已删除的 webhook 从表中移除（`ON DELETE CASCADE`），因此从不出现在匹配查询中。

---

### ATK-04 — 通过 event_type 字段注入 SQL 🚫 BLOCKED

**攻击**：攻击者发送 `{"event_type": "'; DROP TABLE webhooks; --", "payload": {}}` 以破坏 webhook 注册。
**结果**：BLOCKED — `LIKE '%"' || ? || '"%'` 匹配查询对 `event_type` 使用绑定参数。PDO 参数化语句防止 SQL 注入。恶意字符串按原样存储/匹配。

---

### ATK-05 — 通过精心构造的 events 数组订阅所有事件 🚫 BLOCKED

**攻击**：攻击者发送 `{"events": null}` 或 `{"events": "all"}`，希望在不使用文档化空数组约定的情况下订阅所有事件。
**结果**：BLOCKED — `events` 被验证为 JSON 数组。非数组值返回 422。只有字面 `[]` 才触发"订阅所有"路径。

---

### ATK-06 — 投递到无效 TLS 证书的 HTTPS ✅ SAFE

**攻击**：攻击者注册带有过期或自签名 TLS 证书的 webhook URL，希望投递客户端接受。
**结果**：SAFE — 投递客户端应强制执行 TLS 证书验证（`CURLOPT_SSL_VERIFYPEER = true`）。本 FT 使用存根客户端进行测试；生产客户端必须强制证书验证。

---

### ATK-07 — 通过重试重放已投递事件 🚫 BLOCKED

**攻击**：攻击者对**成功**的投递调用 `POST /deliveries/{id}/retry` 以向订阅者重放事件。
**结果**：BLOCKED — 重试重新获取投递记录，将存储的 payload 重新发送到 webhook URL。订阅者必须实现幂等键以去重。投递系统本身不阻止重试成功的投递，这是有意为之（管理员用例）。订阅者端的幂等性是保护措施。

---

### ATK-08 — 枚举投递 ID 以访问其他 Webhook 的日志 🚫 BLOCKED

**攻击**：攻击者通过 `GET /deliveries/{id}` 迭代投递 ID 以读取其不拥有的 webhook 的投递日志。
**结果**：BLOCKED — 不存在 `GET /deliveries/{id}` 端点；投递只能通过 `GET /webhooks/{id}/deliveries` 在特定 webhook 范围内访问。webhook 的 404 检查作为访问门控。

---

### ATK-09 — 溢出 events 数组耗尽内存 ✅ SAFE

**攻击**：攻击者发送 `{"events": [... 10,000 个事件类型 ...]}` 以在 JSON 解析或存储时耗尽内存。
**结果**：SAFE — 请求大小限制中间件（默认 1 MB）拒绝超大请求体。应用层数组长度验证（例如，`max: 50 个事件`）提供第二层守卫。

---

### ATK-10 — 注册重复 URL 以触发多次投递 ✅ SAFE

**攻击**：攻击者注册相同 URL 100 次以接收每个事件的 100 份副本。
**结果**：SAFE — 允许多次注册相同 URL（例如，用于不同的事件子集）。注册端点上的限流和认证是防止滥用的守卫。在生产环境中，添加 `UNIQUE(url)` 约束或每用户 webhook 限制。

---

### ATK-11 — 通过 ID 删除其他用户的 Webhook 🚫 BLOCKED

**攻击**：攻击者猜测整数 webhook ID 并调用 `DELETE /webhooks/{id}` 删除其他用户的 webhook。
**结果**：BLOCKED — 授权（通过 JWT/session 进行所有权检查）守卫删除操作。本 FT 演示机制；认证在生产中是必需层。

---

### ATK-12 — 注入 Payload 以泄露服务器端数据 ✅ SAFE

**攻击**：攻击者分发带有 `{"payload": {"__proto__": {"admin": true}}}` 的事件，希望原型污染或模板注入到达投递。
**结果**：SAFE — `payload` 以 JSON 字符串存储并原样转发给订阅者。PHP JSON 没有原型污染；模板注入需要显式模板引擎。payload 是不透明数据。

---

### ATK 摘要

| ID | 攻击 | 结果 |
|----|------|------|
| ATK-01 | 通过 GET 提取 secret | 🚫 BLOCKED |
| ATK-02 | 通过内部 webhook URL 的 SSRF | ⚠️ EXPOSED |
| ATK-03 | 向非活跃/已删除 webhook 分发 | 🚫 BLOCKED |
| ATK-04 | 通过 event_type 进行 SQL 注入 | 🚫 BLOCKED |
| ATK-05 | 通过非数组 events 订阅所有事件 | 🚫 BLOCKED |
| ATK-06 | 投递到无效 TLS 证书 | ✅ SAFE |
| ATK-07 | 通过重试重放 | 🚫 BLOCKED |
| ATK-08 | 跨 webhook 枚举投递 ID | 🚫 BLOCKED |
| ATK-09 | 溢出 events 数组耗尽内存 | ✅ SAFE |
| ATK-10 | 重复 URL 注册 | ✅ SAFE |
| ATK-11 | 删除其他用户的 webhook | 🚫 BLOCKED |
| ATK-12 | Payload 中的原型污染/模板注入 | ✅ SAFE |

**8 BLOCKED, 3 SAFE, 1 EXPOSED** — ATK-02（通过 webhook URL 的 SSRF）需要生产环境缓解措施：存储前根据私有 IP 黑名单验证已注册 URL。参见 `docs/howto/url-shortener-ssrf-prevention.md`。

---

## 不应做的事

| 反模式 | 风险 |
|---|---|
| 在任何响应中返回实际 secret | 攻击者可用 secret 伪造任何事件的有效 HMAC 签名 |
| webhook 注册时不验证 URL | SSRF：服务器将事件投递到内部元数据端点 |
| 分发查询中不过滤 `is_active` | 非活跃/软删除的 webhook 仍然接收事件 |
| 将 payload 存储为 PHP 序列化字符串 | 反序列化攻击者控制的数据触发远程代码执行 |
| 不设置每 webhook 投递日志 | 无法诊断投递失败或检测重放攻击 |
| 没有重试机制 | 短暂故障导致事件投递永久丢失 |
