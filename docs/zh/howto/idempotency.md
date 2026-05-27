# 操作指南：幂等性密钥模式

> **FT 参考**：FT276（`NENE2-FT/csrflog`）——状态变更请求的 Idempotency-Key 请求头：UNIQUE DB 约束，重放返回原始结果（200），重放时忽略请求体变更，竞态条件由 DatabaseConstraintException 处理，15 个测试 / 30 个断言全部通过。
>
> **ATK 评估**：ATK-01 至 ATK-12 包含在本文档末尾。

通过要求客户端在每个状态变更请求上提供 `Idempotency-Key` 请求头，防止因网络重试导致的重复订单或重复资源创建。

## 为什么这很重要

当客户端发送 `POST /orders` 后网络在收到响应前断开，它会重试。没有幂等性机制时，该重试会创建第二个订单。有了 `Idempotency-Key`，服务端可以检测到重试并返回原始结果，而不是创建副本。

Stripe、GitHub 以及许多其他生产 API 都使用这一模式。

## 数据库结构

在幂等性密钥列上添加 `UNIQUE` 约束。这个单一约束处理了下面描述的竞态条件。

```sql
CREATE TABLE orders (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    idempotency_key  TEXT    NOT NULL UNIQUE,
    item             TEXT    NOT NULL,
    quantity         INTEGER NOT NULL,
    total_price      REAL    NOT NULL,
    created_at       TEXT    NOT NULL
);
```

## 处理器实现

```php
// 1. 读取并校验请求头
$key = trim($request->getHeaderLine('Idempotency-Key'));
if ($key === '') {
    return $problems->create(
        $request,
        'missing-idempotency-key',
        'Idempotency-Key header is required for this endpoint.',
        [],
        422,
    );
}

// 2. 检查是否存在已有条目（重放路径）
$existing = $repo->findByIdempotencyKey($key);
if ($existing !== null) {
    return $json->create($existing->toArray(), 200); // 重放——返回原始结果，200
}

// 3. 校验请求体
$body = json_decode((string) $request->getBody(), true);
// ... 校验字段 ...

// 4. 创建——UNIQUE 约束处理竞态条件
try {
    $order = $repo->create($key, $item, $quantity, $totalPrice);
    return $json->create($order->toArray(), 201);
} catch (DatabaseConstraintException) {
    // 另一个相同密钥的请求抢先完成——返回其结果
    $existing = $repo->findByIdempotencyKey($key);
    if ($existing !== null) {
        return $json->create($existing->toArray(), 200);
    }
    return $problems->create($request, 'conflict', 'Conflict.', [], 409);
}
```

## 数据仓库

```php
public function findByIdempotencyKey(string $key): ?Order
{
    $row = $this->executor->fetchOne(
        'SELECT * FROM orders WHERE idempotency_key = ?',
        [$key],
    );
    return $row !== null ? Order::fromRow($row) : null;
}

public function create(string $key, string $item, int $quantity, float $totalPrice): Order
{
    // UNIQUE 冲突（竞态条件）时抛出 DatabaseConstraintException
    $this->executor->insert(
        'INSERT INTO orders (idempotency_key, item, quantity, total_price, created_at) VALUES (?, ?, ?, ?, ?)',
        [$key, $item, $quantity, $totalPrice, (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)],
    );
    // ...
}
```

## 关键设计决策

### 重放返回 200 而非 201

第二个请求是重放，不是创建。使用 `200 OK` 告知客户端"你之前见过这个"，避免对创建内容产生困惑。

### 重放忽略请求体

如果客户端用相同的 `Idempotency-Key` 发送了不同的请求体，返回的是**原始**结果。服务端将匹配的密钥视为该请求已被处理的证明，无论请求体内容如何。

```
POST /orders  Idempotency-Key: uuid-abc  body: {quantity: 1, price: 9.99}
→ 201 Created  {id: 1, quantity: 1}

POST /orders  Idempotency-Key: uuid-abc  body: {quantity: 99, price: 0.01}
→ 200 OK  {id: 1, quantity: 1}   ← 原始订单，请求体被忽略
```

这是有意为之。如果客户端想创建真正不同的资源，必须使用新的密钥。

### UNIQUE 约束作为竞态条件防护

两个携带相同密钥的并发请求会产生竞争。DB 的 `UNIQUE` 约束确保只有一个插入成功。失败方捕获 `DatabaseConstraintException` 并获取成功方的记录。

## 客户端应使用什么作为密钥

UUID v4 是最常见的选择。客户端在发送请求前生成密钥并本地存储，以便在需要时用相同的密钥重试。

```js
// 客户端（JavaScript）
const key = crypto.randomUUID();
const response = await fetch('/orders', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'Idempotency-Key': key,
    },
    body: JSON.stringify({ item: 'Widget', quantity: 1, price: 9.99 }),
});
```

## 读取请求头

PSR-7 请求头名称不区分大小写。`getHeaderLine('Idempotency-Key')`、`getHeaderLine('idempotency-key')` 和 `getHeaderLine('IDEMPOTENCY-KEY')` 返回相同的值。NENE2 使用正确实现了此规范的 Nyholm/PSR-7。

---

## ATK 评估——破解者思维攻击测试

### ATK-01 — 省略 Idempotency-Key 以绕过重复检查 🚫 BLOCKED

