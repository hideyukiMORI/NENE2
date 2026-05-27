# 操作指南：表情符号反应 API

> **FT 参考**：FT306（`NENE2-FT/emojilog`）——表情符号反应：UNIQUE(post_id, user_id, emoji) 允许多个用户使用相同表情，但防止同一用户对同一帖子使用相同表情两次，mb_strlen 最大 8 个字符，DELETE 路径中表情使用 urldecode()，user_reactions 显示当前操作者的反应，反应按数量降序排列，18 个测试 / 28 个断言全部通过。

本指南演示如何实现表情符号反应系统，多个用户可以对一篇帖子用任意表情做出反应，但每个用户对每篇帖子中的同一表情只能使用一次。

## 数据库结构

```sql
CREATE TABLE reactions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id    INTEGER NOT NULL,
    user_id    INTEGER NOT NULL,
    emoji      TEXT    NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (post_id, user_id, emoji),
    FOREIGN KEY (post_id) REFERENCES posts(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`UNIQUE(post_id, user_id, emoji)` 允许：
- 多个用户使用相同表情：Alice 和 Bob 都可以用 `👍` 反应
- 同一用户使用不同表情：Alice 可以同时用 `👍` 和 `❤️`

但禁止：
- 同一用户 + 同一表情两次：Alice 不能对同一篇帖子用 `👍` 两次

## 端点

| 方法 | 路径 | 认证 | 描述 |
|------|------|------|------|
| `GET` | `/posts/{id}/reactions` | `X-User-Id`（可选） | 获取反应计数 + 操作者的反应 |
| `POST` | `/posts/{id}/reactions` | `X-User-Id` | 添加反应 |
| `DELETE` | `/posts/{id}/reactions/{emoji}` | `X-User-Id` | 移除反应 |

## 添加反应——严格校验

```php
if (!isset($body['emoji']) || !is_string($body['emoji']) || trim($body['emoji']) === '') {
    return $this->responseFactory->create(['error' => 'emoji is required'], 422);
}
$emoji = trim($body['emoji']);
if (mb_strlen($emoji) > 8) {
    return $this->responseFactory->create(['error' => 'emoji too long'], 422);
}

$added = $this->repository->addReaction($postId, $actorId, $emoji, date('c'));
if (!$added) {
    return $this->responseFactory->create(['error' => 'already reacted with this emoji'], 409);
}
```

- `is_string()` 检查拒绝非字符串类型
- 空值检查前先 `trim()` 防止仅含空白的表情通过
- `mb_strlen()` 而非 `strlen()`——正确计算多字节字符数
- 重复添加 → 409 Conflict（而非 422）

## 移除反应——路径中表情的 URL 解码

```php
$emoji = isset($params['emoji']) && is_string($params['emoji']) ? urldecode($params['emoji']) : '';
if ($emoji === '') {
    return $this->responseFactory->create(['error' => 'invalid emoji'], 404);
}
```

URL 路径段中的表情字符必须由客户端进行 URL 编码。`urldecode()` 将其还原为原始表情以供数据库查找。示例：`DELETE /posts/1/reactions/%F0%9F%91%8D` → 查找 `👍`。

## 反应计数响应

```php
// 按表情分组，计数，按数量降序排列
$counts = $this->repository->getReactionCounts($postId);

// 如果提供了操作者，显示其已使用的表情
$userReactions = [];
if ($actorId !== null) {
    $userReactions = $this->repository->getUserReactions($postId, $actorId);
}

return $this->responseFactory->create([
    'post_id'        => $postId,
    'reactions'      => $counts,        // [{emoji, count}, ...] 按数量降序
    'user_reactions' => $userReactions, // ['👍', '❤️', ...] 当前操作者的反应
]);
```

未提供 `X-User-Id` 头时 `user_reactions` 为空——此字段显示当前访问者的反应，帮助前端高亮其已激活的反应。

---

## 反模式

| 反模式 | 风险 |
|--------|------|
| `UNIQUE(post_id, user_id)`（无 emoji 列） | 每用户每帖子只能用一个表情 |
| 表情长度检查使用 `strlen()` | 多字节表情如 `🎉`（4 字节）计数错误 |
| 路径中表情不做 `urldecode()` | `%F0%9F%91%8D` 格式的 `👍` 永远匹配不上存储的 `👍` |
| 重复反应返回 404 | 隐藏 409 语义——重复反应是冲突，而非资源缺失 |
| 无表情长度限制 | 任意长度的字符串被存为表情列 |
| 无操作者时 `user_reactions` 为空但仍包含该键 | 省略或返回 `[]` 均可——但需在文档中说明行为 |
| 空值检查后才 `trim()` | 仅含空白的 `"  "` 表情被视为有效 |
