# 操作指南：事件分析 API

> **FT 参考**：FT243（`NENE2-FT/statslog`）——事件分析 API
> **VULN**：FT243——漏洞评估（V-01 至 V-10）

演示一个事件采集和聚合 API：原始分析事件以 JSON `properties` blob 记录，通过 SQLite `json_extract()` 查询，并聚合为每日/每类型/唯一用户统计数据。包含对无认证设计的完整漏洞评估。

---

## 路由

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/events` | 记录分析事件 |
| `GET` | `/events` | 列出事件（分页） |
| `GET` | `/events/by-property` | 按 JSON 属性键值过滤事件 |
| `GET` | `/events/{id}` | 获取单条事件 |
| `GET` | `/stats/per-day` | 按日期分组的事件计数 |
| `GET` | `/stats/per-type` | 按事件类型分组的计数 |
| `GET` | `/stats/unique-users` | 按日期分组的唯一用户数 |

> **静态路由优先于参数化路由**：`/events/by-property` 必须在 `/events/{id}` 之前注册，确保路由器正确分发字面路径。

---

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type  TEXT    NOT NULL,
    user_id     TEXT    NOT NULL,
    session_id  TEXT    NOT NULL DEFAULT '',
    properties  TEXT    NOT NULL DEFAULT '{}',
    occurred_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_events_type     ON events(event_type);
CREATE INDEX IF NOT EXISTS idx_events_occurred ON events(occurred_at);
CREATE INDEX IF NOT EXISTS idx_events_user     ON events(user_id);
```

`properties` 以 JSON 字符串（`TEXT`）存储。SQLite 的 `json_extract()` 允许在读取时查询 blob，无需独立的表结构。三个索引覆盖最常见的访问模式：按类型、按时间范围和按用户。

---

## 事件创建：JSON properties blob

`POST /events` 接受灵活的 `properties` 对象，以及必填的 `event_type` 和 `user_id`：

```php
$eventType  = trim((string) $body['event_type']);
$userId     = trim((string) $body['user_id']);
$sessionId  = isset($body['session_id']) && is_string($body['session_id']) ? $body['session_id'] : '';
$properties = isset($body['properties']) && is_array($body['properties'])
    ? json_encode($body['properties'], JSON_THROW_ON_ERROR)
    : '{}';
$occurredAt = isset($body['occurred_at']) && is_string($body['occurred_at'])
    ? $body['occurred_at']
    : (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
```

- `properties` 必须是 JSON 对象（`is_array()` 检查）——标量值回退到 `'{}'`。
- `occurred_at` 由调用者提供或默认为当前时间——服务端不强制其在有效范围内。
- `JSON_THROW_ON_ERROR` 确保格式错误的中间 JSON 立即抛出异常，而非产生 `false`。

读取时的反序列化：
```php
'properties' => json_decode($event->properties, true, 512, JSON_THROW_ON_ERROR),
```

---

## 使用 `json_extract()` 进行 JSON 属性搜索

`GET /events/by-property?key=page&value=/home` 按属性键值过滤事件：

```php
$rows = $this->executor->fetchAll(
    'SELECT * FROM events WHERE json_extract(properties, ?) = ? ORDER BY occurred_at DESC LIMIT ? OFFSET ?',
    ['$.' . $propertyKey, $propertyValue, $limit, $offset],
);
```

`json_extract(properties, '$.page')` 从 JSON blob 中提取 `page` 字段。路径 `'$.' . $propertyKey` 通过拼接构建，**不**将路径本身参数化——SQLite 的 `json_extract()` 只接受字面路径字符串，而非路径表达式的绑定参数。键来自查询字符串但未进一步校验（见 V-05）。

`= ?` 将提取的值与提供的 `$propertyValue` 进行参数化绑定比较——通过值注入的 SQL 注入被阻断。路径拼接是需要审计的边界。

---

## 聚合查询

### 每日事件计数

```php
$rows = $this->executor->fetchAll(
    "SELECT strftime('%Y-%m-%d', occurred_at) AS day, COUNT(*) AS count
     FROM events
     WHERE occurred_at >= ? AND occurred_at < ?
     GROUP BY strftime('%Y-%m-%d', occurred_at)
     ORDER BY day ASC",
    [$from, $to],
);
```

`strftime('%Y-%m-%d', occurred_at)` 将时间戳截断为日期。对同一表达式使用 `GROUP BY` 将同一天的所有事件分组。`$from` 和 `$to` 都参数化——SQL 中无字符串拼接。

### 每类型事件计数

