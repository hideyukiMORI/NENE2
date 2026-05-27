# 内容置顶

内容（文章）置顶功能的实现指南。解说有序置顶、上限管理、幂等添加和位置自动整合。

## 概述

- 用户将文章置顶（最多 10 篇）
- 通过 `position` 列管理顺序（从 1 开始）
- 幂等添加（已存在返回 200，新增返回 201）
- 取消置顶后 position 自动整合
- 顺序可通过 `PUT /pins/order` 任意调整

## 端点

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/pins` | 置顶文章（幂等） |
| `DELETE` | `/pins/{articleId}` | 取消置顶 |
| `GET` | `/pins` | 置顶列表（有序） |
| `PUT` | `/pins/order` | 更改置顶顺序 |

## 数据库设计

```sql
CREATE TABLE pins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    article_id INTEGER NOT NULL,
    position INTEGER NOT NULL,    -- 从 1 开始的连续整数
    pinned_at TEXT NOT NULL,
    UNIQUE (user_id, article_id), -- 每个用户每篇文章只能置顶一次
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (article_id) REFERENCES articles(id)
);
```

## 幂等置顶添加

```php
public function pin(int $userId, int $articleId, string $now): bool
{
    $existing = $this->findPin($userId, $articleId);
    if ($existing !== null) {
        return false;  // 已存在：false = 200
    }
    $nextPosition = $this->maxPosition($userId) + 1;
    $this->executor->execute('INSERT INTO pins ...', [$userId, $articleId, $nextPosition, $now]);
    return true;  // 新增：true = 201
}
```

返回 `true` → 201 Created，`false` → 200 OK（由调用方决定状态码）。

## 取消置顶后的位置整合

```php
public function unpin(int $userId, int $articleId): bool
{
    $removedPosition = (int) $existing['position'];
    $this->executor->execute('DELETE FROM pins WHERE user_id = ? AND article_id = ?', [...]);
    // 将已删除位置之后的条目各向前移动一位
    $this->executor->execute(
        'UPDATE pins SET position = position - 1 WHERE user_id = ? AND position > ?',
        [$userId, $removedPosition]
    );
    return true;
}
```

自动整合，确保 position 不出现间隔。

## 上限检查

```php
if ($this->repository->countPins($actorId) >= $this->repository->maxPins()) {
    $existing = $this->repository->findPin($actorId, $articleId);
    if ($existing === null) {
        return $this->responseFactory->create([
            'error' => 'pin limit reached',
            'max' => $this->repository->maxPins()
        ], 422);
    }
}
```

对已置顶的文章重新置顶（幂等）时跳过上限检查。

## 顺序调整（reorder）

```php
public function reorder(int $userId, array $orderedArticleIds): bool
{
    $currentPins = $this->listPins($userId);
    $currentIds = array_map(fn (array $p) => (int) $p['article_id'], $currentPins);
    sort($currentIds);
    $sortedInput = $orderedArticleIds;
    sort($sortedInput);
    if ($currentIds !== $sortedInput) {
        return false;  // 与当前置顶列表不匹配
    }
    foreach ($orderedArticleIds as $position => $articleId) {
        $this->executor->execute(
            'UPDATE pins SET position = ? WHERE user_id = ? AND article_id = ?',
            [$position + 1, $userId, $articleId]
        );
    }
    return true;
}
```

`article_ids` 必须与当前置顶列表（不考虑顺序）完全匹配，否则返回 422。只接受不含添加/删除的纯顺序调整。

## GET /pins 响应

```json
{
  "pins": [
    {"article_id": 3, "title": "Article 3", "position": 1, "pinned_at": "2026-05-21T10:00:00+00:00"},
    {"article_id": 1, "title": "Article 1", "position": 2, "pinned_at": "2026-05-21T09:00:00+00:00"}
  ],
  "count": 2
}
```

按 `position` 升序排序。通过 JOIN 使用 `ORDER BY p.position ASC` 获取。

## 释放上限（取消置顶后可新增）

```
已置顶 10 篇 → DELETE /pins/{id} → 9 篇 → 可通过 POST /pins 新增
```

上限根据当前计数动态判断，因此取消置顶后可立即新增。
