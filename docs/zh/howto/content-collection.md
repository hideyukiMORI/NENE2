# 内容收藏集

文章策划收藏集（公开/私有）系统的实现指南。解说公开设置、IDOR 防护（存在不公开）、幂等添加和位置连续性整合。

## 概述

- 用户创建命名收藏集（列表）
- 每个收藏集中添加文章（最多 50 篇）
- `is_public` 标志：公开收藏集任何人都可以浏览
- 私有收藏集对非所有者返回 404（存在不公开模式）
- 添加文章是幂等的（已存在返回 200，新增返回 201）
- 删除条目后 position 自动整合

## 端点

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/collections` | 创建收藏集 |
| `GET` | `/collections/{id}` | 获取收藏集（公开或自己的） |
| `PUT` | `/collections/{id}` | 更改收藏集名称/公开设置 |
| `DELETE` | `/collections/{id}` | 删除收藏集 |
| `POST` | `/collections/{id}/items` | 添加文章（幂等） |
| `DELETE` | `/collections/{id}/items/{articleId}` | 删除文章 |

## 数据库设计

```sql
CREATE TABLE collections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    is_public INTEGER NOT NULL DEFAULT 0,  -- 0=private, 1=public
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE collection_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    collection_id INTEGER NOT NULL,
    article_id INTEGER NOT NULL,
    position INTEGER NOT NULL,
    added_at TEXT NOT NULL,
    UNIQUE (collection_id, article_id),
    FOREIGN KEY (collection_id) REFERENCES collections(id),
    FOREIGN KEY (article_id) REFERENCES articles(id)
);
```

## 存在不公开模式（IDOR 防护）

访问私有收藏集时返回 **404** 而非 403。返回 403 会泄露"存在但无权限"的信息。

```php
if (!$isOwner && !$isPublic) {
    return $this->responseFactory->create(['error' => 'collection not found'], 404);
}
```

## 幂等添加条目

```php
$existing = $this->repository->findItem($id, $articleId);
if ($existing !== null) {
    return $this->responseFactory->create(['message' => 'already in collection', 'article_id' => $articleId], 200);
}

$count = $this->repository->countItems($id);
if ($count >= CollectionRepository::maxItems()) {
    return $this->responseFactory->create(['error' => 'collection is full', 'max' => 50], 422);
}

$this->repository->addItem($id, $articleId, date('c'));
return $this->responseFactory->create(['message' => 'article added', 'article_id' => $articleId], 201);
```

上限检查仅在"尚未添加的文章"情况下执行。已添加的文章不影响上限（幂等调用）。

## 删除条目后的位置整合

```php
public function removeItem(int $collectionId, int $articleId): void
{
    $item = $this->findItem($collectionId, $articleId);
    $removedPosition = (int) $item['position'];
    $this->executor->execute('DELETE FROM collection_items WHERE collection_id = ? AND article_id = ?', ...);
    $this->executor->execute(
        'UPDATE collection_items SET position = position - 1 WHERE collection_id = ? AND position > ?',
        [$collectionId, $removedPosition]
    );
}
```

将被删除位置之后的条目各向前移动一位，填补空缺。

## GET /collections/{id} 响应示例

```json
{
  "id": 1,
  "user_id": 1,
  "name": "My Reading List",
  "is_public": false,
  "item_count": 2,
  "items": [
    {"article_id": 3, "title": "Article 3", "position": 1, "added_at": "2026-05-21T..."},
    {"article_id": 1, "title": "Article 1", "position": 2, "added_at": "2026-05-21T..."}
  ],
  "created_at": "2026-05-21T...",
  "updated_at": "2026-05-21T..."
}
```

## 所有权检查模式

在所有变更端点（PUT/DELETE/POST items）中进行所有者检查：

```php
if ((int) $collection['user_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

GET 的情况下，根据公开/私有和所有权分支返回 404/200，不使用 403。
