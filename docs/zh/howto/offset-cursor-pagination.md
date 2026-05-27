# 操作指南：偏移量与游标分页

> **FT 参考**：FT325（`NENE2-FT/pagelog`）——双重分页策略（基于偏移量和基于游标），带 `next_offset`/`next_cursor`、`has_more`、分类过滤，15 个测试 / 47 个断言全部通过。

本指南展示如何为同一资源实现基于偏移量和基于游标两种分页端点，让客户端选择适合其使用场景的策略。

## 数据库结构

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    author     TEXT    NOT NULL,
    category   TEXT    NOT NULL DEFAULT 'general',
    created_at TEXT    NOT NULL
);
```

## 端点

| 方法 | 路径 | 描述 |
|--------|------|-------------|
| `POST` | `/articles` | 创建文章 |
| `GET`  | `/articles/offset` | 偏移量分页 |
| `GET`  | `/articles/cursor` | 游标分页 |
| `GET`  | `/articles/by-category` | 分类过滤 |

## 偏移量分页

```
GET /articles/offset?limit=10&offset=0
→ 200
{
  "items": [...],     // 10 条
  "total": 25,
  "limit": 10,
  "offset": 0,
  "has_more": true,
  "next_offset": 10   // 最后一页时为 null
}

// 第 2 页
GET /articles/offset?limit=10&offset=10
→ {"items": [...], "has_more": true, "next_offset": 20}

// 最后一页
GET /articles/offset?limit=10&offset=20
→ {"items": [...], "has_more": false, "next_offset": null}

// 超出末尾
GET /articles/offset?limit=10&offset=100
→ {"items": [], "has_more": false}
```

有 `has_more` 时 `next_offset = offset + limit`，否则为 `null`。

## 游标分页

```
GET /articles/cursor?limit=10
→ 200
{
  "items": [...],        // 最新在前
  "has_more": true,
  "next_cursor": 15      // 最后返回条目的 id
}

// 使用游标翻到下一页
GET /articles/cursor?limit=10&after=15
→ {"items": [...], "has_more": true, "next_cursor": 5}

// 最后一页
GET /articles/cursor?limit=10&after=5
→ {"items": [...], "has_more": false, "next_cursor": null}
```

游标是最后返回条目的 `id`：`WHERE id < $after ORDER BY id DESC LIMIT $limit + 1`（多读一条以判断 `has_more`）。

## 分类过滤

```
GET /articles/by-category?category=tech&limit=5
→ {"items": [...], "total": N}
```

## 偏移量与游标——何时使用

| 标准 | 偏移量 | 游标 |
|-----------|--------|--------|
| 随机页面跳转 | ✅ `?offset=50` | ❌ 必须逐页遍历 |
| 需要总数 | ✅ 始终包含 | ❌ 代价高昂 |
| 插入时结果一致性 | ❌ 新行会使页面偏移 | ✅ 稳定 |
| 大数据集性能 | ❌ `OFFSET N` 扫描 N 行 | ✅ `WHERE id < X` 使用索引 |
| 无限滚动 / 信息流 | ❌ | ✅ |

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 最后一页也返回 `next_offset` | 客户端发出多余的空请求 |
| 对百万行表使用 `OFFSET N` | DB 在返回结果前扫描 N 行；大数据请使用游标 |
| 游标响应中省略 `has_more` | 客户端无法知道是否还有下一页 |
| 使用时间戳作为游标 | 重复时间戳导致跳过或重复行；使用唯一整数 id |
