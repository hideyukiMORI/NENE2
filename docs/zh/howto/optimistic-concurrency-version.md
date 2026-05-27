# 操作指南：乐观并发控制（版本字段）

> **FT 参考**：FT323（`NENE2-FT/optimisticlog`）——在 PUT 请求体中使用 version 字段的文档 API，过期版本时返回 409，防止丢失更新，18 个测试 / 34 个断言全部通过。

本指南展示如何通过在请求体中传递 `version` 字段实现乐观并发控制，作为 HTTP ETag/If-Match 请求头的替代方案。

## 数据库结构

```sql
CREATE TABLE documents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    version    INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT    NOT NULL
);
```

## 端点

| 方法 | 路径 | 描述 |
|--------|------|-------------|
| `POST` | `/documents` | 创建（version 从 1 开始） |
| `GET`  | `/documents` | 列表 |
| `GET`  | `/documents/{id}` | 获取带 version 的文档 |
| `PUT`  | `/documents/{id}` | 更新（请求体中必须包含 version） |

## 创建

```php
POST /documents  {"title": "Hello", "body": "World"}
→ 201  {"id": 1, "title": "Hello", "version": 1}
```

## 带版本的更新

客户端读取当前 `version` 并在 PUT 请求体中包含它：

```php
// 读取
GET /documents/1
→ 200  {"id": 1, "title": "Hello", "version": 1}

// 使用正确版本更新
PUT /documents/1
{"title": "Updated", "body": "new body", "version": 1}
→ 200  {"id": 1, "title": "Updated", "version": 2}
```

每次成功更新后 version 递增。

## 过期版本——409 Conflict

```php
// Alice 和 Bob 都读取了 version 1
// Alice 先更新 → version 变为 2
// Bob 尝试使用 version 1 更新 → 被拒绝
PUT /documents/1
{"title": "Bob's edit", "version": 1}
→ 409 Conflict  {"current_version": 2, "submitted_version": 1}

// Bob 重新读取，获得 version 2，重试
PUT /documents/1
{"title": "Bob's edit", "version": 2}
→ 200  {"version": 3}
```

## 实现

```php
private function update(ServerRequestInterface $request): ResponseInterface
{
    $body    = $this->parseBody($request);
    $version = $body['version'] ?? null;

    if (!is_int($version) || $version < 1) {
        return $this->json->create(['error' => 'version is required'], 422);
    }

    $doc = $this->repo->findById($id);
    if ($doc === null) {
        return $this->json->create(['error' => 'Not found'], 404);
    }

    if ($doc['version'] !== $version) {
        return $this->problems->create('conflict', 'Stale version', 409, [
            'current_version'   => $doc['version'],
            'submitted_version' => $version,
        ]);
    }

    $newVersion = $version + 1;
    // UPDATE documents SET ... WHERE id = ? AND version = ?
    $this->repo->update($id, $title, $newVersion, $now);

    return $this->json->create($updated);
}
```

UPDATE 查询中的 `WHERE version = ?` 子句是防止并发写入的原子守护。

## 版本字段与 ETag 比较

| 方面 | 版本字段（本指南） | ETag / If-Match（参见 `optimistic-locking-etag.md`） |
|--------|---------------------------|-----------------------------------------------------|
| 协议 | 请求体字段 | HTTP 请求头 |
| 客户端体验 | JSON 中显式的 `"version": N` | `If-Match: "vN"` 请求头 |
| 409 响应体 | 可以返回 `current_version` | 412——无响应体标准 |
| 缺失检查 | 422（缺少 `version`） | 428（缺少 `If-Match`） |

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 接受不带 `version` 字段的 PUT | 丢失更新：最后写入者静默胜出 |
| 过期版本时返回 200 | 静默覆盖并发更改 |
| 只在应用代码中检查版本（不在 WHERE 子句） | 读取和写入之间存在竞态条件 |
| 409 响应中不包含 `current_version` | 客户端必须重新 GET 才能恢复；包含它可加快重试 |
