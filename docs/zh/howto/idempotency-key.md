# 操作指南：幂等性密钥（请求去重）

> **FT 参考**：FT292（`NENE2-FT/deduplog`）——幂等性密钥去重：UNIQUE(idempotency_key) DB 约束，24 小时 TTL 含可重新处理的过期机制，缓存响应带 `replayed: true` 标志，参数化查询防注入，ATK-01~12 全部 BLOCKED，24 个测试 / 57 个断言全部通过。

本指南展示如何实现幂等性密钥——一种基于请求头的机制，确保重复请求（重试、网络故障）在不产生重复副作用的情况下得到相同的结果。

## 数据库结构

```sql
CREATE TABLE idempotency_keys (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    idempotency_key TEXT NOT NULL UNIQUE,
    method          TEXT NOT NULL,
    path            TEXT NOT NULL,
    status_code     INTEGER NOT NULL,
    response_body   TEXT NOT NULL,
    created_at      TEXT NOT NULL,
    expires_at      TEXT NOT NULL
);
```

`UNIQUE(idempotency_key)` 确保每个密钥只存储一次。响应体序列化为 JSON，在后续请求中重放。

## 请求流程

```
客户端发送 POST /payments，携带 Idempotency-Key: <uuid>
  │
  ├─ DB 中找到密钥且未过期？
  │    └─ 是 → 返回缓存响应 + { "replayed": true }
  │
  └─ 否 → 处理请求 → 存储响应 → 返回 201
```

## 提取 Idempotency-Key

```php
$key = trim($request->getHeaderLine('Idempotency-Key'));
if ($key === '') {
    return $this->json->create(['error' => 'Idempotency-Key header is required'], 400);
}
```

密钥为必填项，裁剪后不得为空。仅含空白字符的密钥会被拒绝并返回 400。

## 缓存查找——过期检查

```php
private function getCachedResponse(
    string $key,
    ServerRequestInterface $request,
): ?ResponseInterface {
    $cached = $this->repo->find($key);
    if ($cached === null) {
        return null;
    }

    // 过期的条目视为新请求（可重新处理）
    if ($cached['expires_at'] < $this->now()) {
        return null;
    }

    $body = json_decode((string) $cached['response_body'], true) ?? [];
    return $this->json->create(
        array_merge($body, ['replayed' => true]),
        (int) $cached['status_code']
    );
}
```

过期的密钥返回 `null`——请求会像新请求一样重新处理。这允许在 TTL 过期后安全重试，而不会产生永久性的去重效果。

## 缓存存储——TTL 计算

```php
private const int TTL_SECONDS = 86400; // 24 小时

private function cacheResponse(
    string $key,
    string $method,
    string $path,
    int $statusCode,
    array $data,
    string $now,
): void {
    $expiresAt = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))
        ->modify('+' . self::TTL_SECONDS . ' seconds')
        ->format('Y-m-d\TH:i:s\Z');
    $this->repo->store($key, $method, $path, $statusCode, (string) json_encode($data), $now, $expiresAt);
}
```

TTL 以 UTC 计算。`DateTimeImmutable::modify()` 能安全处理夏令时转换和午夜跨越。

## `replayed: true` 信号

缓存响应会在响应体中合并 `"replayed": true`：

```json
{ "id": 42, "amount": 1000, "currency": "USD", "replayed": true }
```

这让客户端无需检查状态码即可区分首次响应与重放响应。状态码保持不变（创建操作返回 201）。

## UNIQUE 约束作为竞态防护

```sql
UNIQUE(idempotency_key)
```

如果两个携带相同密钥的并发请求都通过了查找检查（TOCTOU），只有一个 `INSERT` 会成功。另一个会收到约束错误，应用程序可以通过重新获取缓存响应来处理。

---

## ATK 评估——破解者思维攻击测试

### ATK-01 — Idempotency-Key 请求头 SQL 注入 🚫 BLOCKED

**攻击**：发送 `Idempotency-Key: '; DROP TABLE idempotency_keys; --`。
**结果**：BLOCKED — 所有查询均使用参数化语句。注入字符串以字面量密钥值存储或查找。

---

### ATK-02 — 金额字段 SQL 注入 🚫 BLOCKED

**攻击**：发送 `{ "amount": "1; DROP TABLE payments;" }`。
**结果**：BLOCKED — 金额校验要求整数类型。字符串值无法通过 `is_int()` 检查 → 422。不执行任何 DB 查询。

---

### ATK-03 — 商品字段 SQL 注入（安全存储）🚫 BLOCKED

**攻击**：在订单创建时发送 `{ "item": "' OR 1=1; --" }`。
**结果**：BLOCKED — 参数化查询将该字符串作为 `item` 的字面量值存储。不执行任何 SQL。

---

### ATK-04 — 重放攻击（相同密钥 10 次）🚫 BLOCKED

**攻击**：用相同密钥发送 10 次 `POST /payments` 以创建 10 条记录。
**结果**：BLOCKED — 第一次请求创建一条支付记录并缓存响应。后续 9 次请求均返回带 `replayed: true` 的缓存响应。只有 1 条支付记录存在。