```php
$rows = $this->executor->fetchAll(
    'SELECT event_type, COUNT(*) AS count
     FROM events
     WHERE occurred_at >= ? AND occurred_at < ?
     GROUP BY event_type
     ORDER BY count DESC',
    [$from, $to],
);
```

`ORDER BY count DESC` 将最频繁的事件类型显示在最前。

### 每日唯一用户数

```php
$rows = $this->executor->fetchAll(
    "SELECT strftime('%Y-%m-%d', occurred_at) AS day, COUNT(DISTINCT user_id) AS unique_users
     FROM events
     WHERE occurred_at >= ? AND occurred_at < ?
     GROUP BY strftime('%Y-%m-%d', occurred_at)
     ORDER BY day ASC",
    [$from, $to],
);
```

`COUNT(DISTINCT user_id)` 每天只计算每个 `user_id` 一次。

### 日期范围默认值

```php
private function parseDateRange(ServerRequestInterface $request): array
{
    $from = QueryStringParser::string($request, 'from') ?? '2000-01-01T00:00:00Z';
    $to   = QueryStringParser::string($request, 'to') ?? '2100-01-01T00:00:00Z';

    return [$from, $to];
}
```

宽泛的默认值（`2000-01-01` 到 `2100-01-01`）确保没有日期范围的统计包含所有事件。在生产环境中，将默认范围限制在合理窗口内（例如最近 30 天），以避免对大数据集进行全表扫描。

---

## VULN——漏洞评估（FT243）

### V-01 — 无认证：任何人都可以记录事件

**风险**：任何调用者都可以提交带有任意 `event_type` 和 `user_id` 的事件。没有 API 密钥、会话或令牌检查。

**影响**：攻击者可以用数百万条虚假事件污染分析数据集，扭曲统计数据，并冒充任何用户 ID。

**结论**：**EXPOSED**——为写入端点添加 API 密钥或 JWT 认证。只读统计可以保持公开，但数据采集必须经过认证。

---

### V-02 — 统计端点无授权：统计数据对全球可读

**风险**：`GET /stats/per-day`、`/stats/per-type`、`/stats/unique-users` 在没有任何认证的情况下返回聚合数据。

**影响**：竞争对手或爬虫可以监控产品使用趋势、每日活跃用户和功能采用情况。

**结论**：**EXPOSED**——将统计端点限制为认证角色（管理员、分析查看者）。如果统计数据有意公开，请将其记录为设计决策。

---

### V-03 — `user_id` 由用户提供：无身份验证

**风险**：`user_id` 直接从请求体获取，无需任何调用者拥有该身份的证明。

```json
{"event_type": "login", "user_id": "alice", "occurred_at": "2026-01-01T00:00:00Z"}
```

**影响**：攻击者可以为任意用户 ID 伪造活动，操纵每用户统计和唯一用户计数。

**结论**：**EXPOSED**——在认证上下文中，应从令牌/会话中的已验证身份派生 `user_id`，而非从请求体获取。

---

### V-04 — `occurred_at` 由用户提供：事件可被回溯或提前日期

**风险**：`occurred_at` 字段从调用者接受，无范围校验。

```json
{"event_type": "purchase", "user_id": "alice", "occurred_at": "2020-01-01T00:00:00Z"}
```

**影响**：攻击者可以将事件插入任意历史时间段（回溯）或遥远的未来，扭曲时间序列统计。

**结论**：**EXPOSED**——校验 `occurred_at` 落在可接受的窗口内（例如最近 24 小时到 +5 分钟），并拒绝超出范围的时间戳。

---

### V-05 — `json_extract()` 路径拼接：JSON 路径注入

**风险**：属性键被直接拼接到 JSON 路径表达式中：`'$.' . $propertyKey`。没有校验 `$propertyKey` 是否是安全标识符。

**攻击**：
```
GET /events/by-property?key=x%22%5D+OR+1%3D1+--&value=y
```
变为：`json_extract(properties, '$.x"] OR 1=1 --')`——SQLite 将路径参数解释为传递给 `json_extract` 的字符串字面量，而非 SQL。该路径不作为 SQL 执行——它由 SQLite 的 JSON 函数作为字符串处理。无效路径返回 `NULL`，因此查询不返回任何行而非所有行。

**观察**：`json_extract()` 将整个第二个参数视为路径表达式。格式错误的路径（`$.x"] OR 1=1 --`）对每一行返回 `NULL`——没有 SQL 注入。但该行为依赖于 SQLite 的 JSON 实现——纵深防御方法是用 `preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)` 验证 `$propertyKey`。

