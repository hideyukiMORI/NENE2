# 操作指南：带审计日志的状态机

> **FT 参考**：FT237（`NENE2-FT/statemachinelog`）— 带审计日志的状态机
> **VULN**：FT237 — 安全/漏洞评估（V-01 到 V-10）

演示一个状态机 API，其中每次转换都记录在不可变的审计日志表中。当前状态存储在订单上；完整历史记录存储在独立的 `order_transitions` 表中。`InvalidTransitionException` 提供带 `from` 和 `to` 上下文的结构化 409 响应。

---

## 路由

| 方法 | 路径 | 说明 |
|------|------|------|
| `POST` | `/orders` | 创建订单（初始状态为 `draft`） |
| `GET` | `/orders/{id}` | 获取当前订单状态 |
| `POST` | `/orders/{id}/transitions` | 应用状态转换 |
| `GET` | `/orders/{id}/transitions` | 列出完整转换历史 |

---

## 状态机：允许的转换

```php
enum OrderStatus: string
{
    case Draft     = 'draft';
    case Submitted = 'submitted';
    case Approved  = 'approved';
    case Rejected  = 'rejected';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft     => [self::Submitted, self::Cancelled],
            self::Submitted => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved  => [],
            self::Rejected  => [],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
```

终态（`approved`、`rejected`、`cancelled`）返回空列表——无法进一步转换。

---

## InvalidTransitionException → 带上下文的 409

当调用者请求非法转换时，异常携带 from 和 to 状态作为结构化数据用于错误响应：

```php
final class InvalidTransitionException extends \RuntimeException
{
    public function __construct(OrderStatus $from, OrderStatus $to)
    {
        parent::__construct(
            sprintf('Transition from "%s" to "%s" is not allowed.', $from->value, $to->value)
        );
    }
}
```

控制器在 Problem Details 扩展中包含 `from` 和 `to`：

```php
try {
    $updated = $this->repo->transition($id, $targetEnum, $now);
} catch (InvalidTransitionException $e) {
    return $this->problems->create(
        $request,
        'invalid-transition',
        'Invalid State Transition',
        409,
        $e->getMessage(),
        ['from' => $order->status->value, 'to' => $targetEnum->value],
    );
}
```

响应：
```json
{
  "type": "https://nene2.dev/problems/invalid-transition",
  "title": "Invalid State Transition",
  "status": 409,
  "detail": "Transition from \"approved\" to \"submitted\" is not allowed.",
  "from": "approved",
  "to": "submitted"
}
```

`from` 和 `to` 让调用者准确了解哪个转换被拒绝，无需解析 `detail` 字符串。

---

## 转换审计日志：双写模式

每次成功的转换原子地更新订单状态并插入日志记录：

```php
public function transition(int $orderId, OrderStatus $targetStatus, string $now): Order
{
    $order = $this->findById($orderId);

    if (!$order->status->canTransitionTo($targetStatus)) {
        throw new InvalidTransitionException($order->status, $targetStatus);
    }

    // 更新当前状态
    $this->executor->execute(
        'UPDATE orders SET status = ?, updated_at = ? WHERE id = ?',
        [$targetStatus->value, $now, $orderId],
    );

    // 追加到审计日志
    $this->executor->execute(
        'INSERT INTO order_transitions (order_id, from_status, to_status, transitioned_at) VALUES (?, ?, ?, ?)',
        [$orderId, $order->status->value, $targetStatus->value, $now],
    );

    return new Order($order->id, $order->title, $targetStatus, $order->createdAt, $now);
}
```

> **原子性注意**：没有包装事务时，UPDATE 和 INSERT 之间的失败会让订单处于新状态但没有日志记录。将两条语句包裹在事务中以实现真正的原子性。SQLite 的 WAL 模式在并发访问下使这一操作安全。

---

## 数据库结构：订单状态 + 转换历史

```sql
CREATE TABLE IF NOT EXISTS orders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    status     TEXT NOT NULL DEFAULT 'draft',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS order_transitions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id        INTEGER NOT NULL,
    from_status     TEXT    NOT NULL,
    to_status       TEXT    NOT NULL,
    transitioned_at TEXT    NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders (id)
);
```

`order_transitions` 按设计是仅追加的——它不存在 UPDATE 或 DELETE 端点。完整的转换历史被保留用于审计。

---

## 转换历史响应

```json
{
  "order_id": 1,
  "transitions": [
    {"id": 1, "order_id": 1, "from_status": "draft", "to_status": "submitted", "transitioned_at": "2026-05-27 10:00:00"},
    {"id": 2, "order_id": 1, "from_status": "submitted", "to_status": "approved", "transitioned_at": "2026-05-27 11:00:00"}
  ]
}
```

列表按 `id ASC` 排序，因此历史是按时间顺序的。

---

## VULN — 安全评估（FT237）

### V-01 — 所有端点无认证

**攻击**：无凭据创建订单并应用转换。

```bash
curl -s -X POST http://localhost:8200/orders/1/transitions \
  -H 'Content-Type: application/json' \
  -d '{"status":"approved"}'
```

**观察结果**：`200 OK`——不需要令牌。任何人都可以批准或取消任何订单。

**结论**：**EXPOSED**（FT237 演示的设计选择）。添加认证和授权：在角色（提交者 vs 审核者）后面设置转换门控，并将每个订单限制为其所有者。

---

### V-02 — 无效状态值

**攻击**：发送未知状态字符串。

```json
{"status": "hacked"}
{"status": ""}
```

**观察结果**：`OrderStatus::tryFrom('hacked')` = `null` → `422`，附带列出所有有效状态的错误。

**结论**：**BLOCKED** — backed enum 的 `tryFrom()` 拒绝未知值。

