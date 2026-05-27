# 操作指南：评分与评价 API

> **FT 参考**：FT333（`NENE2-FT/ratinglog`）——按条目、按用户的评分系统，包含分数校验（1–5）、upsert 语义、带分布明细的汇总以及漏洞评估，16 个测试 / 40+ 断言全部通过。

本指南展示如何构建评分系统：用户提交数字分数及可选的文字评价，API 实时计算聚合汇总。

## 数据库结构

```sql
CREATE TABLE ratings (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    item_id    TEXT    NOT NULL,
    rater_id   TEXT    NOT NULL,
    score      INTEGER NOT NULL CHECK (score BETWEEN 1 AND 5),
    review     TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE(item_id, rater_id)
);
```

`UNIQUE(item_id, rater_id)` 强制每个评分者对每个条目只能评一次。`item_id` 和 `rater_id` 是不透明的字符串标识符——不需要外键约束。

## 端点

| 方法 | 路径 | 说明 |
|--------|------|-------------|
| `PUT`  | `/items/{itemId}/ratings/{raterId}` | 创建或更新评分（upsert） |
| `GET`  | `/items/{itemId}/ratings` | 列出条目的所有评分 |
| `GET`  | `/items/{itemId}/ratings/summary` | 带分布的聚合汇总 |
| `GET`  | `/items/{itemId}/ratings/{raterId}` | 获取某个评分者的评分 |
| `DELETE` | `/items/{itemId}/ratings/{raterId}` | 删除评分 |

## 创建/更新评分（Upsert）

```php
PUT /items/product-1/ratings/alice
{"score": 5, "review": "Excellent!"}
→ 200  {"rater_id": "alice", "score": 5, "review": "Excellent!", ...}

// 更新现有评分
PUT /items/product-1/ratings/alice
{"score": 3, "review": "Changed my mind."}
→ 200  {"score": 3}
```

带 `UNIQUE(item_id, rater_id)` 的 `PUT` 充当自然 upsert（`INSERT OR REPLACE`）。同一端点处理创建和更新，无需单独的 `PATCH`。

### 校验

```php
// 缺少 score
PUT /items/product-1/ratings/alice  {"review": "Nice"}
→ 422

// 超出范围
PUT /items/product-1/ratings/alice  {"score": 6}
→ 422

PUT /items/product-1/ratings/alice  {"score": 0}
→ 422
```

score 必须是 [1, 5] 范围内的整数。`review` 是可选的（默认为 `""`）。

## 列出评分

```php
GET /items/product-1/ratings
→ 200
{
  "ratings": [
    {"rater_id": "alice", "score": 5, "review": "Excellent!"},
    {"rater_id": "bob",   "score": 3, "review": ""}
  ]
}
```

评分按条目作用域——`product-2` 的评分不会出现在 `product-1` 的列表中。

## 带分布的汇总

```php
GET /items/product-1/ratings/summary
→ 200
{
  "count": 3,
  "average": 4.0,
  "distribution": {
    "1": 0, "2": 0, "3": 1, "4": 1, "5": 1
  }
}

// 还没有评分
GET /items/product-2/ratings/summary
→ 200  {"count": 0, "average": 0.0, "distribution": {"1":0,"2":0,"3":0,"4":0,"5":0}}
```

`distribution` 始终返回所有五个键，即使计数为零——客户端渲染星级条时无需 null 检查。

## 获取单个评分

```php
GET /items/product-1/ratings/alice
→ 200  {"score": 4, "review": "..."}

GET /items/product-1/ratings/nobody
→ 404
```

## 删除评分

```php
DELETE /items/product-1/ratings/alice
→ 200  {"deleted": true}

DELETE /items/product-1/ratings/nobody
→ 404
```

删除后，下次请求时汇总会立即重新计算。

```php
// 之前：alice(5) + bob(1)，average=3.0
DELETE /items/product-1/ratings/bob

// 之后：只有 alice(5)
GET /items/product-1/ratings/summary
→ 200  {"count": 1, "average": 5.0}
```

---

## 漏洞评估

### V-01 — 评分冒充（raterId 的 IDOR）⚠️ EXPOSED

**风险**：任何客户端都可以使用任意 `raterId` 路径段提交或删除评分。
**发现**：EXPOSED——URL 中的 `raterId` 没有针对已认证的行为者进行校验。攻击者可以以 `raterId: "competitor"` 提交 1 星评价或删除其他用户的评价。缓解措施：认证评分者（session、JWT 或 `X-User-Id` 头）并拒绝已认证身份与路径 `raterId` 不匹配的请求。

---

### V-02 — 分数范围绕过 ✅ SAFE

**风险**：攻击者提交 `score: 0` 或 `score: 6` 以产生无效数据或扭曲平均值。
**发现**：✅ SAFE——在任何 DB 写入之前，score 已校验为 `[1, 5]`。超出范围的值返回 422。DB 层的 `CHECK (score BETWEEN 1 AND 5)` 提供二级防护。

---

### V-03 — 通过大量虚假评分污染平均值 ⚠️ EXPOSED

