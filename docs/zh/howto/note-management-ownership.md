# 操作指南：带所有权的笔记管理

> **FT 参考**：FT240（`NENE2-FT/noteslog`）——笔记管理 API
> **ATK**：FT240——破解者思维攻击测试（ATK-01 至 ATK-12）

演示一个带所有者范围操作的笔记管理 API，使用 `X-Auth-User` 请求头标识身份，通过 `WHERE id = ? AND owner_id = ?` 防止 IDOR，以及保留未指定字段的字段合并更新。

---

## 路由

| 方法 | 路径 | 描述 |
|----------|----------------|------------------------------------------------------|
| `POST`   | `/notes`       | 创建笔记（需要 `X-Auth-User` 请求头）        |
| `GET`    | `/notes`       | 列出调用者拥有的笔记                               |
| `GET`    | `/notes/{id}`  | 获取单篇笔记（未找到或非所有者时返回 404） |
| `PUT`    | `/notes/{id}`  | 更新笔记（字段合并：省略的字段保留原值）    |
| `DELETE` | `/notes/{id}`  | 删除笔记（未找到或非所有者时返回 404）      |

---

## `X-Auth-User` 请求头身份识别

API 使用最简单的 `X-Auth-User` 字符串请求头作为调用者身份：

```php
private function resolveAuthUser(ServerRequestInterface $request): ?string
{
    $userId = trim($request->getHeaderLine('X-Auth-User'));

    return $userId !== '' ? $userId : null;
}
```

`trim()` 去除首尾空白。修剪后为空的请求头 → `null` → `401 Unauthorized`。任何非空字符串都被接受为有效用户 ID——没有令牌验证。

这是演示目的下有意为之的弱认证。在生产环境中，请替换为已验证的 JWT 声明或会话 cookie 支持的会话。

---

## IDOR 防御：`WHERE id = ? AND owner_id = ?`

每个操作特定笔记的操作都在查询中包含 `owner_id`：

```php
/**
 * 只有当笔记属于给定所有者时才返回它。
 * "未找到"和"所有者不匹配"都返回 null——调用者在两种情况下都返回 404
 * 以防止 IDOR 信息泄露（不暴露资源是否存在）。
 */
public function findByIdAndOwner(int $id, string $ownerId): ?Note
{
    $row = $this->db->fetchOne(
        'SELECT * FROM notes WHERE id = ? AND owner_id = ?',
        [$id, $ownerId],
    );

    return $row !== null ? $this->hydrate($row) : null;
}
```

该方法对"未找到"和"所有者不匹配"都返回 `null`。控制器在两种情况下使用相同的 `404 Not Found` 响应：

```php
$note = $this->repo->findByIdAndOwner($id, $authUser);

if ($note === null) {
    // 404 而非 403：不透露资源是否存在（IDOR 防御）
    return $this->problems->create($request, 'not-found', 'Note Not Found', 404, '');
}
```

返回 `403 Forbidden` 会确认资源存在——`404` 方式防止枚举攻击。调用者对其他用户的笔记一无所知。

---

## 字段合并更新

`PUT /notes/{id}` 保留请求体中省略字段的现有值：

```php
$title    = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : $note->title;
$noteBody = isset($body['body'])  && is_string($body['body'])  ? $body['body']        : $note->body;

$this->repo->update($id, $authUser, $title, $noteBody);
$updated = new Note($note->id, $note->ownerId, $title, $noteBody, $note->createdAt);
```

如果只提供了 `title`，`body` 保留当前值——反之亦然。这与完全替换（`PUT` 语义）不同——它更接近 `PATCH`。若需严格的 `PUT` 语义，要求两个字段都存在，否则返回 `422`。

---

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id   TEXT    NOT NULL,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_notes_owner ON notes (owner_id);
```

`body` 默认为 `''`——文本正文没有可空列。`owner_id` 是自由字符串（`X-Auth-User` 的值）；不存在指向 users 表的外键。

---

## ATK——破解者思维攻击测试（FT240）

### ATK-01 — `X-Auth-User` 轻而易举可伪造

**攻击**：通过在请求头中发送他人的用户 ID 来冒充其身份。

```bash
curl -s -X GET http://localhost:8080/notes \
  -H 'X-Auth-User: alice'

curl -s -X GET http://localhost:8080/notes \
  -H 'X-Auth-User: bob'
