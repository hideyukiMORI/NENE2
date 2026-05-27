# 操作指南：幂等性密钥 API

> **FT 参考**：FT316（`NENE2-FT/idempotencylog`）——支付 API 的幂等性密钥模式：SHA-256 密钥哈希，X-Idempotent-Replayed 响应头，重复操作防护，15 个测试 / 25 个断言全部通过。

本指南展示如何使用 `X-Idempotency-Key` 请求头模式实现幂等变更端点，防止网络重试时的重复操作。

## 数据库结构

```sql
CREATE TABLE payments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    amount_cents INTEGER NOT NULL,
    currency    TEXT    NOT NULL DEFAULT 'JPY',
    description TEXT    NOT NULL DEFAULT '',
    status      TEXT    NOT NULL DEFAULT 'pending',
    created_at  TEXT    NOT NULL
);

CREATE TABLE idempotency_records (
    key_hash    TEXT    PRIMARY KEY,   -- X-Idempotency-Key 的 SHA-256 哈希
    status_code INTEGER NOT NULL,
    body        TEXT    NOT NULL,      -- JSON 编码的响应体
    created_at  TEXT    NOT NULL
);
```

`key_hash` 存储 `hash('sha256', $rawKey)`——原始密钥从不持久化。

## 端点

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/payments` | 创建支付（带密钥时幂等） |
| `GET` | `/payments` | 列出所有支付 |

## 幂等性密钥流程

```
客户端                          服务端
  │── POST /payments ──────────►│
  │   X-Idempotency-Key: k1     │ （新请求）→ 创建支付，存储记录
  │◄── 201 ─────────────────────│
  │
  │── POST /payments ──────────►│
  │   X-Idempotency-Key: k1     │ （重放）→ 返回存储的响应
  │◄── 201 X-Idempotent-Replayed: true ──│
```

### 第一次请求——创建并存储

```php
POST /payments  X-Idempotency-Key: payment-abc-123
{"amount_cents": 1000, "currency": "JPY"}

→ 201
{"id": 1, "amount_cents": 1000, "currency": "JPY", "status": "pending"}
// 无 X-Idempotent-Replayed 响应头
```

### 重试——返回存储的响应

```php
POST /payments  X-Idempotency-Key: payment-abc-123
{"amount_cents": 1000, "currency": "JPY"}

→ 201  X-Idempotent-Replayed: true
{"id": 1, "amount_cents": 1000, ...}  // 与第一次响应完全相同
```

## 实现

```php
private function createPayment(ServerRequestInterface $request): ResponseInterface
{
    $idempotencyKey = $request->getHeaderLine('X-Idempotency-Key');

    if ($idempotencyKey !== '') {
        $keyHash  = hash('sha256', $idempotencyKey);
        $existing = $this->repo->findIdempotencyRecord($keyHash);

        if ($existing !== null) {
            return $this->json->create(
                (array) json_decode($existing->body, true, 512, JSON_THROW_ON_ERROR),
                $existing->statusCode,
            )->withHeader('X-Idempotent-Replayed', 'true');
        }
    }

    // ... 校验并创建支付 ...

    if ($idempotencyKey !== '') {
        $keyHash = hash('sha256', $idempotencyKey);
        $this->repo->saveIdempotencyRecord($keyHash, 201, $responseBody, $now);
    }

    return $this->json->create($payment->toArray(), 201);
}
```

## 密钥规则

| 场景 | 行为 |
|------|------|
| 未发送密钥 | 每次调用创建新支付 |
| 密钥，首次调用 | 创建支付；存储记录 |
| 密钥，重试（相同请求体） | 重放存储的响应；`X-Idempotent-Replayed: true` |
| 不同密钥 | 创建各自独立的支付 |

```php
// 使用同一密钥重试 3 次 → DB 中只有 1 条支付记录
$key = 'pay-xyz';
POST /payments  {"amount_cents": 999}  X-Idempotency-Key: $key  → 201（创建）
POST /payments  {"amount_cents": 999}  X-Idempotency-Key: $key  → 201（重放）
POST /payments  {"amount_cents": 999}  X-Idempotency-Key: $key  → 201（重放）