**结论**：**部分 BLOCKED**——SQLite 的 `json_extract()` 沙盒化路径参数。添加显式键校验（`[a-zA-Z_][a-zA-Z0-9_]*`）以实现纵深防御。

---

### V-06 — 无界 event_type：无白名单

**风险**：`event_type` 接受任何非空字符串。超长字符串或高基数类型会使 `countPerType` 结果集膨胀。

```json
{"event_type": "aaaa....(10000 个字符)", "user_id": "x"}
```

**影响**：`GROUP BY event_type` 中无界的基数可能导致内存压力。超长字符串导致存储膨胀。

**结论**：**EXPOSED**——添加最大长度检查（例如 100 个字符），并可选地添加事件类型白名单或长度限制。

---

### V-07 — 通过 `from`/`to` 日期参数的 SQL 注入

**攻击**：在日期范围参数中传递 SQL 元字符。

```
GET /stats/per-day?from=2000-01-01%27+OR+%271%27%3D%271&to=2100-01-01
```

**观察**：`$from` 和 `$to` 都作为参数化值绑定（`?` 占位符）。SQL 引擎将它们视为字面字符串，而非 SQL 片段。

**结论**：**BLOCKED**——参数化查询防止通过日期参数的 SQL 注入。

---

### V-08 — properties 无大小限制：JSON blob 不受大小约束

**风险**：`properties` 以 `TEXT` 存储，无大小校验。攻击者可以提交数兆字节的 JSON 对象。

```json
{"event_type": "x", "user_id": "y", "properties": {"data": "AAAA....(1MB)"}}
```

**影响**：每个大型事件消耗大量存储。批量插入大型事件可能耗尽磁盘空间。

**结论**：**EXPOSED**——对原始 `properties` 值添加大小检查（例如 `strlen($raw) > 65535 → 422`）。依靠请求大小中间件作为外部限制。

---

### V-09 — 事件洪水：POST /events 无速率限制

**风险**：采集端点没有速率限制。

**影响**：单个客户端每秒可以提交数百万个事件，使数据库和存储不堪重负。

**结论**：**EXPOSED**——在写入端点应用 `ThrottleMiddleware` 或每 IP / 每 API 密钥的速率限制。

---

### V-10 — 统计暴露：`COUNT(DISTINCT user_id)` 泄露用户数量

**风险**：`GET /stats/unique-users` 返回每天不同用户 ID 的计数。

**影响**：没有认证，这会泄露每日活跃用户数——这是敏感的商业指标。

**结论**：**EXPOSED**（与 V-02 根本原因相同）。限制或要求认证才能访问统计端点。

---

## VULN 总结

| # | 漏洞 | 结论 |
|---|------|------|
| V-01 | 写入端点无认证 | EXPOSED |
| V-02 | 统计端点对全球可读 | EXPOSED |
| V-03 | `user_id` 未验证（身份欺骗） | EXPOSED |
| V-04 | `occurred_at` 由用户提供（回溯/提前日期） | EXPOSED |
| V-05 | `json_extract()` 路径拼接 | 部分 BLOCKED |
| V-06 | `event_type` 无白名单/长度限制 | EXPOSED |
| V-07 | 通过日期范围参数的 SQL 注入 | BLOCKED |
| V-08 | `properties` JSON blob 无大小限制 | EXPOSED |
| V-09 | POST /events 无速率限制 | EXPOSED |
| V-10 | 唯一用户数泄露 DAU 指标 | EXPOSED |

**生产前必须修复**：
1. **V-01 / V-02 / V-10**——为写入和统计端点添加认证（API 密钥或 JWT）
2. **V-03**——从已验证身份派生 `user_id`，而非从请求体获取
3. **V-04**——校验 `occurred_at` 落在可接受的时间窗口内
4. **V-05**——添加 `preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)` 校验
5. **V-06**——添加 `event_type` 最大长度检查（例如 100 个字符）
6. **V-08**——添加 `properties` 大小限制（例如 64 KB）
7. **V-09**——在 POST /events 上应用速率限制

---

## 相关操作指南

- [`event-sourcing.md`](event-sourcing.md) ——不可变事件日志模式
- [`api-usage-metering.md`](api-usage-metering.md) ——带配额执行的计量 API
- [`quota-management.md`](quota-management.md) ——带 QuotaWindow 的每资源配额
- [`cursor-pagination.md`](cursor-pagination.md) ——高容量事件信息流的高效分页
