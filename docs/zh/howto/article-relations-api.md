# 操作指南：文章关联 API

> **FT 参考**：FT334（`NENE2-FT/relatedlog`）——带自动反向创建的类型化文章间关联，对称和非对称关联类型，按类型过滤，以及 GET 响应中嵌入的关联摘要，17 个测试 / 40+ 个断言全部通过。

本指南展示如何对内容项之间的类型化关系进行建模——`related`、`sequel`、`prequel`、`reference`——并通过自动反向管理确保每个关联在两个方向上保持一致。

## 数据库结构

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE article_relations (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id    INTEGER NOT NULL REFERENCES articles(id),
    related_id    INTEGER NOT NULL REFERENCES articles(id),
    relation_type TEXT    NOT NULL,
    created_at    TEXT    NOT NULL,
    UNIQUE(article_id, related_id, relation_type)
);
```

`UNIQUE(article_id, related_id, relation_type)` 防止同一类型的重复关联边。同一对文章之间允许有不同类型的关联。

## 关联类型与反向关联

| 提交的类型 | 自动创建的反向关联 |
|---|---|
| `related` | `related`（对称） |
| `sequel` | `prequel` |
| `prequel` | `sequel` |
| `reference` | `reference`（对称） |

当 A→B 为 `sequel` 时，服务器原子性地插入 B→A 为 `prequel`。删除 A→B 同时删除 B→A。

## 端点

| 方法 | 路径 | 描述 |
|--------|------|-------------|
| `POST` | `/articles` | 创建文章 |
| `GET` | `/articles/{id}` | 获取带嵌入关联的文章 |
| `POST` | `/articles/{id}/relations` | 添加关联 |
| `GET` | `/articles/{id}/relations` | 列出关联（可选 ?type=） |
| `DELETE` | `/articles/{id}/relations/{relatedId}?type=` | 删除关联（及其反向关联） |

## 创建文章

```php
POST /articles
{"title": "Hello", "body": "World"}
→ 201  {"id": 1, "title": "Hello", "body": "World", "created_at": "..."}

// 缺少 title
POST /articles  {"body": "No title"}
→ 422

// 缺少 body
POST /articles  {"title": "No body"}
→ 422
```

## GET 文章含嵌入关联

```php
GET /articles/1
→ 200
{
  "data": {"id": 1, "title": "Intro", ...},
  "relations": [
    {
      "relation": {"relation_type": "sequel"},
      "related":  {"id": 2, "title": "Follow-up"}
    }
  ]
}

// 尚无关联
GET /articles/1
→ 200  {"data": {...}, "relations": []}

GET /articles/9999
→ 404
```

## 添加关联

```php
POST /articles/1/relations
{"related_id": 2, "relation_type": "sequel"}
→ 201  {"relation_type": "sequel", "article_id": 1, "related_id": 2}

// 反向关联自动插入：文章 2 现在有一个指向文章 1 的 "prequel" 关联
GET /articles/2/relations
→ 200  {"data": [{"relation_type": "prequel", "related_id": 1}]}
```

### 对称关联

```php
POST /articles/1/relations
{"related_id": 2, "relation_type": "related"}
→ 201

// B 也自动获得一个指向 A 的 "related" 关联
GET /articles/2/relations
→ 200  {"data": [{"related_id": 1, "relation_type": "related"}]}
```

### 错误情况

```php
// 未知的 related_id
POST /articles/1/relations  {"related_id": 9999, "relation_type": "related"}
→ 404

// 重复（相同的文章对+相同类型已存在）
POST /articles/1/relations  {"related_id": 2, "relation_type": "related"}
→ 409

// 自我关联
POST /articles/1/relations  {"related_id": 1, "relation_type": "related"}
→ 422

// 无效的关联类型
POST /articles/1/relations  {"related_id": 2, "relation_type": "not-a-type"}
→ 422
```

### 同一对文章之间的多种类型

同一对文章可以有多种不同的关联类型：

```php
POST /articles/1/relations  {"related_id": 2, "relation_type": "related"}   → 201
POST /articles/1/relations  {"related_id": 2, "relation_type": "reference"} → 201

GET /articles/1/relations
→ 200  {"data": [
    {"related_id": 2, "relation_type": "related"},
    {"related_id": 2, "relation_type": "reference"}
  ]}
```

## 列出关联

```php
// 所有关联
GET /articles/1/relations
→ 200  {"data": [{...}, {...}]}

// 按类型过滤
GET /articles/1/relations?type=sequel
→ 200  {"data": [{"related_id": 2, "relation_type": "sequel"}]}

// 未知文章
GET /articles/9999/relations
→ 404
```

## 删除关联

```php
DELETE /articles/1/relations/2?type=related
→ 200  {"deleted": true}

// 反向关联也自动删除
GET /articles/2/relations
→ 200  {"data": []}  // 不再有指向文章 1 的 "related" 关联

// 未找到
DELETE /articles/1/relations/2?type=related
→ 404

// 缺少 type 查询参数
DELETE /articles/1/relations/2
→ 422
```

## 实现——原子反向管理

```php
private function addRelation(int $articleId, int $relatedId, string $type): void
{
    $this->db->beginTransaction();

    $inverse = match ($type) {
        'sequel'    => 'prequel',
        'prequel'   => 'sequel',
        default     => $type,   // related、reference → 对称
    };

    $this->repo->insert($articleId, $relatedId, $type);
    $this->repo->insert($relatedId, $articleId, $inverse);

    $this->db->commit();
}

private function removeRelation(int $articleId, int $relatedId, string $type): void
{
    $inverse = match ($type) {
        'sequel'    => 'prequel',
        'prequel'   => 'sequel',
        default     => $type,
    };

    $this->db->beginTransaction();
    $this->repo->delete($articleId, $relatedId, $type);
    $this->repo->delete($relatedId, $articleId, $inverse);
    $this->db->commit();
}
```

将两次插入/删除包裹在事务中——如果其中一次失败，两次都不提交。

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 插入关联前不检查文章是否存在 | 外键约束违规或静默的 0 行插入；对未知 ID 始终返回 404 |
| 正向+反向插入没有事务保护 | 部分失败导致非对称数据（A→B 存在但 B→A 不存在） |
| 没有 `UNIQUE(article_id, related_id, relation_type)` | 重复边导致列表计数虚高 |
| 允许自我关联 | 关联遍历中的循环；对自身的 `sequel` 没有意义 |
| 对所有类型硬编码对称假设 | `sequel`→`sequel`（错误）而非 `prequel` |
| 只删除正向边 | 反向孤儿依然存在；A 被删除后 B 仍然"看到" A 是一个前传 |
