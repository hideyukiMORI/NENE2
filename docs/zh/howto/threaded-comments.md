# 嵌套评论

实现带深度限制和软删除的自引用评论线程。

## 概述

嵌套评论系统包含一张自引用的表。每条评论都知道自己的 `parent_id`（顶层为 null）、`depth`（从 0 开始）和 `status`。在响应树中，回复嵌套在其父评论内部。

## 数据库结构

```sql
CREATE TABLE comments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id     INTEGER NOT NULL,
    parent_id   INTEGER,
    author_name TEXT    NOT NULL,
    body        TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'published',
    depth       INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL,
    FOREIGN KEY (post_id)   REFERENCES posts(id),
    FOREIGN KEY (parent_id) REFERENCES comments(id)
);
```

`depth` 被反规范化存储到行中，以避免每次插入时进行递归祖先查询。

## 最大深度

在写入时强制深度限制：

```php
public const int MAX_DEPTH = 3;

public function canHaveReplies(): bool
{
    return $this->depth < self::MAX_DEPTH;
}
```

在路由处理器中，插入前进行检查：

```php
if (!$parent->canHaveReplies()) {
    return $this->problems->create($request, 'unprocessable-entity', 'Maximum comment depth reached.', 422, '');
}

$comment = $this->repo->addComment(
    $parent->postId, $parentId, $authorName, $body, $parent->depth + 1, $now,
);
```

## 软删除

软删除将 body 替换为 `[deleted]` 并设置 `status = 'deleted'`。子评论得以保留：

```php
public function softDelete(int $id): void
{
    $this->executor->execute(
        "UPDATE comments SET status = 'deleted', body = '[deleted]' WHERE id = ?",
        [$id],
    );
}
```

树形获取返回带 `[deleted]` body 的已删除评论，使线程结构保持连贯——读者看到删除评论所在位置的占位符，其子评论仍然可见。

尝试回复已删除评论返回 409：

```php
if ($parent->isDeleted()) {
    return $this->problems->create($request, 'conflict', 'Cannot reply to a deleted comment.', 409, '');
}
```

## 无 N+1 问题地构建评论树

通过单次按 ID 排序的查询加载文章的所有评论（父评论的 ID 始终小于子评论）。然后在 PHP 中通过两次遍历组装树：

```php
// 第一遍：构建原始行映射和子 ID 邻接列表
foreach ($rows as $row) {
    $rowMap[(int) $row['id']]   = $row;
    $childIds[(int) $row['id']] = [];
}

foreach ($rowMap as $id => $row) {
    $parentId = isset($row['parent_id']) ? (int) $row['parent_id'] : null;
    if ($parentId === null) {
        $roots[] = $id;
    } elseif (isset($childIds[$parentId])) {
        $childIds[$parentId][] = $id;
    }
}

// 第二遍：从根节点递归构建 Comment 值对象
return $this->buildTree($roots, $rowMap, $childIds);
```

将原始行和 `int[]` 子 ID 列表与 `Comment` 值对象分离，可以在使用 `readonly` 类时避免 PHPStan 类型混淆。

## 将行数据与值对象分离

在递归组装 readonly 值对象树时，PHPStan 需要清晰的类型边界。有效的模式：

1. **第一遍** — 从原始行构建 `array<int, array<string, mixed>> $rowMap` 和 `array<int, int[]> $childIds`。还不创建值对象。
2. **第二遍** — `buildTree()` 只接受 `int[]` ID 和两个映射，递归地将 `Comment` 对象与完整组装的子数组进行水合。

这避免了在同一个数组中混合 `Comment` 对象和 `int` ID，否则会产生 PHPStan 无法收窄的联合类型。

## 路由概览

| 方法 | 路径 | 说明 |
|---|---|---|
| `POST` | `/posts` | 创建文章 |
| `GET` | `/posts/{id}` | 获取文章 |
| `POST` | `/posts/{id}/comments` | 添加顶层评论 |
| `GET` | `/posts/{id}/comments` | 获取评论树 |
| `POST` | `/comments/{id}/replies` | 回复评论 |
| `DELETE` | `/comments/{id}` | 软删除评论 |

## 设计说明

- `depth` 存储在行中（反规范化），以避免每次插入时进行递归祖先查询。
- `ORDER BY id ASC` 保证在加载扁平列表时父评论出现在子评论之前。
- 软删除保留线程结构——硬删除会使子评论成为孤儿。
- 回复已删除的评论被阻止（409），以防止出现幽灵线程。