```

**观察结果**：每个请求返回请求头中用户 ID 所拥有的笔记。任何调用者都可以通过知道或猜测用户 ID 字符串来冒充任何用户。

**结论**：⚠️ EXPOSED——请求头不携带任何加密身份证明。生产认证请使用签名 JWT 令牌或会话 cookie。

---

### ATK-02 — `X-Auth-User` 中的换行注入

**攻击**：在请求头值中嵌入 HTTP 请求头注入字符（CR/LF）。

```
X-Auth-User: alice\r\nX-Injected: evil
```

**观察结果**：PSR-7（Nyholm）去除或拒绝无效的请求头字符。请求头值是普通字符串——HTTP 层的 CRLF 注入在到达应用之前由服务器（Swoole、Apache、Nginx）处理。`trim()` 去除首尾空白，但不对嵌入的控制字符提供进一步防御。

**结论**：🚫 BLOCKED（实际上）——HTTP 服务器在请求头到达应用层之前拒绝格式错误的请求头。

---

### ATK-03 — IDOR：读取他人的笔记

**攻击**：猜测或枚举属于另一个用户的笔记 ID。

```bash
curl -s http://localhost:8080/notes/1 -H 'X-Auth-User: bob'
# 笔记 1 由 alice 创建
```

**观察结果**：`findByIdAndOwner(1, 'bob')` 找不到匹配 `id = 1 AND owner_id = 'bob'` 的行 → 返回 `null` → `404 Not Found`。Bob 无法确定笔记 1 是否存在。

**结论**：🚫 BLOCKED——所有权范围查询 + 404 防止 IDOR。

---

### ATK-04 — 通过 title 或 body 的 SQL 注入

**攻击**：在请求体中嵌入 SQL 元字符。

```json
{"title": "'; DROP TABLE notes; --", "body": "\" OR \"1\"=\"1"}
```

**观察结果**：值作为参数化 `?` 值存储——不与 SQL 进行字符串拼接。注入载荷以字面文本存储。

**结论**：🚫 BLOCKED——参数化查询防止所有通过请求体字段的 SQL 注入。

---

### ATK-05 — 空标题

**攻击**：创建仅含空白或为空的标题的笔记。

```json
{"title": "   "}
{"title": ""}
```

**观察结果**：`trim($body['title'])` 将两者都减为 `""`。`title === ''` 检查触发 → `422 Unprocessable Entity`。

**结论**：🚫 BLOCKED——`trim()` + 空字符串检查处理仅含空白的输入。

---

### ATK-06 — 缺少 `X-Auth-User` 请求头

**攻击**：发送不带 `X-Auth-User` 请求头的请求。

```bash
curl -s http://localhost:8080/notes
```

**观察结果**：`getHeaderLine('X-Auth-User')` 返回 `""`。`trim()` 之后仍然是 `""`。`$userId !== ''` 失败 → `resolveAuthUser()` 返回 `null` → `401 Unauthorized`，带结构化 Problem Details 响应。

**结论**：🚫 BLOCKED——缺少请求头被视为未认证。

---

### ATK-07 — 通过任意 `X-Auth-User` 值冒充

**攻击**：创建以特权用户 ID 字符串为所有者的笔记。

```bash
# 假设 'admin' 是特殊用户
curl -s -X POST http://localhost:8080/notes \
  -H 'X-Auth-User: admin' \
  -H 'Content-Type: application/json' \
  -d '{"title":"Admin note"}'
