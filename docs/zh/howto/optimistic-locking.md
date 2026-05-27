# 乐观锁

乐观锁防止**丢失更新问题**——两个并发写入者都读取同一条记录，独立进行修改，第二个写入者静默覆盖第一个写入者的更改。

在以下情况使用乐观锁：
- 冲突不频繁（大多数更新会成功）
- 需要非阻塞读取（不使用 SELECT FOR UPDATE）
- 记录有 `version` 或 `updated_at` 字段来追踪其状态

## 丢失更新问题

不使用锁：

```
时间 | 写入者 A              | 写入者 B
-----|----------------------|-------------------
  1  | GET /articles/1      | GET /articles/1
     | ← version: 1         | ← version: 1
  2  | [编辑 title]         | [编辑 body]
  3  | PATCH /articles/1    |
     | title = "A's title"  |
     | ← version: 1, 200 OK |
  4  |                      | PATCH /articles/1
     |                      | body = "B's body"
     |                      | ← version: 1, 200 OK  ← A 的 title 丢失了
```

写入者 B 覆盖了写入者 A 的 title 更改，因为两者都没有检查并发修改。

## 数据库结构

添加一个每次更新都递增的 `version` 列：

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL,
    version    INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT NOT NULL
);
```

## 数据仓库实现

```php
/**
 * @throws ConflictException 如果另一个写入者先更新了记录
 * @throws \RuntimeException 如果文章不存在
 */
public function update(int $id, string $title, string $body, int $expectedVersion): Article
{
    $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

    // WHERE version = $expectedVersion 是乐观锁检查。
    // 如果另一个写入者已经递增了 version，此 UPDATE 匹配 0 行。
    $affected = $this->executor->execute(
        'UPDATE articles SET title = ?, body = ?, version = version + 1, updated_at = ? WHERE id = ? AND version = ?',
        [$title, $body, $now, $id, $expectedVersion],
    );

    if ($affected === 0) {
        // 0 行被更新：未找到或版本冲突——区分两者
        $current = $this->findById($id);
        if ($current === null) {
            throw new \RuntimeException("Article {$id} does not exist.");
        }
        throw new ConflictException($id, $expectedVersion);
    }

    return new Article(id: $id, title: $title, body: $body, version: $expectedVersion + 1, updatedAt: $now);
}
```

### 为什么 `version = version + 1` 在 SQL 中而非 PHP 中

```php
// ❌ 竞态条件：两个写入者都读取 version=1，都计算出 version=2
$newVersion = $article->version + 1;
$this->executor->execute('UPDATE ... SET version = ? ...', [$newVersion, $id, $expectedVersion]);

// ✅ 原子：数据库进行递增——version 始终正确
$this->executor->execute('UPDATE ... SET version = version + 1 ...', [$id, $expectedVersion]);
```

`WHERE version = $expectedVersion` 检查是守护；`version = version + 1` 确保新值恰好比通过守护的值多一。

## 控制器集成

客户端必须读取当前 `version` 并在每次更新时发回：

```php
private function update(ServerRequestInterface $request): ResponseInterface
{
    $id   = (int) Router::param($request, 'id');
    $body = json_decode((string) $request->getBody(), true);

    if (!is_array($body) || !is_int($body['version'] ?? null)) {
        return $this->problems->create($request, 'invalid-body', 'version (int) is required.', 400);
    }

    try {
        $article = $this->repo->update($id, $body['title'], $body['body'], $body['version']);
        return $this->json->create($this->serialize($article));
    } catch (ConflictException $e) {
        $current = $this->repo->findById($id);
        return $this->problems->create(
            $request,
            'conflict',
            'Optimistic lock conflict.',
            409,
            $e->getMessage(),
            $current !== null ? ['current_version' => $current->version] : [],
        );
    } catch (\RuntimeException) {
        return $this->problems->create($request, 'not-found', 'Article not found.', 404);
    }
}
```

## 客户端流程

```
POST /articles            → 201 { id: 1, version: 1, ... }
GET /articles/1           → 200 { id: 1, version: 1, ... }

PATCH /articles/1         → 200 { id: 1, version: 2, ... }
  { title: "...", version: 1 }

PATCH /articles/1         → 409 { type: "conflict", current_version: 2 }
  { title: "...", version: 1 }   （version 过期——冲突！）

PATCH /articles/1         → 200 { id: 1, version: 3, ... }
  { title: "...", version: 2 }   （重新读取或使用 409 中的 current_version）
```

在 409 响应中包含 `current_version` 让客户端无需额外 GET 即可重试。

## 响应载荷

始终在每个响应中包含 `version`，以便客户端始终拥有最新值：

```php
/** @return array<string, mixed> */
private function serialize(Article $article): array
{
    return [
        'id'         => $article->id,
        'title'      => $article->title,
        'body'       => $article->body,
        'version'    => $article->version,  // ← 客户端需要回传此值
        'updated_at' => $article->updatedAt,
    ];
}
```

## 乐观锁与悲观锁比较

| | 乐观锁 | 悲观锁 |
|---|---|---|
| 机制 | `WHERE version = ?` + 0 行检查 | `SELECT ... FOR UPDATE` |
| 读取阻塞 | 无 | 阻塞其他读取者 |
| 冲突率 | 低（大多数更新成功） | 高争用可以接受 |
| 重试代价 | 客户端在 409 时重试 | 等待锁释放 |
| SQLite 支持 | ✅ | ❌（不支持） |
| 最适合 | 冲突不频繁，UX 驱动的重试 | 高争用，必须成功的操作 |

## 代码审查清单

- [ ] UPDATE 的 WHERE 子句包含 `AND version = ?`
- [ ] 检查 `execute()` 返回值（受影响行数）——0 表示冲突或未找到
- [ ] 0 行情况区分"未找到"和"版本冲突"（冲突路径上额外的 `findById`）
- [ ] `version = version + 1` 在 SQL 中计算，而非在 PHP 应用代码中
- [ ] 每个响应载荷包含 `version` 以便客户端始终拥有最新值
- [ ] 409 响应包含 `current_version` 以便客户端无需额外 GET 即可重试
- [ ] 请求体中的 `version` 校验为 `int` 而非 `string`（`is_int()` 检查）
- [ ] 测试涵盖：成功更新、连续更新、并发冲突、冲突后重试、404、缺少 version
