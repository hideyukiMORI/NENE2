# 标签系统（M:N）

使用多对多关联表将标签附加到文章，并支持原子性标签替换和无 N+1 问题的标签获取。

## 概述

标签系统包含三张表：`posts`、`tags` 和 `post_tags`（关联表）。文章和标签具有 M:N 关系——一篇文章有多个标签，一个标签属于多篇文章。

## 数据库结构

```sql
CREATE TABLE posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL
);

CREATE TABLE tags (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL
);

CREATE TABLE post_tags (
    post_id INTEGER NOT NULL,
    tag_id  INTEGER NOT NULL,
    PRIMARY KEY (post_id, tag_id),
    FOREIGN KEY (post_id) REFERENCES posts(id),
    FOREIGN KEY (tag_id)  REFERENCES tags(id)
);
```

`(post_id, tag_id)` 上的复合主键在 DB 层强制唯一性。

## 原子性设置标签

`PUT /posts/{id}/tags` 端点在一次操作中替换文章的所有标签。先删除后插入：

```php
public function setPostTags(int $postId, array $tagNames): array
{
    $this->executor->execute('DELETE FROM post_tags WHERE post_id = ?', [$postId]);

    foreach ($tagNames as $name) {
        $tag = $this->findTagByName($name);
        if ($tag === null) {
            continue; // 静默跳过未知标签名称
        }

        $this->executor->execute(
            'INSERT OR IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)',
            [$postId, $tag->id],
        );
    }

    return $this->findTagsByPostId($postId);
}
```

- 先删除后插入使操作幂等：以相同载荷调用两次结果相同。
- `INSERT OR IGNORE` 防止请求体中相同标签名出现两次时产生 DB 错误。
- 未知标签名称被静默跳过——客户端必须先创建标签再进行分配。
- 要清除所有标签，发送 `{"tags": []}`。

## 避免 N+1 查询

加载文章列表时（例如基于标签的搜索），使用单个 `IN` 查询批量获取所有标签，而不是每篇文章执行一次查询：

```php
private function findTagsByPostIds(array $postIds): array
{
    if ($postIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($postIds), '?'));
    $rows = $this->executor->fetchAll(
        "SELECT t.*, pt.post_id FROM tags t
         INNER JOIN post_tags pt ON pt.tag_id = t.id
         WHERE pt.post_id IN ({$placeholders})
         ORDER BY t.name ASC",
        $postIds,
    );

    $map = [];
    foreach ($rows as $row) {
        $postId         = (int) $row['post_id'];
        $map[$postId][] = $this->hydrateTag($row);
    }

    return $map;
}
```

返回以文章 ID 为键的 `array<int, Tag[]>`。无论文章数量多少，总共只需两次查询。

## 标签唯一性

标签在 `name` 上有 `UNIQUE` 约束。重复创建返回 409：

```php
public function createTag(string $name, string $now): ?Tag
{
    try {
        $this->executor->execute(
            'INSERT INTO tags (name, created_at) VALUES (?, ?)',
            [$name, $now],
        );
    } catch (\RuntimeException) {
        return null; // null → 处理器返回 409
    }

    return $this->findTagByName($name);
}
```

## 基于标签的搜索

通过 JOIN 按标签过滤文章：

```sql
SELECT p.* FROM posts p
INNER JOIN post_tags pt ON pt.post_id = p.id
INNER JOIN tags t ON t.id = pt.tag_id
WHERE t.name = ?
ORDER BY p.id DESC
```

然后使用上述 `IN` 查询批量加载结果集的标签。

## 路由概览

| 方法 | 路径 | 说明 |
|---|---|---|
| `POST` | `/posts` | 创建文章 |
| `GET` | `/posts/{id}` | 获取文章及其标签 |
| `POST` | `/tags` | 创建标签（重复 → 409） |
| `GET` | `/tags` | 列出所有标签（按字母顺序） |
| `PUT` | `/posts/{id}/tags` | 替换文章上的所有标签 |
| `GET` | `/tags/{name}/posts` | 列出带有某标签的文章 |

## 设计说明

- 标签是应用管理的实体，而非自由文本。客户端先创建标签，再进行分配。
- `PUT /posts/{id}/tags` 中的未知标签名称被静默忽略。这避免了预验证名称的往返请求。
- 响应中标签按字母顺序排序，以确保确定性输出。
- `GET /tags/{name}/posts` 在标签不存在时返回 404，与"标签存在但无文章"（返回 200 和空数组）加以区分。
