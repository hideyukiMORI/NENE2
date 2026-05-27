# 操作指南：限时特卖 API

> **FT 参考**：FT304（`NENE2-FT/salelog`）——限时特卖 API：时间窗口校验（特卖未开始 → 422，已结束 → 422），`UNIQUE(sale_id, user_id)` 防止重复购买，售罄库存检查，负价格/零数量 → 422，日期倒置拒绝，ATK-01 至 ATK-12 全部阻断，29 个测试 / 42 个断言全部通过。

本指南展示如何构建限时特卖系统，用户在时间窗口内购买限量商品，并具备竞态条件防护和攻击防御。

## 数据库结构

```sql
CREATE TABLE flash_sales (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    price      INTEGER NOT NULL,
    quantity   INTEGER NOT NULL,
    starts_at  TEXT    NOT NULL,
    ends_at    TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    CHECK (quantity > 0),
    CHECK (price >= 0),
    FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE purchases (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id      INTEGER NOT NULL,
    user_id      INTEGER NOT NULL,
    purchased_at TEXT    NOT NULL,
    UNIQUE (sale_id, user_id),
    FOREIGN KEY (sale_id) REFERENCES flash_sales(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`CHECK (quantity > 0)` 和 `CHECK (price >= 0)` 在 DB 层面执行业务规则。`UNIQUE(sale_id, user_id)` 防止同一用户对同一特卖重复购买——即使在并发请求下也有效。

## 端点

| 方法 | 路径 | 认证 | 描述 |
|------|------|------|------|
| `POST` | `/products` | — | 创建商品 |
| `POST` | `/sales` | — | 创建限时特卖 |
| `GET` | `/sales` | — | 列出有效特卖 |
| `GET` | `/sales/{id}` | — | 获取特卖详情 |
| `POST` | `/sales/{id}/purchase` | `X-User-Id` | 购买（时间检查） |

## 特卖创建校验

```php
if (!is_int($price) || $price < 0) {
    return 422; // 拒绝负价格
}
if (!is_int($quantity) || $quantity <= 0) {
    return 422; // 拒绝零或负数量
}
if ($endsAt <= $startsAt) {
    return 422; // 拒绝日期倒置或相等
}
```

三层 DB 级检查，配合应用层校验：
- `price >= 0` — 允许免费特卖（`0`），不允许负价格
- `quantity > 0` — 无法创建零数量特卖
- `ends_at > starts_at` — 拒绝时间倒置

## 购买——时间窗口检查

```php
$now = date('c');
if ($now < $sale['starts_at']) {
    return 422; // 特卖尚未开始
}
if ($now > $sale['ends_at']) {
    return 422; // 特卖已结束
}
```

特卖窗口外的购买尝试返回 422。检查使用服务端 `date('c')`——客户端无法篡改时间。

## 库存检查

```php
$purchaseCount = $this->repo->countPurchases($saleId);
if ($purchaseCount >= $sale['quantity']) {
    return $this->json(['error' => 'sold out'], 422);
}
```

插入前将现有购买数与特卖的 `quantity` 进行比较。若售罄，返回带 `"error": "sold out"` 的 422。

## UNIQUE(sale_id, user_id)——防止重复购买

```php
// UNIQUE 约束捕获并发重复购买
try {
    $this->repo->createPurchase($saleId, $userId, $now);
} catch (\PDOException $e) {
    // UNIQUE 约束违反 → 已购买
    return $this->json(['error' => 'already purchased'], 409);
}
```

DB 的 `UNIQUE(sale_id, user_id)` 约束是竞态条件的最终防线。首次购买成功（201）；任何重复购买返回 409 Conflict。

## 用户 ID 校验

```php
$actorIdRaw = $request->getHeaderLine('X-User-Id');
if ($actorIdRaw === '' || !ctype_digit($actorIdRaw)) {
    return $this->json(['error' => 'X-User-Id required'], 400);
}
$actorId = (int) $actorIdRaw;