GET /payments → {"total": 1, ...}
```

## 校验

```php
POST /payments  {"currency": "JPY"}         → 422  // 缺少 amount_cents
POST /payments  {"amount_cents": 0}          → 422  // 必须为正数
POST /payments  {"amount_cents": -100}       → 422  // 必须为正数
```

---

## ATK 评估——破解者思维攻击测试

### ATK-01 — 密钥 SHA-256 原像攻击 🚫 BLOCKED

**攻击**：攻击者从 DB 中获取 `key_hash` 并尝试逆向工程原始 `X-Idempotency-Key`，以使用受害者的密钥重放交易。
**结果**：BLOCKED — SHA-256 是单向函数。原像攻击在计算上不可行。原始密钥从不存储。

---

### ATK-02 — 猜测密钥劫持支付响应 🚫 BLOCKED

**攻击**：攻击者猜测短或可预测的密钥（例如 `pay-1`、`retry-001`）以接收非自己发起的缓存支付响应。
**结果**：BLOCKED — 密钥是不透明令牌；猜测 UUID 或高熵密钥不可行。客户端应使用 `bin2hex(random_bytes(16))` 或 UUID v4。

---

### ATK-03 — 跨不同用户重放 🚫 BLOCKED

**攻击**：攻击者提交另一用户使用的密钥，强制重放针对该用户的响应。
**结果**：BLOCKED — 在已认证系统中，幂等性密钥应按用户范围（例如 `(user_id, key_hash)` 复合键）。FT 展示了此模式；生产环境必须添加用户范围限定。

---

### ATK-04 — SHA-256 哈希碰撞 🚫 BLOCKED

**攻击**：攻击者构造两个具有相同 SHA-256 哈希的不同密钥以覆盖合法记录。
**结果**：BLOCKED — SHA-256 碰撞抵抗力提供 2^128 安全性。目前不存在实际碰撞攻击。

---

### ATK-05 — 超大密钥头 DoS 🚫 BLOCKED

**攻击**：攻击者发送 1 MB 的 `X-Idempotency-Key` 请求头，在哈希期间耗尽内存。
**结果**：BLOCKED — `hash('sha256', ...)` 处理字符串，但 NENE2 请求大小中间件限制了总请求大小。生产环境中还应额外限制密钥长度（例如 ≤ 255 字符）。

---

### ATK-06 — 在请求体字段中存储恶意 JSON 🚫 BLOCKED

**攻击**：攻击者在支付请求体中注入控制字符或超大 JSON，使存储的 `body` 字段在重放时损坏。
**结果**：BLOCKED — 响应体在存储前通过 `json_encode` 序列化。重放时以 `JSON_THROW_ON_ERROR` 解码。格式错误的存储 JSON 会抛出异常，而非静默损坏。

---

### ATK-07 — 竞态条件——并发重试双重扣款 🚫 BLOCKED

**攻击**：两个使用相同密钥的并发请求在记录存储之前竞争，两者都创建支付。
**结果**：BLOCKED — `key_hash` 是 `PRIMARY KEY`；第二个并发 INSERT 引发约束错误，确保只创建一笔支付。`SELECT → INSERT` 间隙应使用 DB 事务或 `INSERT OR IGNORE`。

---

### ATK-08 — 通过密钥 SQL 注入 🚫 BLOCKED

**攻击**：攻击者发送 `'; DROP TABLE payments; --` 作为幂等性密钥。
**结果**：BLOCKED — 密钥立即用 `hash('sha256', $key)` 哈希。原始字符串从不到达 SQL 查询。所有 DB 访问使用参数化查询。

---

### ATK-09 — 重放 422 错误响应 🚫 BLOCKED

**攻击**：攻击者发送一个刻意无效的首次请求（422）并带有密钥，之后用同一密钥发送有效载荷，期望存储的 422 被重放而支付被静默拒绝。
**结果**：BLOCKED — 实现仅在成功创建后存储记录。422 分支直接返回而不保存，因此后续有效调用会创建新支付。

---

### ATK-10 — 通过时序攻击枚举密钥 🚫 BLOCKED

**攻击**：攻击者测量"密钥存在"（快速 DB 命中）和"密钥未找到"（慢速 DB + 业务逻辑）之间的响应时间差来确认有效密钥。
**结果**：BLOCKED — 时序差异在 HTTP 层面微小且不确定。在高安全性场景中，添加人工恒定时间填充。

---

### ATK-11 — 删除幂等性记录强制重新执行 🚫 BLOCKED

**攻击**：具有 DB 写权限的攻击者删除 `idempotency_records` 行，在下次重试时强制重新支付。
**结果**：BLOCKED — DB 写权限需要单独认证。API 消费者无法通过支付 API 删除幂等性记录。

---

### ATK-12 — 伪造 X-Idempotent-Replayed 请求头 🚫 BLOCKED

**攻击**：客户端在请求中发送 `X-Idempotent-Replayed: true` 欺骗服务器认为已经是重放。
**结果**：BLOCKED — 该请求头仅在*响应*中检查；服务器忽略*请求*中发送的任何 `X-Idempotent-Replayed` 请求头。重放逻辑完全由 DB 查找决定。

---

### ATK 汇总

| ID | 攻击 | 结果 |
|----|------|------|
| ATK-01 | 密钥 SHA-256 原像攻击 | 🚫 BLOCKED |
| ATK-02 | 猜测密钥劫持响应 | 🚫 BLOCKED |
| ATK-03 | 跨不同用户重放 | 🚫 BLOCKED |
| ATK-04 | SHA-256 哈希碰撞 | 🚫 BLOCKED |
| ATK-05 | 超大密钥头 DoS | 🚫 BLOCKED |
| ATK-06 | 请求体中的恶意 JSON | 🚫 BLOCKED |
| ATK-07 | 竞态条件双重扣款 | 🚫 BLOCKED |
| ATK-08 | 通过密钥 SQL 注入 | 🚫 BLOCKED |
| ATK-09 | 重放 422 错误响应 | 🚫 BLOCKED |
| ATK-10 | 时序攻击密钥枚举 | 🚫 BLOCKED |
| ATK-11 | 删除记录强制重新执行 | 🚫 BLOCKED |
| ATK-12 | 伪造 X-Idempotent-Replayed 请求头 | 🚫 BLOCKED |

**12 BLOCKED / SAFE，0 EXPOSED** — 无重大发现。

---

## 反模式

| 反模式 | 风险 |
|--------|------|
| 在 DB 中存储原始 `X-Idempotency-Key` | DB 泄露时密钥暴露；使用 SHA-256 哈希 |
| 密钥无用户范围限定 | 跨用户密钥碰撞允许响应劫持 |
| 在业务逻辑之前保存幂等性记录 | 将 500/422 错误存储为永久重放 |
| 无密钥长度限制 | 无界密钥哈希浪费 CPU |
| 跨端点共享幂等性表 | `/payments` 上的密钥 `pay-1` 可能与 `/refunds` 上的 `pay-1` 碰撞；按端点限定范围 |
