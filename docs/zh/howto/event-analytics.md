# 操作指南：事件分析 API

> **FT 参考**：FT51（`NENE2-FT/statslog`）——带 JSON 属性过滤和聚合查询的事件分析 API

演示一个事件追踪 API，以任意 JSON 属性存储分析事件，并暴露聚合端点提供每日计数、每类型分布和唯一用户指标。关键模式：`json_extract()` 属性过滤、`strftime()` 日期分桶、静态路由优先于参数化路由，以及字符串类型的用户 ID。

---

## 路由

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/events` | 记录事件 |
| `GET` | `/events` | 列出事件（分页） |
| `GET` | `/events/by-property` | 按 JSON 属性键值过滤 |
| `GET` | `/events/{id}` | 获取单条事件 |
| `GET` | `/stats/per-day` | 每日历日的事件计数（`?from=&to=`） |
| `GET` | `/stats/per-type` | 每事件类型的计数（`?from=&to=`） |
| `GET` | `/stats/unique-users` | 每日唯一用户数（`?from=&to=`） |

---

## 记录事件

```php
// POST /events
$body = [
    'event_type'  => 'page_view',          // 必填，非空字符串
    'user_id'     => 'usr_abc123',          // 必填，字符串（UUID 或不透明 ID）
    'session_id'  => 'sess_xyz789',         // 可选
    'properties'  => ['path' => '/pricing', 'referrer' => 'google'],  // 可选对象
    'occurred_at' => '2026-05-27T09:00:00Z', // 可选，ISO 8601（默认为服务器时间）
];
```

`properties` 以 JSON 字符串存储。输出时解码回对象：

```php
'properties' => json_decode($event->properties, true, 512, JSON_THROW_ON_ERROR),
```

省略 `occurred_at` 时，服务器用当前 UTC 时间填充：

```php
$occurredAt = isset($body['occurred_at']) && is_string($body['occurred_at'])
    ? $body['occurred_at']
    : (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
```

---

## 路由顺序：静态路由优先于参数化路由

路由器按注册顺序匹配路由。像 `/events/by-property` 这样的静态路径必须在参数化的 `/events/{id}` **之前**注册，否则 `by-property` 段会被捕获为 `{id}`：

```php
public function register(Router $router): void
{
    $router->post('/events', $this->createEvent(...));
    $router->get('/events', $this->listEvents(...));

    // ✓ 静态路由优先——否则 "by-property" 会被 {id} 吞掉
    $router->get('/events/by-property', $this->eventsByProperty(...));
    $router->get('/events/{id}', $this->showEvent(...));

    $router->get('/stats/per-day', $this->statsPerDay(...));
    $router->get('/stats/per-type', $this->statsPerType(...));
    $router->get('/stats/unique-users', $this->statsUniqueUsers(...));
}
```

**规则**：始终在同一深度级别的通配符段之前注册具体路径段。

---

## 使用 `json_extract()` 进行 JSON 属性过滤

SQLite（≥ 3.38）和 MySQL 支持 `json_extract()` 来查询存储的 JSON 列内容。键作为参数化的 JSONPath 表达式传递：

```php
$rows = $this->executor->fetchAll(
    'SELECT * FROM events WHERE json_extract(properties, ?) = ? ORDER BY occurred_at DESC LIMIT ? OFFSET ?',
    ['$.' . $propertyKey, $propertyValue, $limit, $offset],
);
```

JSONPath 前缀 `$.` 在 PHP 中追加，因此 `key = "path"` 变为 `json_extract(properties, '$.path')`。由于两个参数都是参数化的，即使 `$propertyKey` 包含特殊字符也不存在 SQL 注入风险。

> **深度限制**：`$.path` 访问顶层。对于嵌套访问（`$.browser.name`），调用者传递 `browser.name` 作为键。深层路径可能出人意料——在 OpenAPI 规范中记录支持的键形式。

---

## 使用 `strftime()` 进行日期聚合

```sql
SELECT strftime('%Y-%m-%d', occurred_at) AS day,
       COUNT(*) AS count
FROM events
WHERE occurred_at >= ? AND occurred_at < ?
GROUP BY strftime('%Y-%m-%d', occurred_at)
ORDER BY day ASC
```

`strftime('%Y-%m-%d', ...)` 将 ISO 8601 日期时间字符串截断为日期部分。当 `occurred_at` 以 UTC 存储时（例如 `2026-05-27T09:00:00Z`），这在 SQLite 中有效。带非 UTC 偏移量存储的时间会按原始字符串分桶，而非转换为本地时间——如果日期边界语义重要，写入时请规范化为 UTC。

---

## 每日唯一用户数计算

```sql
SELECT strftime('%Y-%m-%d', occurred_at) AS day,
       COUNT(DISTINCT user_id) AS unique_users
FROM events
WHERE occurred_at >= ? AND occurred_at < ?
GROUP BY strftime('%Y-%m-%d', occurred_at)
ORDER BY day ASC
```

`COUNT(DISTINCT user_id)` 返回每个分桶中不同 `user_id` 值的数量。当 `user_id` 是稳定的外部标识符（UUID、哈希设备 ID 等）时，这是每日活跃用户（DAU）的近似值。

---

## 字符串类型的 user_id

`user_id` 以 `TEXT NOT NULL` 存储，而非整数外键。这种设计适应：

- UUID（`usr_01HQ...`）
- 来自身份提供者的不透明字符串标识符
- 账户创建前的匿名会话令牌

由于该字段是自由格式文本，分析层不与用户数据模型耦合。没有 `REFERENCES users(id)` 外键——事件可以在用户账户创建前后记录。

---

## 默认日期范围回退

聚合端点接受 `?from=` 和 `?to=` 查询参数。省略时，默认跨越很宽的范围：

```php
$from = QueryStringParser::string($request, 'from') ?? '2000-01-01T00:00:00Z';
$to   = QueryStringParser::string($request, 'to')   ?? '2100-01-01T00:00:00Z';
```

这对演示很方便，但在大型生产数据集上可能很昂贵。在生产环境中，要求显式日期范围并限制最大跨度（参见 [`shift-management.md`](shift-management.md) 中的限制模式）。

---

## 数据库结构和索引

```sql
CREATE TABLE events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type  TEXT    NOT NULL,
    user_id     TEXT    NOT NULL,
    session_id  TEXT    NOT NULL DEFAULT '',
    properties  TEXT    NOT NULL DEFAULT '{}',
    occurred_at TEXT    NOT NULL
);