**攻击**：发送不含 `Idempotency-Key` 请求头的 `POST /orders`。
**结果**：BLOCKED — `trim($request->getHeaderLine('Idempotency-Key')) === ''` → 422 并返回 `missing-idempotency-key` Problem Details。不创建任何订单。

---

### ATK-02 — 发送空的 Idempotency-Key 🚫 BLOCKED

**攻击**：发送 `Idempotency-Key: `（仅含空白字符）。
**结果**：BLOCKED — `trim()` 将仅含空白字符的字符串转为 `''` → 与 ATK-01 相同的 422。

---

### ATK-03 — 用修改后的请求体重放以更改订单内容 🚫 BLOCKED

**攻击**：用密钥 `uuid-abc` 和 `{quantity: 1}` 发送 `POST /orders`。重放时使用相同密钥但 `{quantity: 99}`。
**结果**：BLOCKED — 服务端通过 `idempotency_key` 找到已有记录并立即返回，甚至在读取请求体之前。新请求体从未被处理。

---

### ATK-04 — 用不同密钥创建两个订单 ✅ SAFE（预期行为）

**攻击**：使用两个不同的 `Idempotency-Key` 值合法地创建两个订单。
**结果**：SAFE（符合设计）— 不同密钥是不同的请求。两个订单都被创建。这是预期行为：幂等性是按密钥的，而非按请求体的。

---

### ATK-05 — 竞态条件：两个并发请求使用相同密钥 🚫 BLOCKED

**攻击**：在任一请求完成前并发发送两个相同请求。
**结果**：BLOCKED — 两个请求都通过了 `findByIdempotencyKey` 检查（尚无已有记录），但只有一个 INSERT 成功。失败方捕获 `DatabaseConstraintException`，获取成功方的记录并以 200 返回。UNIQUE 约束是竞态防护。

---

### ATK-06 — 负数数量注入 🚫 BLOCKED

**攻击**：发送 `{item: "widget", quantity: -1, price: 9.99}` 并携带有效密钥。
**结果**：BLOCKED — `if ($quantity <= 0)` → 422 校验错误。不创建任何订单。

---

### ATK-07 — 零数量注入 🚫 BLOCKED

**攻击**：发送 `{item: "widget", quantity: 0, price: 9.99}`。
**结果**：BLOCKED — 相同的 `quantity <= 0` 防护 → 422。

---

### ATK-08 — 缺少必填请求体字段 🚫 BLOCKED

**攻击**：发送不含 `item` 字段的 `{quantity: 1}`。
**结果**：BLOCKED — `if ($item === '')` → 422 校验错误。

---

### ATK-09 — 通过跨域浏览器请求的 CSRF 🚫 BLOCKED（设计）

**攻击**：恶意网站从浏览器发起跨域 `POST /orders` 请求。
**结果**：BLOCKED（符合设计）— JSON API 要求 `Content-Type: application/json`。浏览器 CSRF 攻击只能通过 `<form>` 发送表单编码或纯文本请求体，无需预检请求。JSON 请求体会触发 CORS 预检请求；服务端的 CORS 策略决定是否允许跨域写入。此外，要求 `Idempotency-Key` 提供了次要保护，因为伪造请求无法预测唯一密钥。

---

### ATK-10 — 负数价格注入 🚫 BLOCKED

**攻击**：发送 `{item: "widget", quantity: 1, price: -100.0}`。
**结果**：BLOCKED — `if ($price < 0)` → 422 校验错误。

---

### ATK-11 — 浮点数/字符串数量强制转换 🚫 BLOCKED

**攻击**：发送 `{quantity: "1"}` 或 `{quantity: 1.5}`（字符串或浮点数）。
**结果**：BLOCKED — `is_int($body['quantity'])` 拒绝字符串和浮点数；`1.5` 是浮点数 → 422。

---

### ATK-12 — 通过 Idempotency-Key 的 SQL 注入 🚫 BLOCKED

**攻击**：发送 `Idempotency-Key: '; DROP TABLE orders; --`。
**结果**：BLOCKED — 密钥仅用于参数化查询（`WHERE idempotency_key = ?`）。通过请求头值进行 SQL 注入不可行。

---

### ATK 汇总

| ID | 攻击 | 结果 |
|----|------|------|
| ATK-01 | 缺少 Idempotency-Key | 🚫 BLOCKED |
| ATK-02 | 空/仅含空白字符的密钥 | 🚫 BLOCKED |
| ATK-03 | 用修改后的请求体重放 | 🚫 BLOCKED |
| ATK-04 | 不同密钥 = 不同订单 | ✅ SAFE（预期行为） |
| ATK-05 | 相同密钥的竞态条件 | 🚫 BLOCKED |
| ATK-06 | 负数数量 | 🚫 BLOCKED |
| ATK-07 | 零数量 | 🚫 BLOCKED |
| ATK-08 | 缺少请求体字段 | 🚫 BLOCKED |
| ATK-09 | 通过跨域 POST 的 CSRF | 🚫 BLOCKED |
| ATK-10 | 负数价格 | 🚫 BLOCKED |
| ATK-11 | 浮点数/字符串数量强制转换 | 🚫 BLOCKED |
| ATK-12 | 通过密钥请求头的 SQL 注入 | 🚫 BLOCKED |

**12 BLOCKED / SAFE，0 EXPOSED**
幂等性密钥模式、参数化查询和严格的 `is_int()` 校验防御了所有测试的攻击向量。
