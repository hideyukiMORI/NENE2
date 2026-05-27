# 书签系统

允许用户将条目保存到命名集合中。书签操作是幂等的——两次收藏同一个条目不会报错，而是返回已有的书签。

## 概述

书签系统涉及：
- **添加书签**——将条目保存到用户的集合（幂等）
- **删除书签**——删除已保存的书签（未找到时返回 404）
- **列出书签**——用户的所有书签，可按集合过滤
- **统计书签**——轻量级徽章计数器
- **获取书签**——检查特定条目是否已被收藏

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

`UNIQUE (user_id, item_id)` 强制每个用户每个条目只有一个书签。`collection` 字段将书签分组到命名类别中，`'default'` 作为默认值。

## 幂等添加

在插入之前检查是否存在书签。如果发生冲突（竞争条件），捕获 `DatabaseConstraintException` 并返回已有记录：

```php
public function add(int $userId, int $itemId, string $collection, string $now): Bookmark
{
    $existing = $this->find($userId, $itemId);

    if ($existing !== null) {
        return $existing;  // 已收藏——不是错误
    }

    try {
        $this->executor->execute(
            'INSERT INTO bookmarks (user_id, item_id, collection, created_at) VALUES (?, ?, ?, ?)',
            [$userId, $itemId, $collection, $now],
        );
    } catch (DatabaseConstraintException) {
        // 竞争条件——另一个请求先完成；返回已有书签
        $found = $this->find($userId, $itemId);
        if ($found !== null) {
            return $found;
        }
    }

    $id = (int) $this->executor->lastInsertId();
    return new Bookmark($id, $userId, $itemId, $collection, $now);
}
```

先检查后插入的模式高效地处理常见情况。`DatabaseConstraintException` 捕获处理并发请求下的竞争条件。

## 集合过滤

使用可选的 `collection` 查询参数过滤书签：

```php
// GET /users/{userId}/bookmarks?collection=reading
$collection = isset($query['collection']) && $query['collection'] !== ''
    ? $query['collection'] : null;

$items = $this->repo->listByUser($userId, $collection);
```

`null` 集合返回所有书签；非空字符串过滤到该集合。

## 删除返回 204 还是 404

- `204 No Content`——书签存在并已被删除
- `404 Not Found`——书签不存在

```php
$removed = $this->repo->remove($userId, $itemId);

if (!$removed) {
    return $this->responseFactory->create(['error' => 'bookmark not found'], 404);
}

return $this->responseFactory->createEmpty(204);
```

`execute()` 返回受影响的行数——零表示未找到书签。

## MySQL 数据库结构

MySQL 需要明确的 `ENGINE=InnoDB` 和 `AUTO_INCREMENT` 语法：

```sql
CREATE TABLE bookmarks (
    id         INT          NOT NULL AUTO_INCREMENT,
    user_id    INT          NOT NULL,
    item_id    INT          NOT NULL,
    collection VARCHAR(100) NOT NULL DEFAULT 'default',
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_item (user_id, item_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

对于 MySQL 集成测试，在删除表之前 `SET FOREIGN_KEY_CHECKS = 0` 以避免外键依赖顺序问题。

## MySQL 集成测试模式

```php
protected function setUp(): void
{
    $host = (string) (getenv('MYSQL_HOST') ?: '');
    if ($host === '') {
        self::markTestSkipped('MYSQL_HOST not set — skipping MySQL integration tests');
    }
    ...
    $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $this->pdo->exec('DROP TABLE IF EXISTS bookmarks');
    $this->pdo->exec('DROP TABLE IF EXISTS items');
    $this->pdo->exec('DROP TABLE IF EXISTS users');
    $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $schema = (string) file_get_contents('.../database/schema.mysql.sql');
    $this->pdo->exec($schema);
}

protected function tearDown(): void
{
    if ($this->mysqlEnabled && $this->pdo !== null) {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DROP TABLE IF EXISTS bookmarks');
        $this->pdo->exec('DROP TABLE IF EXISTS items');
        $this->pdo->exec('DROP TABLE IF EXISTS users');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
```

## 安全属性

| 属性 | 实现方式 |
|---|---|
| 每个用户每个条目一个书签 | `UNIQUE (user_id, item_id)` 数据库约束 |
| 添加时的竞争条件 | 捕获 `DatabaseConstraintException` → 返回已有记录 |
| 用户隔离 | 所有查询按 `user_id` 过滤 |
| 删除不存在的书签 | 返回 404（非静默） |

## 路由摘要

| 方法 | 路径 | 描述 |
|---|---|---|
| `POST` | `/users` | 创建用户 |
| `POST` | `/items` | 创建条目 |
| `POST` | `/users/{userId}/bookmarks` | 添加书签（幂等） |
| `DELETE` | `/users/{userId}/bookmarks/{itemId}` | 删除书签（204 或 404） |
| `GET` | `/users/{userId}/bookmarks` | 列出书签（`?collection=` 过滤） |
| `GET` | `/users/{userId}/bookmarks/count` | 书签总数 |
| `GET` | `/users/{userId}/bookmarks/{itemId}` | 获取单个书签状态 |
