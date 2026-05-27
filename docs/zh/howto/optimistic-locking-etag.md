# 操作指南：ETag / If-Match 乐观锁

> **FT 参考**：FT320（`NENE2-FT/locklog`）——带 ETag 请求头的文档版本控制，变更必须携带 If-Match（428），过期 ETag 被拒绝（412），防止丢失更新，15 个测试 / 30 个断言全部通过。

本指南展示如何使用 HTTP ETag 实现乐观并发控制，在不使用悲观 DB 锁的情况下防止丢失更新。

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

`version` 是权威的并发令牌。ETag 为 `"v{version}"`。

## 端点

| 方法 | 路径 | 描述 |
|--------|------|-------------|
| `POST` | `/documents` | 创建文档 |
| `GET`  | `/documents/{id}` | 获取带 ETag 的文档 |
| `PUT`  | `/documents/{id}` | 更新（需要 If-Match） |
| `DELETE` | `/documents/{id}` | 删除（需要 If-Match） |

## 创建

```php
POST /documents
{"title": "Hello", "body": "World"}
→ 201  ETag: "v1"
{"id": 1, "title": "Hello", "version": 1, ...}
```

## GET——返回 ETag

```php
GET /documents/1
→ 200  ETag: "v1"
{"id": 1, "title": "Hello", "version": 1}
```

客户端存储 ETag，并在下次变更时将其作为 `If-Match` 发送。

## PUT——乐观锁

```php
// 客户端发送当前 ETag
PUT /documents/1  If-Match: "v1"
{"title": "Updated"}
→ 200  ETag: "v2"
{"id": 1, "title": "Updated", "version": 2}

// 过期 ETag（另一个客户端先更新了）
PUT /documents/1  If-Match: "v1"
→ 412 Precondition Failed

// 缺少 If-Match
PUT /documents/1
{"title": "No lock"}
→ 428 Precondition Required

// 通配符——绕过版本检查
PUT /documents/1  If-Match: *
→ 200  // 文档存在时始终成功
```

### 防止丢失更新

```
Alice 读取文档 → version=1，ETag="v1"
Bob  读取文档 → version=1，ETag="v1"

Alice: PUT If-Match: "v1" → 200（version 变为 2）
Bob:   PUT If-Match: "v1" → 412 ← Bob 的写入被拒绝

Bob 必须重新 GET 以查看 Alice 的更改，然后用 "v2" 重试
```

## DELETE——也需要 If-Match

```php
DELETE /documents/1  If-Match: "v1"  → 200  {"deleted": true}
DELETE /documents/1  If-Match: "v1"  → 412  // version 已被更新
DELETE /documents/1                  → 428  // 缺少 If-Match
DELETE /documents/9999  If-Match: "v1" → 404
```

## 实现

```php
private function update(ServerRequestInterface $request): ResponseInterface
{
    $ifMatch = $request->getHeaderLine('If-Match');

    if ($ifMatch === '') {
        return $this->problems->create(
            'precondition-required',
            'If-Match header is required',
            428,
        );
    }

    $doc = $this->repo->findById($id);
    if ($doc === null) {
        return $this->json->create(['error' => 'Not found'], 404);
    }

    // 检查通配符或精确版本匹配
    $currentETag = '"v' . $doc['version'] . '"';
    if ($ifMatch !== '*' && $ifMatch !== $currentETag) {
        return $this->problems->create(
            'precondition-failed',
            'Document was modified by another request',
            412,
        );
    }

    $newVersion = $doc['version'] + 1;
    $this->repo->update($id, $title, $newVersion, $now);

    return $this->json->create($updated, 200)
        ->withHeader('ETag', '"v' . $newVersion . '"');
}
```

---

## ATK 评估——破解者思维攻击测试

### ATK-01 — ETag 暴力破解绕过前提条件 ✅ SAFE

**攻击**：攻击者循环尝试 `"v1"`、`"v2"`、`"v3"` 直到找到当前版本以强制更新。
**结论**：✅ SAFE——在简单顺序计数器上 ETag 暴力破解是可能的，但更新仍然是合法的写入。412 响应不透露任何关于当前版本的信息；攻击者必须 GET 才能确认。在高价值场景中，使用不透明 ETag（例如 `hash('sha256', $version . $secret)`）。

---

### ATK-02 — 省略 If-Match 强制无条件写入 🚫 BLOCKED

**攻击**：攻击者发送不带 `If-Match` 请求头的 PUT，希望服务器接受无条件写入。
**结论**：🚫 BLOCKED——缺少 `If-Match` 返回 428 Precondition Required。该端点拒绝所有不带锁令牌的写入。

---

### ATK-03 — 通配符 If-Match: * 绕过版本检查 🚫 BLOCKED

**攻击**：攻击者发送 `If-Match: *` 以无条件覆盖，忽略并发性。
**结论**：🚫 BLOCKED——通配符按设计被接受（匹配任何现有版本），但文档必须存在（不存在时返回 404）。这符合 HTTP 规范：`*` 表示"存在"；对于管理员操作是可接受的。对于面向用户的变更，将通配符限制为管理员角色。

---

