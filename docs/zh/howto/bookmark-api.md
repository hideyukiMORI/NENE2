# 操作指南：书签 API

> **FT 参考**：FT295（`NENE2-FT/bookmarklog`）——书签管理：`UNIQUE(user_id, item_id)` 防止重复书签，带可选过滤的集合分组，用户范围访问（IDOR 防护），重复时返回 409，22 个测试 / 64 个断言全部通过。

本指南展示如何构建一个书签 API，让用户可以将条目保存到命名集合中，具有去重和用户范围访问控制。

## 数据库结构

```sql
CREATE TABLE bookmarks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    item_id    INTEGER NOT NULL,
    collection TEXT    NOT NULL DEFAULT 'default',
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, item_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (item_id) REFERENCES items(id)
);
```

`UNIQUE(user_id, item_id)` 确保每个用户只能为每个条目添加一次书签。`collection` 字段将书签分组到命名列表中（默认：`'default'`）。

## 端点

| 方法 | 路径 | 描述 |
|--------|------|-------------|
| `POST` | `/users/{userId}/bookmarks` | 添加书签 |
| `DELETE` | `/users/{userId}/bookmarks/{itemId}` | 删除书签 |
| `GET` | `/users/{userId}/bookmarks` | 列出书签（可按集合过滤） |
| `GET` | `/users/{userId}/bookmarks/count` | 统计书签数量 |
| `GET` | `/users/{userId}/bookmarks/{itemId}` | 获取特定书签 |

## 路由注册顺序

`/users/{userId}/bookmarks/count` 必须在 `/users/{userId}/bookmarks/{itemId}` **之前**注册，以防止 `count` 被捕获为 `{itemId}`：

```php
$router->get('/users/{userId}/bookmarks', $this->listBookmarks(...));
$router->get('/users/{userId}/bookmarks/count', $this->countBookmarks(...));  // 静态路由在动态路由之前
$router->get('/users/{userId}/bookmarks/{itemId}', $this->getBookmark(...));
```

## 添加书签

```php
private function addBookmark(ServerRequestInterface $request): ResponseInterface
{
    $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $userId = isset($params['userId']) && is_numeric($params['userId']) ? (int) $params['userId'] : 0;

    if ($userId <= 0 || !$this->repo->findUserById($userId)) {
        return $this->responseFactory->create(['error' => 'user not found'], 404);
    }

    $body       = JsonRequestBodyParser::parse($request);
    $itemId     = isset($body['item_id']) && is_int($body['item_id']) ? $body['item_id'] : 0;
    $collection = isset($body['collection']) && is_string($body['collection'])
        ? trim($body['collection']) : 'default';

    if ($itemId <= 0 || !$this->repo->findItemById($itemId)) {
        return $this->responseFactory->create(['error' => 'item not found'], 404);
    }

    if ($collection === '') {
        $collection = 'default';  // 空集合字符串 → 回退到 'default'
    }

    $now      = date('Y-m-d H:i:s');
    $bookmark = $this->repo->add($userId, $itemId, $collection, $now);
    return $this->responseFactory->create($bookmark->toArray(), 201);
}
```

`item_id` 需要 `is_int()`——JSON 字符串 `"5"` 会被拒绝。数据库中的 `UNIQUE` 约束捕获竞争条件；仓库应捕获约束违规并返回 409。

## 列表的集合过滤

```php
$query      = $request->getQueryParams();
$collection = isset($query['collection']) && is_string($query['collection']) && $query['collection'] !== ''
    ? $query['collection'] : null;

$items = $this->repo->listByUser($userId, $collection);
```

不带 `?collection=` 时返回所有书签。带 `?collection=favorites` 时只返回该集合。空的集合查询参数被视为"无过滤"。

## 用户范围——IDOR 防护

每个端点在返回数据之前都会验证 `userId` 是否存在于数据库中：

```php
if ($userId <= 0 || !$this->repo->findUserById($userId)) {
    return $this->responseFactory->create(['error' => 'user not found'], 404);
}
```

以不同用户身份请求 `/users/999/bookmarks` 返回 404（而非其他用户的书签）。所有查询都以路径中的 `userId` 为范围。

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 无 `UNIQUE(user_id, item_id)` | 用户多次收藏同一条目；产生令人困惑的重复 |
| 重复书签时返回 200 | 客户端无法区分"已添加"和"已存在"；应使用 409 |
| 从请求体接受字符串类型的 `item_id` | JSON 类型混淆：`"5"` ≠ `5`；使用 `is_int()` |
| 在 `/count` 之前注册 `/{itemId}` | `GET /users/1/bookmarks/count` 解析为 `itemId = "count"`（错误的处理器） |
| 不检查用户是否存在 | 不存在的 userId 返回空列表而非 404 |
| 查询中无用户范围限定 | 用户 A 看到用户 B 的书签（IDOR） |
| 无集合默认值 | 缺少 `collection` 字段导致崩溃或在数据库中留下 `NULL` |
