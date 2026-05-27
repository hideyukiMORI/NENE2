# 操作指南：关注 / 取消关注 API

> **FT 参考**：FT314（`NENE2-FT/followlog`）——社交关注图谱：幂等关注（POST 首次返回 201，重复返回 200），防止自我关注（422），取消关注（DELETE 204），通过统计接口获取粉丝/关注数，按最新顺序分页列表，是否关注检查，支持互相关注，20 个测试 / 72 个断言全部通过。

本指南展示如何构建社交关注系统，支持用户互相关注和取消关注，并提供粉丝/关注数统计及列表端点。

## 数据库结构

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE follows (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    follower_id INTEGER NOT NULL REFERENCES users(id),
    followee_id INTEGER NOT NULL REFERENCES users(id),
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (follower_id, followee_id)
);
```

`UNIQUE (follower_id, followee_id)` 约束在 DB 层面强制关注关系的幂等性。

## 端点

| 方法 | 路径 | 描述 |
|------|------|------|
| `POST` | `/users` | 创建用户 |
| `POST` | `/users/{id}/follow` | 关注其他用户 |
| `DELETE` | `/users/{id}/follow/{followeeId}` | 取消关注 |
| `GET` | `/users/{id}/stats` | 获取粉丝/关注数 |
| `GET` | `/users/{id}/followers` | 列出粉丝（最新优先） |
| `GET` | `/users/{id}/following` | 列出关注（最新优先） |
| `GET` | `/users/{id}/is-following/{targetId}` | 检查是否关注 |

## 幂等关注

```php
// 首次关注 → 201 Created
POST /users/1/follow  {"followee_id": 2}
→ 201  {"following": true, "follower_id": 1, "followee_id": 2}

// 重复关注同一对象 → 200 OK（非 201，非 409）
POST /users/1/follow  {"followee_id": 2}
→ 200  {"following": true, "follower_id": 1, "followee_id": 2}
```

```php
// 处理程序逻辑
try {
    $this->repo->follow($followerId, $followeeId);
    return $json->ok($response, ['following' => true, ...], 201);
} catch (DuplicateFollowException $e) {
    return $json->ok($response, ['following' => true, ...], 200); // 已关注
}
```

## 防止自我关注

```php
POST /users/1/follow  {"followee_id": 1}
→ 422 Unprocessable Entity
```

```php
if ($followerId === $followeeId) {
    throw new ValidationException([
        ['field' => 'followee_id', 'message' => 'Cannot follow yourself.', 'code' => 'self-follow'],
    ]);
}
```

## 取消关注

```php
DELETE /users/1/follow/2
→ 204 No Content   // 成功取消关注

DELETE /users/1/follow/2  // 未关注时
→ 404 Not Found
```

取消关注后再重新关注的循环正常工作：DELETE → POST 再次返回 201。

## 统计

```php
GET /users/1/stats
→ 200
{
    "user_id": 1,
    "followers_count": 2,
    "following_count": 3
}
```

`followers_count` = 有多少用户关注了此用户。
`following_count` = 此用户关注了多少用户。

未知用户 → 404。

## 粉丝 / 关注列表

```php
GET /users/1/followers
→ 200
{
    "items": [
        {"id": 3, "name": "Carol", "created_at": "..."},
        {"id": 2, "name": "Bob",   "created_at": "..."}
    ],
    "count": 2
}
```

- 按 `follows.id DESC` 排序（最新粉丝优先）。
- `GET /users/{id}/following` 具有相同结构。
- 未知用户 → 404。

## 是否关注检查

```php
GET /users/1/is-following/2
→ 200  {"following": true}   // 1 关注了 2

GET /users/1/is-following/2  // 取消关注后
→ 200  {"following": false}
```

未关注时返回 `false`（而非 404）——检查本身始终有效。

## 互相关注

```php
POST /users/1/follow  {"followee_id": 2}
POST /users/2/follow  {"followee_id": 1}

GET /users/1/is-following/2  → {"following": true}
GET /users/2/is-following/1  → {"following": true}
```

互相关注只是两条独立的关注记录——不需要特殊的表或逻辑。

---

## 反模式

| 反模式 | 风险 |
|--------|------|
| 重复关注返回 409 | 客户端重试逻辑失效；幂等操作应返回 200，而非错误 |
| 允许自我关注 | 破坏统计（`followers_count` 被自己虚增）；信息流看起来异常 |
| `(follower_id, followee_id)` 无 UNIQUE 约束 | 并发关注点击在竞态条件下创建重复行 |
| DELETE 不存在的关注返回 204 | 客户端无法区分"已取消关注"和"从未关注"；应使用 404 |
| 按名称或 ID 而非最新时间排序 | 最新粉丝/关注在长列表中丢失；用户体验预期是"最近谁关注了我" |
| 跨用户共享关注数 | 粉丝数在不相关用户间混淆；始终按 user_id 限定范围 |