### ATK-04 — 竞态条件——使用相同 ETag 的并发写入 🚫 BLOCKED

**攻击**：两个客户端同时发送带 `"v1"` 的 PUT。在任何一个更新之前，两者都通过了 ETag 检查。
**结论**：🚫 BLOCKED——DB UPDATE 使用 `WHERE version = $expectedVersion`。第二次写入发现 version 已递增并更新了 0 行 → 返回 412。在 DB 层是原子的。

---

### ATK-05 — 注入任意 ETag 值 🚫 BLOCKED

**攻击**：攻击者对 version 为 1 的文档发送 `If-Match: "v999999"`，希望服务器跳过校验。
**结论**：🚫 BLOCKED——ETag 与存储的 `"v{version}"` 字符串进行比较。`"v999999" ≠ "v1"` → 412。

---

### ATK-06 — 通过 If-Match 的请求头注入 🚫 BLOCKED

**攻击**：攻击者发送 `If-Match: "v1"\r\nX-Admin: true` 以注入响应头。
**结论**：🚫 BLOCKED——PSR-7 请求头解析从头值中去除 CR/LF。注入的请求头永远不会到达应用层。

---

### ATK-07 — 使用过期 ETag 删除 🚫 BLOCKED

**攻击**：攻击者获取旧 ETag，等待文档更新，然后使用过期 ETag 发送 DELETE。
**结论**：🚫 BLOCKED——DELETE 与 PUT 完全一样检查 ETag。过期 ETag 返回 412；文档得以保存。

---

### ATK-08 — ETag 中的负数版本 🚫 BLOCKED

**攻击**：攻击者发送 `If-Match: "v-1"` 或 `If-Match: "v0"`。
**结论**：🚫 BLOCKED——Version 从 1 开始只递增。`"v-1"` 和 `"v0"` 永远不会匹配存储的版本。

---

### ATK-09 — 重放之前成功的 ETag 🚫 BLOCKED

**攻击**：成功更新（`v1→v2`）后，攻击者重放 `If-Match: "v2"` 进行另一次更新。
**结论**：🚫 BLOCKED——这是有效行为——攻击者拥有当前令牌。问题是第三方不应能使用另一个用户的令牌。授权（所有权检查）是守护；ETag 只防止并发冲突。

---

### ATK-10 — 版本计数器溢出 🚫 BLOCKED

**攻击**：通过数百万次更新强制版本计数器溢出。
**结论**：🚫 BLOCKED——PHP 整数为 64 位（最大约 9.2 × 10^18）。实际上达到溢出是不可行的。速率限制防护针对快速更新循环。

---

### ATK-11 — 响应中的 ETag 欺骗 🚫 BLOCKED

**攻击**：攻击者构造请求使服务器返回伪造的 `ETag: "v999"`，让其他客户端认为文档在 version 999。
**结论**：🚫 BLOCKED——ETag 始终从 DB 的 `$doc['version']` 计算。没有用户输入影响返回的 ETag。

---

### ATK-12 — DELETE 不带 If-Match 无锁删除 🚫 BLOCKED

**攻击**：攻击者发送不带 `If-Match` 的 DELETE，依赖不强制前提条件的服务器。
**结论**：🚫 BLOCKED——DELETE 与 PUT 一样，`If-Match` 缺失时返回 428。

---

### ATK 汇总

| ID | 攻击 | 结论 |
|----|--------|--------|
| ATK-01 | ETag 暴力破解 | ✅ SAFE（顺序计数，参见注释） |
| ATK-02 | 省略 If-Match | 🚫 BLOCKED |
| ATK-03 | 通配符 If-Match 绕过 | 🚫 BLOCKED |
| ATK-04 | 并发写入竞争 | 🚫 BLOCKED |
| ATK-05 | 注入任意 ETag | 🚫 BLOCKED |
| ATK-06 | 通过 If-Match 的请求头注入 | 🚫 BLOCKED |
| ATK-07 | 使用过期 ETag 删除 | 🚫 BLOCKED |
| ATK-08 | 负数/零 version ETag | 🚫 BLOCKED |
| ATK-09 | 重放之前的 ETag | ✅ SAFE（授权问题，非 ETag 问题） |
| ATK-10 | 版本计数器溢出 | 🚫 BLOCKED |
| ATK-11 | 响应中的 ETag 欺骗 | 🚫 BLOCKED |
| ATK-12 | DELETE 不带 If-Match | 🚫 BLOCKED |

**10 BLOCKED，2 SAFE，0 EXPOSED**——无严重发现。

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 允许 PUT/DELETE 不带 If-Match | 无锁令牌的写入导致丢失更新 |
| 过期 ETag 时返回 200（静默覆盖） | 丢失更新：最后写入者胜出，并发编辑被静默丢弃 |
| 使用可变 ETag（例如 `Last-Modified` 时间戳） | 时钟偏差导致误报 412 或错误匹配 |
| 跳过通配符 `*` If-Match 支持 | 破坏管理工具和 RFC 7232 合规性 |
| WHERE 子句中无 DB 级版本检查 | 应用层检查通过但并发 DB 写入竞争成功 |
