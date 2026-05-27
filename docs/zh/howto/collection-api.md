# 操作指南：收藏集 API（用户策划列表）

> **FT 参考**：FT299（`NENE2-FT/collectionlog`）——用户策划文章收藏集：is_public/private 可见性（非所有者访问私有收藏集时返回 404），`UNIQUE(collection_id, article_id)` 去重，位置排序，仅所有者可写，20 个测试 / 34 个断言全部通过。

本指南展示如何构建用户策划收藏集 API，让用户可以创建命名列表、向其中添加文章，并控制公开/私有可见性。

## 数据库结构

```sql
CREATE TABLE collections (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    name       TEXT    NOT NULL,
    is_public  INTEGER NOT NULL DEFAULT 0,  -- 0=私有，1=公开
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE collection_items (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    collection_id INTEGER NOT NULL,
    article_id    INTEGER NOT NULL,
    position      INTEGER NOT NULL,
    added_at      TEXT    NOT NULL,
    UNIQUE (collection_id, article_id),
    FOREIGN KEY (collection_id) REFERENCES collections(id),
    FOREIGN KEY (article_id)    REFERENCES articles(id)
);
```

`UNIQUE(collection_id, article_id)` 防止同一篇文章在收藏集中出现两次。`position` 支持有序显示。

## 端点

| 方法 | 路径 | 认证 | 描述 |
|------|------|------|------|
| `POST` | `/collections` | `X-User-Id` | 创建收藏集 |
| `GET` | `/collections/{id}` | `X-User-Id` | 获取收藏集（可见性检查） |
| `PUT` | `/collections/{id}` | `X-User-Id`（所有者） | 更新名称/可见性 |
| `DELETE` | `/collections/{id}` | `X-User-Id`（所有者） | 删除收藏集 |
| `POST` | `/collections/{id}/items` | `X-User-Id`（所有者） | 添加文章 |
| `DELETE` | `/collections/{id}/items/{articleId}` | `X-User-Id`（所有者） | 移除文章 |

## 可见性——私有收藏集返回 404

```php
$isOwner  = (int) $collection['user_id'] === $actorId;
$isPublic = (bool) $collection['is_public'];

if (!$isOwner && !$isPublic) {
    return $this->responseFactory->create(['error' => 'collection not found'], 404);
}
```

非所有者尝试访问私有收藏集时收到 404，而非 403。这样可以防止泄露私有收藏集的存在。

## 仅所有者可写

```php
if ((int) $collection['user_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

添加、移除、更新和删除操作需要操作者是收藏集所有者。与可见性不同，写权限失败返回 403（此时收藏集的存在已知晓）。

## UNIQUE(collection_id, article_id)——去重

数据库约束防止同一篇文章在收藏集中出现两次。应用在插入前检查重复：

```php
// 仓库在 addItem() 之前检查 findItem()
if ($this->repository->findItem($id, $articleId) !== null) {
    return $this->responseFactory->create(['error' => 'article already in collection'], 409);
}
$this->repository->addItem($id, $articleId, date('c'));
```

## is_public 作为布尔整数

```php
$isPublic = isset($body['is_public']) && $body['is_public'] === true;
```

`is_public` 在 SQLite 中存储为 INTEGER（0/1）。读取时：`(bool) $collection['is_public']`。写入时：严格的 `=== true` 检查防止字符串 `"true"` 启用公开访问。

## 响应结构

```php
private function formatCollection(array $collection, array $items): array
{
    return [
        'id'         => (int)    $collection['id'],
        'user_id'    => (int)    $collection['user_id'],
        'name'       => (string) $collection['name'],
        'is_public'  => (bool)   $collection['is_public'],
        'item_count' => count($items),
        'items'      => array_map(fn($item) => [
            'article_id' => (int)    $item['article_id'],
            'title'      => (string) $item['article_title'],
            'position'   => (int)    $item['position'],
            'added_at'   => (string) $item['added_at'],
        ], $items),
        'created_at' => (string) $collection['created_at'],
        'updated_at' => (string) $collection['updated_at'],
    ];
}
```

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 访问私有收藏集返回 403 | 向非所有者泄露收藏集的存在（信息泄露） |
| 允许任意用户向任意收藏集添加条目 | 非所有者向他人收藏集注入内容 |
| 无 `UNIQUE(collection_id, article_id)` | 同一篇文章被添加两次；产生令人困惑的重复条目 |
| 接受 `"true"` 字符串作为 `is_public` | 类型混淆：松散比较下任何字符串都是真值 |
| 无 position 字段 | 条目始终按插入顺序显示；无法重新排序 |
| 删除收藏集时无所有权检查 | 任意用户可删除任意收藏集 |
| 暴露 `item_count` 但不包含条目 | 向私有收藏集的非所有者泄露收藏集大小 |