**风险**：攻击者注册数千个用户 ID 并提交 1 星评分，使产品平均分下降。
**发现**：EXPOSED——评分端点没有强制执行限流或账户验证。缓解措施：在评分前要求账户年龄/邮箱验证；应用按 IP 和按用户的限流；检测统计异常（低分的突然激增）。

---

### V-04 — 通过评价文本的 XSS ✅ SAFE

**风险**：攻击者在 `review` 中存储 `<script>alert(1)</script>`，在渲染评价 HTML 的客户端上执行 JavaScript。
**发现**：✅ SAFE——API 返回 `application/json`。JSON 编码对 HTML 特殊字符进行转义（`<`、`>`、`&`）。只要客户端将 JSON 值解析并渲染为文本（而非 `innerHTML`），存储型 XSS 就被防止了。建议在服务器端进行 HTML 编码作为额外防护层。

---

### V-05 — 通过 itemId / raterId 的 SQL 注入 ✅ SAFE

**风险**：攻击者发送 `item_id = "x' OR '1'='1"` 或 `rater_id = "'; DROP TABLE ratings--"` 来操纵查询。
**发现**：✅ SAFE——所有查询使用参数化语句（`?` 占位符）。路径段作为绑定值传递，从不插值到 SQL 字符串中。

---

### V-06 — 无限制评价文本（存储滥用）⚠️ EXPOSED

**风险**：攻击者提交 100 MB 的评价字符串以耗尽数据库/内存资源。
**发现**：EXPOSED——`review` 没有强制执行 `max_length` 检查。缓解措施：添加 `MAX_REVIEW_LENGTH` 常量（例如 2000 个字符），超出时返回 422。请求大小中间件提供二级防护。

---

### V-07 — 汇总平均值整数截断 ✅ SAFE

**风险**：计算 3 个评分的平均值（5+3+4=12，12/3=4.0）在某些 DB 引擎上可能损失精度。
**发现**：✅ SAFE——SQLite 中的 `AVG()` 返回浮点数。PHP 在编码前将结果转换为 `float`。不使用 `(int)(5+3)/2` 风格的截断。

---

### V-08 — 分布键缺失（客户端崩溃）✅ SAFE

**风险**：如果 `distribution` 省略零评分数的键，访问 `distribution[1]` 的客户端会崩溃并出现 `undefined`。
**发现**：✅ SAFE——API 始终返回所有五个键（`1`–`5`），初始化为 `0`。客户端不需要防御性的 null 检查。

---

### V-09 — 跨条目数据泄露 ✅ SAFE

**风险**：`GET /items/product-1/ratings` 返回 `product-2` 的评分。
**发现**：✅ SAFE——所有查询包含 `WHERE item_id = ?`。隔离测试明确验证了 `product-2` 的评分不出现在 `product-1` 的列表中。

---

### V-10 — 浮点 score 绕过整数校验 ✅ SAFE

**风险**：攻击者发送 `score: 4.9`（四舍五入为 5）或 `score: 5.1`（四舍五入为 5 或 6）以绕过范围检查。
**发现**：✅ SAFE——score 作为严格整数进行校验。JSON 浮点数在任何范围检查之前就通不过类型校验，返回 422。

---

### VULN 汇总

| ID | 漏洞 | 发现 |
|----|---------------|---------|
| V-01 | 评分冒充（raterId 的 IDOR） | ⚠️ EXPOSED |
| V-02 | 分数范围绕过 | ✅ SAFE |
| V-03 | 通过大量虚假评分污染平均值 | ⚠️ EXPOSED |
| V-04 | 通过评价文本的 XSS | ✅ SAFE |
| V-05 | 通过 itemId / raterId 的 SQL 注入 | ✅ SAFE |
| V-06 | 无限制评价文本（存储滥用） | ⚠️ EXPOSED |
| V-07 | 汇总平均值整数截断 | ✅ SAFE |
| V-08 | 分布键缺失 | ✅ SAFE |
| V-09 | 跨条目数据泄露 | ✅ SAFE |
| V-10 | 浮点 score 绕过整数校验 | ✅ SAFE |

**7 SAFE，3 EXPOSED** — 关键：认证 `raterId`；添加 `review` 长度上限；针对大量虚假评分应用限流。

---

## 不应做的事

| 反模式 | 风险 |
|---|---|
| 信任路径中的 `raterId` 而不认证 | 任何客户端都可以以任意用户身份评分或删除 |
| 评价文本没有 `max_length` | 存储炸弹——单个请求向 DB 写入几 GB 数据 |
| 对零计数的分布键返回 `null` | 访问 `distribution[2]` 的客户端代码崩溃 |
| 在 PHP 中用 `array_sum` 重新计算平均值 | 大数据集上的有损浮点运算；让 DB 执行 `AVG()` |
| 没有按用户限流 | 大量虚假账户毒化产品平均分 |
| 使用 `SELECT * FROM ratings` 而没有 `WHERE item_id` | 跨条目数据泄露 |
