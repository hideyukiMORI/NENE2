# 操作指南：文章定时发布

> **FT 参考**：FT330（`NENE2-FT/pubschedulelog`）——文章草稿/定时/发布/归档生命周期、仅作者可访问草稿、公开已发布文章、定时发布触发，34 个测试 / 95 个断言全部通过。

本指南展示如何构建带延迟发布的文章管理系统：作者编写草稿，安排未来发布时间，后台任务（或 API 调用）将其转换为已发布状态。

## 数据库结构

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id  INTEGER NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    status     TEXT    NOT NULL DEFAULT 'draft',   -- draft | scheduled | published | archived
    publish_at TEXT,                               -- ISO-8601，未定时时为 NULL
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);
```

## 状态转换

```
draft ──publish──► published ──archive──► archived
  │
  └──schedule──► scheduled ──(时间到)──► published
  │                  │
  │               unschedule
  │                  │
  └──────────────────┘
```

只允许有效的转换——无效转换返回 409。

## 端点

| 方法 | 路径 | 说明 |
|--------|------|-------------|
| `POST`  | `/articles` | 创建草稿（需要 `X-User-Id`） |
| `GET`   | `/articles/{id}` | 获取（草稿：仅作者；已发布：公开） |
| `PUT`   | `/articles/{id}` | 更新草稿（需要 `X-User-Id`） |
| `POST`  | `/articles/{id}/publish` | 立即发布 |
| `POST`  | `/articles/{id}/schedule` | 定时发布 |
| `POST`  | `/articles/{id}/unschedule` | 返回草稿 |
| `POST`  | `/articles/{id}/archive` | 归档已发布文章 |
| `GET`   | `/articles` | 列出（带 `?status=` 过滤） |
| `POST`  | `/publish-due` | 触发超过 publish_at 的定时文章 |

## 创建草稿

```php
POST /articles  X-User-Id: 1
{"title": "Hello", "body": "World"}
→ 201  {"id": 1, "status": "draft", "author_id": 1}

// 无认证 → 401
```

## 可见性规则

```php
// 草稿：仅作者
GET /articles/1  X-User-Id: 1  → 200   // 作者可以看到自己的草稿
GET /articles/1  X-User-Id: 2  → 404   // 其他用户看不到草稿
GET /articles/1               → 404   // 无认证，草稿隐藏

// 已发布：任何人
GET /articles/1               → 200   // 公开
```

## 发布与归档

```php
POST /articles/1/publish  X-User-Id: 1  → 200  {"status": "published"}
POST /articles/1/archive  X-User-Id: 1  → 200  {"status": "archived"}

// 不能归档草稿
POST /articles/1/archive  X-User-Id: 1  → 409
```

## 定时发布

```php
// 定时在 1 小时后发布
POST /articles/1/schedule  X-User-Id: 1
{"publish_at": "2026-05-27T15:00:00+09:00"}
→ 200  {"status": "scheduled", "publish_at": "2026-05-27T15:00:00+09:00"}

// 过去的时间 → 422
POST /articles/1/schedule  X-User-Id: 1
{"publish_at": "2020-01-01T00:00:00Z"}
→ 422

// 取消定时 → 返回草稿
POST /articles/1/unschedule  X-User-Id: 1
→ 200  {"status": "draft", "publish_at": null}
```

## 触发定时文章

定时任务或管理员端点将所有 `publish_at <= now` 的定时文章转换为已发布：

```php
POST /publish-due
→ 200  {"published_count": 3}
```

## 列出文章

```php
GET /articles?status=published      → 200  // 公开，无需认证
GET /articles?status=draft  X-User-Id: 1  → 200  // 只有自己的草稿
```

---

## 不应做的事

| 反模式 | 风险 |
|---|---|
| 向未认证用户显示草稿 | 泄露未发布内容 |
| 允许在过去定时 | 文章会通过触发任务"立即"发布，绕过审核 |
| 在测试中使用实时 now() 进行定时触发 | 测试变得依赖时间；在测试中使用强制插入带过去 `publish_at` 的记录 |
| 归档时硬删除 | 丢失审计记录；使用 status 字段 |
| 允许从 archived → published 转换 | 带回已删除内容；要求明确重新发布 |