CREATE INDEX idx_events_type     ON events(event_type);
CREATE INDEX idx_events_occurred ON events(occurred_at);
CREATE INDEX idx_events_user     ON events(user_id);
```

三个索引覆盖三种主要查询模式：
- `idx_events_occurred`——日期范围聚合（`WHERE occurred_at >= ? AND < ?`）
- `idx_events_type`——类型过滤（`WHERE event_type = ?`）
- `idx_events_user`——用户历史查找（`WHERE user_id = ?`）

SQLite 中 `properties` 上的 `json_extract()` 查询在没有生成列的情况下不支持索引。对于高容量属性过滤，考虑添加生成列：

```sql
ALTER TABLE events ADD COLUMN prop_path TEXT GENERATED ALWAYS AS (json_extract(properties, '$.path')) STORED;
CREATE INDEX idx_events_prop_path ON events(prop_path);
```

---

## PHP 中的 properties 编码

`properties` 字段接受调用者传入的任何 JSON 对象并将其存储为字符串：

```php
$properties = isset($body['properties']) && is_array($body['properties'])
    ? json_encode($body['properties'], JSON_THROW_ON_ERROR)
    : '{}';
```

`is_array($body['properties'])` 拒绝 JSON 标量和数组（数组在 PHP 中解码为 array，但不是对象）。使用 `JSON_THROW_ON_ERROR` 确保编码失败抛出异常，而非静默返回 `false`。

序列化时，properties 解码回 PHP 数组，并作为嵌套对象嵌入响应：

```php
'properties' => json_decode($event->properties, true, 512, JSON_THROW_ON_ERROR),
```

---

## 相关操作指南

- [`admin-report-aggregation.md`](admin-report-aggregation.md) ——管理员报告的 SQL 聚合模式
- [`shift-management.md`](shift-management.md) ——日期范围限制、聚合查询
- [`pagination.md`](pagination.md) ——`PaginationQueryParser` 和 `PaginationResponse`
- [`iso-datetime-validation.md`](iso-datetime-validation.md) ——`occurred_at` 的 ISO 8601 往返校验
