# 如何添加乐观并发控制（ETag / If-Match）

乐观锁防止**丢失更新问题**：两个客户端读取同一资源，都进行修改，第二次写入静默覆盖第一次。

NENE2 提供了 `ConditionalWriteHelper` 用于写入端（PUT、PATCH、DELETE）和 `ConditionalGetHelper` 用于读取端（GET → 304 Not Modified）。

---

## 1. 在数据库结构中添加版本计数器

```sql
CREATE TABLE documents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    version    INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT    NOT NULL
);
```

---

## 2. 在每个 GET 和写入响应中返回 ETag

使用版本号作为简单、易于调试的 ETag：

```php
private function etag(int $version): string
{
    return '"v' . $version . '"';
}

// 在 GET 处理器中：
return $this->json->create($doc->toArray())
    ->withHeader('ETag', $this->etag($doc->version));

// 在 POST（创建）处理器中：
return $this->json->create($doc->toArray(), 201)
    ->withHeader('ETag', $this->etag($doc->version));
```

---

## 3. 在 PUT / PATCH / DELETE 中检查 `If-Match`

```php
use Nene2\Http\ConditionalWriteHelper;

private function update(ServerRequestInterface $request): ResponseInterface
{
    $id  = $this->resolveId($request);
    $doc = $this->repo->findById($id);
    if ($doc === null) {
        return $this->problems->create($request, 'not-found', 'Not Found', 404, '');
    }

    $block = ConditionalWriteHelper::check($request, $this->problems, $this->etag($doc->version));
    if ($block !== null) {
        return $block; // 412 Precondition Failed 或 428 Precondition Required
    }

    // ETag 匹配——可以安全写入
    $updated = $this->repo->updateIfMatch($id, /* 新值 */, $doc->version);
    if ($updated === null) {
        // 检查后发生并发修改
        return $this->problems->create($request, 'precondition-failed', 'Precondition Failed', 412, '');
    }
    return $this->json->create($updated->toArray())
        ->withHeader('ETag', $this->etag($updated->version));
}
```

### `ConditionalWriteHelper::check()` 返回的状态码

| `If-Match` 请求头 | 服务器 ETag | 结果 |
|-------------------|-------------|--------|
| 不存在 | 任意 | **428** Precondition Required（请求头是必填的） |
| `*` | 任意 | **null** — 通过（通配符，任意版本） |
| `"v3"` | `"v3"` | **null** — 通过（精确匹配） |
| `"v2"` | `"v3"` | **412** Precondition Failed（版本过旧） |

要使 `If-Match` 可选，传入 `require: false`：

```php
ConditionalWriteHelper::check($request, $this->problems, $etag, require: false);
```

---

## 4. 在仓库中使用条件 UPDATE

```php
public function updateIfMatch(int $id, string $title, int $expectedVersion): ?Document
{
    $newVer  = $expectedVersion + 1;
    $now     = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    $updated = $this->db->execute(
        'UPDATE documents SET title = ?, version = ?, updated_at = ? WHERE id = ? AND version = ?',
        [$title, $newVer, $now, $id, $expectedVersion],
    );

    if ($updated === 0) {
        return null; // 版本不匹配或未找到
    }
    return new Document($id, $title, $newVer, $now);
}
```

`WHERE version = ?` 子句是数据库层面的锁保护。如果该行的版本已被并发写入者推进，`execute()` 返回 `0`（无行被更新），调用者可以返回第二个 412 响应。

---

## 5. 测试丢失更新场景

```php
public function testLostUpdatePrevented(): void
{
    $id = $this->decode($this->create('Original'))['id'];

    // Alice 读取版本 1 并更新 → 版本变为 2
    $this->req('PUT', '/documents/' . $id, ['title' => "Alice's edit"], '"v1"');

    // Bob 尝试用过期的 v1 ETag 更新 → 必须失败
    $bob = $this->req('PUT', '/documents/' . $id, ['title' => "Bob's edit"], '"v1"');
    self::assertSame(412, $bob->getStatusCode());

    // Alice 的更新被保留
    $final = $this->decode($this->req('GET', '/documents/' . $id));
    self::assertSame("Alice's edit", $final['title']);
    self::assertSame(2, $final['version']);
}
```

---

## 说明

- **ETag 格式**：`"v{version}"`（基于整数）简单且在测试中可预测。基于内容哈希的 ETag（`'"' . md5($body) . '"'`）对内容可寻址资源更健壮，但在测试中需要预先计算哈希，较难预测。
- **通配符 `If-Match: *`**：RFC 9110 将 `*` 定义为"如果资源有任何当前表示则成功"——即资源存在。适用于"如果存在则更新"场景而无需知道版本。调用者在资源不存在时仍须返回 404。
- **428 Precondition Required**（RFC 6585 §3）：当 `If-Match` 是必填项但缺失时，这是正确的状态码。使用它代替 400 或 422——请求本身格式正确；前置条件缺失。
- **TOCTOU 窗口**：`findById()` + 条件 UPDATE 模式在多写入数据库上存在短暂的竞争窗口。在 SQLite 的写入序列化下这是无害的。在 PostgreSQL 高并发下，应将两个操作包裹在 `SERIALIZABLE` 事务中。
