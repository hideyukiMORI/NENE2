# 内容关联——带类型的 M:N 自引用链接

使用**带 `relation_type` 列的中间表**将文章（或任何资源）相互关联。支持不对称类型（续集 ↔ 前传），自动插入反向关联，以及带有相同反向逻辑的对称类型（related、reference）。

**参考实现**：[hideyukiMORI/NENE2-examples](https://github.com/hideyukiMORI/NENE2-examples) 中的 `FT173 relatedlog`

---

## 何时使用此模式

| 适用场景 | 考虑替代方案 |
|---|---|
| 资源之间通过带类型的边相互链接 | 只需要无类型的"related"链接 |
| 需要不对称边（A 是 B 的续集） | 简单的标签系统已足够 |
| 双向查询需要保持高效 | 需要跨多跳的图遍历 |
| 关联类型驱动 UI 行为（"查看续集"） | — |

---

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
    article_id    INTEGER NOT NULL,
    related_id    INTEGER NOT NULL,
    relation_type TEXT    NOT NULL,
    -- 'related' | 'sequel' | 'prequel' | 'reference'
    created_at    TEXT    NOT NULL,
    UNIQUE (article_id, related_id, relation_type),
    FOREIGN KEY (article_id) REFERENCES articles(id),
    FOREIGN KEY (related_id) REFERENCES articles(id),
    CHECK (article_id != related_id)      -- 数据库层面防止自我关联
);
```

### 设计说明

- `UNIQUE (article_id, related_id, relation_type)` 约束防止相同类型的重复边。同一对可以有**多种**类型（例如，A → B 既是 `related` 又是 `reference`）。
- `CHECK (article_id != related_id)` 在数据库层面防止自循环。
- **存储两个方向**：添加 `A → B (sequel)` 时也会插入 `B → A (prequel)`。这使得按文章查询变得简单（`WHERE article_id = ?`），无需 JOIN。

---

## 关联类型

```php
enum RelationType: string
{
    case Related   = 'related';    // 对称：A related B ↔ B related A
    case Sequel    = 'sequel';     // 不对称：A sequel→B ↔ B prequel→A
    case Prequel   = 'prequel';    // 不对称：sequel 的反向
    case Reference = 'reference';  // 对称：双向引用

    public function inverse(): self
    {
        return match ($this) {
            self::Sequel  => self::Prequel,
            self::Prequel => self::Sequel,
            default       => $this,  // related、reference 是自反的
        };
    }
}
```

---

## 核心操作：带自动反向的添加关联

```php
public function addRelation(int $articleId, int $relatedId, RelationType $type, string $now): ArticleRelation
{
    // 1. 验证两篇文章都存在
    // 2. 检查重复（UNIQUE 约束也会捕获这个）
    $existing = $this->db->fetchOne(
        'SELECT id FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
        [$articleId, $relatedId, $type->value],
    );
    if ($existing !== null) {
        throw new RelationAlreadyExistsException($articleId, $relatedId, $type);
    }

    // 3. 插入正向关联
    $id = $this->db->insert(
        'INSERT INTO article_relations (article_id, related_id, relation_type, created_at) VALUES (?, ?, ?, ?)',
        [$articleId, $relatedId, $type->value, $now],
    );

    // 4. 插入反向（如果尚不存在）
    $inverse = $type->inverse();
    $inverseExists = $this->db->fetchOne(
        'SELECT id FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
        [$relatedId, $articleId, $inverse->value],
    );
    if ($inverseExists === null) {
        $this->db->insert(
            'INSERT INTO article_relations (article_id, related_id, relation_type, created_at) VALUES (?, ?, ?, ?)',
            [$relatedId, $articleId, $inverse->value, $now],
        );
    }

    return new ArticleRelation($id, $articleId, $relatedId, $type, $now);
}
```

### 删除关联（级联反向）

```php
public function removeRelation(int $articleId, int $relatedId, RelationType $type): bool
{
    $deleted = $this->db->execute(
        'DELETE FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
        [$articleId, $relatedId, $type->value],
    );
    // 删除反向
    $inverse = $type->inverse();
    $this->db->execute(
        'DELETE FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
        [$relatedId, $articleId, $inverse->value],
    );
    return $deleted > 0;
}
```

---

## 端点

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/articles` | 创建文章 |
| `GET` | `/articles/{id}` | 获取带嵌入关联摘要的文章 |
| `POST` | `/articles/{id}/relations` | 添加关联（+ 自动插入反向） |
| `GET` | `/articles/{id}/relations` | 列出关联（`?type=sequel` 过滤） |
| `DELETE` | `/articles/{id}/relations/{relatedId}` | 删除关联（需要 `?type=sequel`） |

---

## 响应结构

### GET /articles/{id}——带嵌入关联

```json
{
  "data": { "id": 1, "title": "Part 1", ... },
  "relations": [
    {
      "relation": { "id": 1, "article_id": 1, "related_id": 2, "relation_type": "sequel", ... },
      "related":  { "id": 2, "title": "Part 2", ... }
    }
  ]
}
```

### POST /articles/{id}/relations——请求

```json
{
  "related_id": 2,
  "relation_type": "sequel"
}
```

### DELETE /articles/{id}/relations/{relatedId}

```
DELETE /articles/1/relations/2?type=sequel
```

`type` 查询参数是**必填的**——一对资源可以同时有多种关联类型，所以类型用于消除要删除哪条边的歧义。

---

## 领域层结构

```
src/Article/
├── Article.php
├── ArticleRelation.php
├── ArticleRepository.php       # addRelation / removeRelation / listRelations / findWithRelations
├── RelationType.php            # 带 inverse() 的枚举
├── ArticleNotFoundException.php
└── RelationAlreadyExistsException.php
```

---

## 边界情况

| 场景 | 行为 |
|---|---|
| 自我关联（`article_id == related_id`） | 422——在处理器中于 DB 之前检查 |
| 同一对之间的重复类型 | 409 Conflict |
| 同一对的不同类型 | 201——有效，作为独立行存储 |
| 删除不存在的关联 | 404 |
| 不带 `type` 参数删除 | 422 |
| 文章不存在 | 每个无效 ID 返回 404 |

---

## 参见

- [标签系统 (M:N)](./tagging-system.md) ——无类型边的资源到标签 M:N
- [线程评论](./threaded-comments.md) ——自引用 `parent_id`
- [层级数据](./hierarchical-data.md) ——物化路径树
- [用户关注系统](./user-follow-system.md) ——用户之间的有向 M:N