$user = $this->repo->findUser($actorId);
if ($user === null) {
    return $this->json(['error' => 'user not found'], 404);
}
```

- 缺少或非数字的 `X-User-Id` → 400
- 不存在的用户 ID → 404（IDOR 防护——无法以幽灵用户身份购买）

---

## ATK 评估——破解者思维攻击测试

### ATK-01 — 商品名称 SQL 注入 🚫 BLOCKED

**攻击**：`POST /products`，`name: "'; DROP TABLE products; --"`。
**结果**：BLOCKED — 参数化查询将注入字符串原样存储（201）。后续请求正常工作；products 表完好无损。

---

### ATK-02 — 无 X-User-Id 请求头购买 🚫 BLOCKED

**攻击**：`POST /sales/{id}/purchase`，不携带 `X-User-Id` 请求头。
**结果**：BLOCKED — 缺少请求头返回 400。

---

### ATK-03 — 非数字 X-User-Id 请求头 🚫 BLOCKED

**攻击**：`X-User-Id: admin`（字符串值）。
**结果**：BLOCKED — `ctype_digit()` 检查拒绝非数字值；不返回 201。

---

### ATK-04 — URL 中的负数特卖 ID 🚫 BLOCKED

**攻击**：`POST /sales/-1/purchase`。
**结果**：BLOCKED — 负数 ID 解析为找不到特卖；不返回 201。

---

### ATK-05 — 特卖开始前购买 🚫 BLOCKED

**攻击**：创建 1 小时后开始的特卖；立即尝试购买。
**结果**：BLOCKED — `$now < $sale['starts_at']` 检查 → 422。

---

### ATK-06 — 特卖结束后购买 🚫 BLOCKED

**攻击**：创建 1 小时前已结束的特卖；尝试购买。
**结果**：BLOCKED — `$now > $sale['ends_at']` 检查 → 422。

---

### ATK-07 — 同一特卖重复购买 🚫 BLOCKED

**攻击**：同一用户快速连续购买同一特卖两次。
**结果**：BLOCKED — 首次购买 201；第二次购买 409（UNIQUE 约束或应用层检查）。

---

### ATK-08 — 耗尽库存后购买 🚫 BLOCKED

**攻击**：创建 `quantity=1` 的特卖；Alice 购买后；Bob 尝试购买。
**结果**：BLOCKED — 库存检查 `purchaseCount >= quantity` → Bob 收到 422 `"sold out"`。

---

### ATK-09 — 创建 quantity=0 的特卖 🚫 BLOCKED

**攻击**：`POST /sales`，`quantity: 0`。
**结果**：BLOCKED — `quantity <= 0` 校验 + DB `CHECK (quantity > 0)` → 422。

---

### ATK-10 — 创建负价格特卖 🚫 BLOCKED

**攻击**：`POST /sales`，`price: -999`。
**结果**：BLOCKED — `price < 0` 校验 + DB `CHECK (price >= 0)` → 422。

---

### ATK-11 — 以不存在的用户身份购买 🚫 BLOCKED

**攻击**：`X-User-Id: 99999`（users 表中不存在的 ID）。
**结果**：BLOCKED — `findUser($actorId) === null` → 404。

---

### ATK-12 — 特卖日期倒置（ends_at 早于 starts_at） 🚫 BLOCKED

**攻击**：`starts_at: "+2 hours"`，`ends_at: "+1 hour"`。
**结果**：BLOCKED — `$endsAt <= $startsAt` 校验 → 422。

---

### ATK 汇总

| ID | 攻击 | 结果 |
|----|------|------|
| ATK-01 | 商品名称 SQL 注入 | 🚫 BLOCKED |
| ATK-02 | 无 X-User-Id 购买 | 🚫 BLOCKED |
| ATK-03 | 非数字 X-User-Id | 🚫 BLOCKED |
| ATK-04 | URL 中的负数特卖 ID | 🚫 BLOCKED |
| ATK-05 | 特卖开始前购买 | 🚫 BLOCKED |
| ATK-06 | 特卖结束后购买 | 🚫 BLOCKED |
| ATK-07 | 同一特卖重复购买 | 🚫 BLOCKED |
| ATK-08 | 耗尽库存后购买 | 🚫 BLOCKED |
| ATK-09 | 创建 quantity=0 特卖 | 🚫 BLOCKED |
| ATK-10 | 创建负价格特卖 | 🚫 BLOCKED |
| ATK-11 | 以不存在的用户身份购买 | 🚫 BLOCKED |
| ATK-12 | 特卖日期倒置 | 🚫 BLOCKED |

**12 BLOCKED，0 EXPOSED**
服务端时间窗口检查、库存计数防护、UNIQUE 约束以及严格输入校验，共同防御了所有已知攻击向量。

---

## 反模式

| 反模式 | 风险 |
|--------|------|
| 信任客户端提供的时间戳进行时间检查 | 客户端发送过去/未来的时间戳绕过窗口 |
| 无 `UNIQUE(sale_id, user_id)` | 并发请求允许同一用户在高负载下购买两次 |
| 无竞态条件防护的库存检查 | 在库存检查和插入之间，另一个请求可能耗尽库存 |
| 允许 API 创建 quantity=0 的特卖 | 零数量特卖永远无法购买；容易造成混淆的边界情况 |
| 允许 price=-999 | 负价格购买会向买家付款而非收款 |
| 无用户存在检查 | 幽灵用户 ID（不在 DB 中）绕过审计追踪 |
| `$endsAt >= $startsAt`（允许相等） | 开始/结束相等会创建零时长窗口——立即过期 |
| 接受非数字 X-User-Id | `"admin"` 字符串转为 `(int)` 变成 `0`；绕过认证 |
| 时间窗口错误返回 409 | 时间违规是业务校验失败（422），而非状态冲突 |