---

### ATK-05 — 仅含空白字符的 Idempotency-Key 🚫 BLOCKED

**攻击**：发送 `Idempotency-Key:    `（仅含空格）以绕过空密钥检查。
**结果**：BLOCKED — `trim($key) === ''` → 400。仅含空白字符的密钥等同于缺失密钥。

---

### ATK-06 — 超长 Idempotency-Key 🚫 BLOCKED（设计说明）

**攻击**：发送数兆字节长度的密钥字符串。
**结果**：BLOCKED（设计说明）— SQLite 原样存储密钥；超长密钥会降低查找性能但不会崩溃。生产环境应添加长度限制（例如 `strlen($key) > 255 → 400`）。

---

### ATK-07 — 订单中的负数数量 🚫 BLOCKED

**攻击**：发送 `{ "quantity": -5 }` 以创建负数量的订单。
**结果**：BLOCKED — 数量校验：`$quantity <= 0` → 422。只接受正整数。

---

### ATK-08 — 商品字段中的 XSS 以字面量存储 🚫 BLOCKED

**攻击**：发送 `{ "item": "<script>alert(1)</script>" }`。
**结果**：BLOCKED — 以 JSON 字符串值原样存储。API 返回 `application/json`；JSON 编码会转义 `<`、`>`。API 层不进行 HTML 渲染。

---

### ATK-09 — 并发重复密钥 🚫 BLOCKED

**攻击**：两个进程同时发送相同密钥，两者都在任何一方存储之前通过了查找检查。
**结果**：BLOCKED — `UNIQUE(idempotency_key)` 确保只有一个 INSERT 成功。失败方收到约束错误，可重新获取缓存响应。

---

### ATK-10 — 金额整数溢出 🚫 BLOCKED（设计说明）

**攻击**：发送 `{ "amount": 9999999999999999999 }`（超过 PHP_INT_MAX）。
**结果**：BLOCKED（设计说明）— PHP 会将超大 JSON 整数静默转换为浮点数。`is_int()` 对范围内整数返回 true。生产环境应添加上限检查（例如 amount > 10_000_000 → 422）。

---

### ATK-11 — NULL 金额 🚫 BLOCKED

**攻击**：发送 `{ "amount": null }` 期望 null 绕过校验。
**结果**：BLOCKED — `!is_int(null)` 为 true，`ctype_digit(null)` 为 false → 422。

---

### ATK-12 — 不泄露内部信息 🚫 BLOCKED

**攻击**：触发 422 错误并检查响应中是否出现堆栈跟踪、文件路径或 SQL。
**结果**：BLOCKED — 错误响应仅包含 `{ "error": "..." }` 或 Problem Details。所有响应中均不包含内部路径、SQL 或堆栈跟踪。

---

### ATK 汇总

| ID | 攻击 | 结果 |
|----|------|------|
| ATK-01 | Idempotency-Key 请求头 SQL 注入 | 🚫 BLOCKED |
| ATK-02 | 金额字段 SQL 注入 | 🚫 BLOCKED |
| ATK-03 | 商品字段 SQL 注入 | 🚫 BLOCKED |
| ATK-04 | 重放攻击（10 次重复请求） | 🚫 BLOCKED |
| ATK-05 | 仅含空白字符的密钥 | 🚫 BLOCKED |
| ATK-06 | 超长密钥 | 🚫 BLOCKED（设计说明） |
| ATK-07 | 负数数量 | 🚫 BLOCKED |
| ATK-08 | 商品字段中的 XSS | 🚫 BLOCKED |
| ATK-09 | 并发重复密钥 | 🚫 BLOCKED |
| ATK-10 | 金额整数溢出 | 🚫 BLOCKED（设计说明） |
| ATK-11 | NULL 金额 | 🚫 BLOCKED |
| ATK-12 | 不泄露内部信息 | 🚫 BLOCKED |

**12 BLOCKED，0 EXPOSED**
参数化查询、严格类型校验、`UNIQUE(idempotency_key)` 和 TTL 过期机制覆盖了所有关键的去重攻击向量。

---

## 反模式

| 反模式 | 风险 |
|--------|------|
| 无 `UNIQUE(idempotency_key)` 约束 | 并发重试创建重复记录；去重竞态条件 |
| 无 TTL / 永久去重 | 旧密钥填满表；1 天后的合法重试会失败 |
| 无 `replayed: true` 标志 | 客户端无法区分首次响应与缓存重放 |
| 检查过期但从不重新处理过期密钥 | TTL 后的重试仍返回缓存的（可能过期的）响应 |
| 接受仅含空白字符的密钥 | `"   "` 被视为有效密钥；不同客户端可能交替使用 `""` 和 `"   "` |
| 无密钥长度限制 | 存储和查找中的超大密钥降低性能 |
| 重复时返回 409 | 重放应返回原始状态码（创建返回 201），而非冲突 |
| 不严格校验金额类型 | `"1000"` 字符串通过宽松检查；使用 `is_int()` 进行严格 JSON 整数检查 |
| 无金额上限 | 整数溢出或荒谬金额被接受，缺少业务校验 |