```

**观察结果**：`201 Created`——笔记以 `owner_id = 'admin'` 创建。任何字符串都被接受为调用者身份。

**结论**：⚠️ EXPOSED（与 ATK-01 根源相同）。没有加密认证，就无法区分真正的管理员和知道字符串 `"admin"` 的攻击者。

---

### ATK-08 — title 或 body 中的 XSS 载荷

**攻击**：存储脚本标签。

```json
{"title": "<script>alert(1)</script>", "body": "<img src=x onerror=alert(1)>"}
```

**观察结果**：内容原样存储并在 JSON 中原样返回。JSON API 不对输出进行 HTML 编码。

**结论**：ACCEPTED BY DESIGN——JSON API 返回原始内容。渲染层在插入 HTML 之前必须进行消毒。为 API 消费者记录此预期。

---

### ATK-09 — 局部更新意外丢失字段

**攻击**：通过从更新中省略 `body` 尝试将其清空。

```json
{"title": "New title"}
// 调用者期望 body 被清除；实际上它被保留了
```

**观察结果**：字段合并逻辑在请求中不存在 `body` 时保留它：`$noteBody = isset($body['body']) ? $body['body'] : $note->body`。body 不变——这对于合并更新 API 来说符合预期，但可能让期望完全替换（`PUT` 语义）的调用者感到意外。

**结论**：ACCEPTED BY DESIGN——已记录的合并更新行为。如果需要严格的 `PUT` 语义，要求所有字段。

---

### ATK-10 — 非数字笔记 ID

**攻击**：将字符串或浮点数作为 `{id}` 传入。

```
GET /notes/abc
GET /notes/1.5
```

**观察结果**：`(int) 'abc'` = 0，`(int) '1.5'` = 1。
- `abc` → `findByIdAndOwner(0, ...)` → 无行 → `404 Not Found`。
- `1.5` → `findByIdAndOwner(1, ...)` → 如果笔记 1 属于调用者，则返回。

**结论**：⚠️ 部分阻止——非数字字符串映射为 404。浮点数被静默截断。添加 `ctype_digit()` 守护以进行严格校验。

---

### ATK-11 — 删除不存在或不属于自己的笔记

**攻击**：DELETE 一个不存在或属于另一个用户的笔记 ID。

```bash
curl -s -X DELETE http://localhost:8080/notes/99999 -H 'X-Auth-User: alice'
curl -s -X DELETE http://localhost:8080/notes/1    -H 'X-Auth-User: eve'
# （笔记 1 属于 alice）
```

**观察结果**：数据仓库运行 `DELETE FROM notes WHERE id = ? AND owner_id = ?`。如果没有行匹配（不存在或所有者不匹配），`$deleted = false` → `404 Not Found`。Eve 的尝试与不存在笔记返回相同的 404。

**结论**：🚫 BLOCKED——所有者范围 DELETE + 404 响应防止跨用户删除。

---

### ATK-12 — 仅含空白的 `X-Auth-User`

**攻击**：发送只含空格或制表符的请求头。

```
X-Auth-User:    
X-Auth-User: \t
```

**观察结果**：`trim('   ')` = `""` → `$userId !== ''` 失败 → `401 Unauthorized`。

**结论**：🚫 BLOCKED——`trim()` 将仅含空白的请求头规范化为空字符串。

---

## ATK 汇总

| # | 攻击向量 | 结论 |
|---|---------------|---------|
| ATK-01 | X-Auth-User 轻易可伪造 | ⚠️ EXPOSED |
| ATK-02 | X-Auth-User 换行注入 | 🚫 BLOCKED |
| ATK-03 | IDOR：读取他人笔记 | 🚫 BLOCKED |
| ATK-04 | 通过 title/body 的 SQL 注入 | 🚫 BLOCKED |
| ATK-05 | 空标题 | 🚫 BLOCKED |
| ATK-06 | 缺少 X-Auth-User 请求头 | 🚫 BLOCKED |
| ATK-07 | 通过任意请求头值冒充 | ⚠️ EXPOSED |
| ATK-08 | title/body 中的 XSS | ACCEPTED BY DESIGN |
| ATK-09 | 局部更新字段合并意外 | ACCEPTED BY DESIGN |
| ATK-10 | 非数字笔记 ID | ⚠️ 部分阻止 |
| ATK-11 | 删除不属于自己/不存在的笔记 | 🚫 BLOCKED |
| ATK-12 | 仅含空白的 X-Auth-User | 🚫 BLOCKED |

**生产前需修复的真实漏洞**：
1. **ATK-01 / ATK-07** — 将 `X-Auth-User` 替换为签名 JWT 或会话验证
2. **ATK-10** — 为 ID 路径参数添加 `ctype_digit()` 守护

---

## 相关操作指南

- [`use-bearer-auth.md`](use-bearer-auth.md) — 签名 Bearer 令牌认证
- [`enforce-resource-ownership.md`](enforce-resource-ownership.md) — IDOR 防御模式
- [`jwt-authentication.md`](jwt-authentication.md) — 用于用户身份识别的 JWT 验证
- [`scheduled-reminders.md`](scheduled-reminders.md) — V::userId() 请求头校验模式
