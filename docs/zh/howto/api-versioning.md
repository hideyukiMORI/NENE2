# 操作指南：API 版本管理

> **FT 参考**：FT346（`NENE2-FT/versionlog`）——URL 路径版本控制，/v1/ 和 /v2/ 命名空间，已废弃的 V1 携带 Deprecation/Sunset/Link 请求头，V2 使用更丰富的响应结构，共享底层存储，16 个测试全部通过。

本指南展示如何实现 URL 路径版本控制：并行运行两个 API 版本并使用不同的响应结构，通过 HTTP 请求头将旧版本标记为已废弃，并在版本间共享同一个数据库。

## 版本策略

| 版本 | 状态 | 路径前缀 | 列表包装器 |
|---------|-------------|---------|---------------------------|
| V1 | 已废弃 | `/v1/` | `{"notes": [...]}` |
| V2 | 当前版本 | `/v2/` | `{"data": [...], "meta": {...}}` |

两个版本共享相同的数据库表。V1 客户端可以继续使用现有集成，同时废弃请求头告知迁移截止日期。

## 端点

| 方法 | V1 路径 | V2 路径 | 描述 |
|----------|-----------------|-----------------|-----------------|
| `POST` | `/v1/notes` | `/v2/notes` | 创建笔记 |
| `GET` | `/v1/notes` | `/v2/notes` | 列出笔记 |
| `GET` | `/v1/notes/{id}` | `/v2/notes/{id}` | 获取单条笔记 |

## V1 响应结构

```php
// POST /v1/notes
{"title": "Hello", "content": "World"}
→ 201
{
  "id": 1,
  "title": "Hello",
  "content": "World",    // ← 字段名："content"
  "created_at": "..."
  // 无 "body"、"tags"、"updated_at"
}

// GET /v1/notes
→ 200
{
  "notes": [              // ← 包装键："notes"
    {"id": 1, "title": "Hello", "content": "World", ...}
  ]
}
```

## V2 响应结构

```php
// POST /v2/notes
{"title": "Hello", "body": "World", "tags": ["php", "api"]}
→ 201
{
  "data": {               // ← 信封键："data"
    "id": 2,
    "title": "Hello",
    "body": "World",      // ← 字段名："body"
    "tags": ["php", "api"],  // ← 新增 tags
    "updated_at": "...",     // ← 新增 updated_at
    "created_at": "..."
  }
}

// GET /v2/notes
→ 200
{
  "data": [...],          // ← 列表包装器："data"
  "meta": {               // ← meta 部分
    "limit": 20,
    "offset": 0
  }
}
```

## V1 废弃请求头

每个 V1 响应携带三个请求头，通知客户端进行迁移：

```
Deprecation: true
Sunset: Sat, 01 Jan 2027 00:00:00 GMT
Link: </v2/notes>; rel="successor-version"
```

```php
// 每个 V1 端点添加：
return $response
    ->withHeader('Deprecation', 'true')
    ->withHeader('Sunset', 'Sat, 01 Jan 2027 00:00:00 GMT')
    ->withHeader('Link', '</v2/notes>; rel="successor-version"');
```

V2 响应**不**携带这些请求头。

```php
// V1 GET /v1/notes 请求头：
Deprecation: true
Sunset: Sat, 01 Jan 2027 00:00:00 GMT
Link: </v2/notes>; rel="successor-version"

// V2 GET /v2/notes 请求头：
// （无 Deprecation、Sunset 或 Link）
```

## 共享存储——跨版本访问

两个版本共享同一张 `notes` 表。通过 V1 创建的笔记可从 V2 读取（反之亦然）：

```php
// 通过 V1 创建
POST /v1/notes  {"title": "Cross-version", "content": "Shared body"}
→ 201  {"id": 5, "title": "Cross-version", "content": "Shared body", ...}

// 通过 V2 读取——同一条记录，V2 格式
GET /v2/notes/5
→ 200
{
  "data": {
    "id": 5,
    "title": "Cross-version",
    "body": "Shared body",    // V2 称之为 "body"，而非 "content"
    "tags": [],
    "updated_at": "...",
    "created_at": "..."
  }
}
```

V1 客户端永远看不到 `tags`（不在 V1 响应结构中），即使该笔记是通过 V2 写入的。

## 数据库结构

```sql
CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    tags       TEXT    NOT NULL DEFAULT '[]',  -- JSON 数组
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

底层列名为 `body`。V1 在响应转换器中将其映射为 `content`。

## 实现——响应转换器

```php
// V1 转换器——将 "body" 列映射为 "content" 字段，隐藏 tags/updated_at
final class V1NoteTransformer
{
    /** @param array<string, mixed> $row */
    public function transform(array $row): array
    {
        return [
            'id'         => $row['id'],
            'title'      => $row['title'],
            'content'    => $row['body'],   // 字段重命名
            'created_at' => $row['created_at'],
            // 无 "body"、"tags"、"updated_at"
        ];
    }
}

// V2 转换器——完整行，包装在 "data" 中
final class V2NoteTransformer
{
    /** @param array<string, mixed> $row */
    public function transform(array $row): array
    {
        return [
            'id'         => $row['id'],
            'title'      => $row['title'],
            'body'       => $row['body'],
            'tags'       => json_decode($row['tags'], true),
            'updated_at' => $row['updated_at'],
            'created_at' => $row['created_at'],
        ];
    }
}
```

## 路由注册

```php
// V1Registrar::register()
$router->get('/v1/notes',       [V1ListHandler::class, 'handle']);
$router->post('/v1/notes',      [V1CreateHandler::class, 'handle']);
$router->get('/v1/notes/{id}',  [V1GetHandler::class, 'handle']);

// V2Registrar::register()
$router->get('/v2/notes',       [V2ListHandler::class, 'handle']);
$router->post('/v2/notes',      [V2CreateHandler::class, 'handle']);
$router->get('/v2/notes/{id}',  [V2GetHandler::class, 'handle']);
```

两个注册器都传递给 `RuntimeApplicationFactory`——两者的路由都注册到同一个路由器中。

## 未知版本 → 404

```php
GET /v3/notes
→ 404
```

不存在 V3 路由；路由器返回 404。不需要"版本不支持"错误类型——404 已足够。

## 验证

```php
POST /v1/notes  {"content": "no title"}
→ 422  // title 是必填的

POST /v2/notes  {"body": "no title"}
→ 422  // title 是必填的
```

两个版本都要求 `title`。V1 接受 `content` 作为正文字段；V2 接受 `body`。

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 每个版本使用不同的数据库表 | 跨版本读取失效；版本间不共享状态时数据迁移困难 |
| 在 V2 上返回 `Deprecation: true` | 客户端无法区分哪个版本是当前版本 |
| 不带后继版本的 `Link` 请求头 | 废弃的客户端不知道迁移到哪里 |
| 将 DB 列 `body` 重命名为 `content` 以适配 V1 | 所有 V2 代码都必须修改；应使用响应转换器重命名而非修改数据库结构 |
| 在测试中硬编码 Sunset 日期 | Sunset 日期过后测试失败；使用未来的常量或配置值 |
| 在响应中暴露 V1 的 `tags` | V1 客户端收到不理解的字段；响应结构契约悄然失效 |