---

### V-03 — 非法转换（终态 → 活跃）

**攻击**：尝试从 `approved` 或 `cancelled` 转换到另一个状态。

```json
{"status": "submitted"}   // 从 approved
{"status": "draft"}       // 从 cancelled
```

**观察结果**：`canTransitionTo()` 返回 `false` → `InvalidTransitionException` → `409 Conflict`，响应体中包含 `from`/`to` 上下文。

**结论**：**BLOCKED** — 状态机在领域层强制执行所有转换规则。

---

### V-04 — 非数字订单 ID

**攻击**：将字符串或浮点数作为 `{id}` 传入。

```
GET /orders/abc
GET /orders/1.5
```

**观察结果**：`(int) 'abc'` = 0，`(int) '1.5'` = 1。对于 `abc`，`findById(0)` 返回 `null` → `404 Not Found`。对于 `1.5`，如果订单 1 存在，它会被返回——静默截断。

**结论**：**PARTIALLY BLOCKED** — 非数字字符串解析为 404。浮点数被静默截断。添加 `ctype_digit()` 守护以进行严格校验。

---

### V-05 — 转换历史未作用域限定到调用者

**攻击**：读取其他用户的转换历史。

```
GET /orders/1/transitions
```

**观察结果**：`200 OK`——无任何所有权或认证检查即返回完整历史。历史记录揭示了谁提交、批准或取消了订单（通过时间戳，虽然没有记录操作者）。

**结论**：**EXPOSED** — 没有所有权模型。在订单中添加 `created_by` 字段，并将历史读取限制为所有者或授权审核者。

---

### V-06 — 通过 `status` 请求体字段的 SQL 注入

**攻击**：在 `status` 值中嵌入 SQL 元字符。

```json
{"status": "'; DROP TABLE orders; --"}
{"status": "approved' OR '1'='1"}
```

**观察结果**：
1. `OrderStatus::tryFrom("'; DROP TABLE orders; --")` = `null` → 在任何 SQL 之前 `422`。
2. 即使检查被绕过，状态也作为参数化 `?` 值传递。

**结论**：**BLOCKED** — 双层防护：枚举白名单 + 参数化查询。

---

### V-07 — 转换到相同状态（幂等性）

**攻击**：发送到当前状态的转换。

```json
// 订单已经是 'submitted'
{"status": "submitted"}
```

**观察结果**：`submitted` 的 `allowedTransitions()` 是 `[approved, rejected, cancelled]`——`submitted` 不在列表中。`canTransitionTo(submitted)` 返回 `false` → `409 Conflict`。

**结论**：**BLOCKED** — 状态机隐式拒绝自转换。

---

### V-08 — 同一订单的并发转换

**攻击**：为同一订单发送两个同时的转换请求。

```
POST /orders/1/transitions {"status":"approved"}  // 并发请求 A
POST /orders/1/transitions {"status":"rejected"}  // 并发请求 B
```

**观察结果**：两个请求在任何 UPDATE 运行之前都获取订单（状态 = `submitted`）。两者都看到 `canTransitionTo()` = true。两者都执行 UPDATE——第二个 UPDATE 覆盖第一个。每个请求插入一条转换日志记录，但订单最终处于最后运行的状态。历史显示两次转换，这是不一致的（例如 `submitted → approved`，然后 `submitted → rejected`）。

**结论**：**EXPOSED** — 将 `findById` + `canTransitionTo` + `UPDATE` + `INSERT` 序列包裹在单个事务中以防止竞争条件。

---

### V-09 — 仅空白字符的标题

**攻击**：创建带空白标题的订单。

```json
{"title": "   "}
```

**观察结果**：`trim($body['title'])` 简化为 `""` → `title === ''` 检查触发 → `422 Unprocessable Entity`。

**结论**：**BLOCKED** — 空字符串检查前的 `trim()` 处理仅空白字符输入。

---

### V-10 — 无界标题长度

**攻击**：创建标题极长的订单。

```json
{"title": "A".repeat(100_000)}
```

**观察结果**：没有强制长度限制——很长的标题存储在 `TEXT` 列中没有限制。

**结论**：**EXPOSED** — 添加长度守护：
```php
if (mb_strlen($title) > 500) {
    $errors[] = ['field' => 'title', 'code' => 'too_long', 'message' => 'title must not exceed 500 characters.'];
}
```

---

## VULN 汇总

| # | 攻击向量 | 结论 |
|---|---------|------|
| V-01 | 无认证 | EXPOSED |
| V-02 | 无效状态值 | BLOCKED |
| V-03 | 从终态的非法转换 | BLOCKED |
| V-04 | 非数字订单 ID | PARTIALLY BLOCKED |
| V-05 | 转换历史未作用域限定到调用者 | EXPOSED |
| V-06 | 通过状态请求体的 SQL 注入 | BLOCKED |
| V-07 | 自转换（相同状态） | BLOCKED |
| V-08 | 并发转换竞争条件 | EXPOSED |
| V-09 | 仅空白字符的标题 | BLOCKED |
| V-10 | 无界标题长度 | EXPOSED |

**生产前需修复的真实漏洞**：
1. **V-01 / V-05** — 添加认证和授权（所有权作用域）
2. **V-08** — 将转换包裹在事务中
3. **V-10** — 添加标题长度限制
4. **V-04** — 为 ID 参数添加 `ctype_digit()` 守护

---

## 相关指南

- [`approval-workflow.md`](approval-workflow.md) — 带独立操作端点的枚举状态机
- [`audit-trail.md`](audit-trail.md) — 仅追加审计日志模式
- [`transactions.md`](transactions.md) — 将多写序列包裹在事务中
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — IDOR 防护
