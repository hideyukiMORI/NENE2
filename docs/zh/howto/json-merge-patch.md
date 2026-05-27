# 操作指南：JSON Merge Patch 与 ETag 冲突检测

**FT178 — patchlog**

通过 ETag 实现乐观锁、不可变字段保护和 V.php 集成，实现 PATCH（RFC 7396 JSON Merge Patch）和 PUT 语义。

---

## PUT 的问题

`PUT` 替换整个资源。客户端必须发送所有字段，即使是未更改的字段。这导致：

- **竞态条件**：并发读者都看到版本 1，都进行 PUT，最后一个胜出并静默丢弃另一个的更改。
- **带宽浪费**：即使只改了一个字段也要发送完整载荷。
- **权限混乱**：写入客户端不拥有的字段。

带 **JSON Merge Patch（RFC 7396）** 的 `PATCH` 解决了前两个问题；`ETag` / `If-Match` 为 PATCH 和 PUT 都解决了竞态条件。

---

## JSON Merge Patch 语义（RFC 7396）

补丁文档使用简单规则描述变更：

| 补丁值 | 含义 |
|--------|------|
| `"新值"` | 将字段设为此值 |
| `null` | 重置字段（删除或恢复为默认值） |
| *（键不存在）* | 保持字段不变 |

```json
// PATCH 前的文档：
{ "title": "Hello", "body": "World", "status": "draft" }

// PATCH 请求体：
{ "title": "Goodbye", "status": null }

// 结果：
{ "title": "Goodbye", "body": "World", "status": "draft" }
//                              ^^^^^     ^^^^^^^^^^^^^^
//                              未变        null → 重置为默认值
```

### 不可变字段

某些字段绝不能通过 PATCH 或 PUT 修改：

```php
private const array IMMUTABLE_FIELDS = ['id', 'owner_id', 'version', 'created_at', 'updated_at'];

$violations = array_intersect(array_keys($body), self::IMMUTABLE_FIELDS);

if ($violations !== []) {
    return $this->responseFactory->create(
        ['error' => 'Fields are immutable: ' . implode(', ', $violations)],
        422,
    );
}
```

### 空 PATCH 是有效的（无操作）

RFC 7396 §3 明确允许空补丁 `{}`：

```php
// $patch 中没有键 → 跳过 UPDATE，返回当前文档不变
if ($patch === []) {
    return $doc;  // 无操作；版本不递增
}
```

---

## 用于乐观锁的 ETag 和 If-Match

### ETag 格式

```php
public function etag(): string
{
    return sprintf('"doc-%d-%d"', $this->id, $this->version);
    // 例如 "doc-42-7"
}
```

在每个 GET/PATCH/PUT 响应中返回 `ETag`：

```php
return $this->responseFactory->create($doc->toArray())
    ->withHeader('ETag', $doc->etag());
```

### 冲突检测

```php
$ifMatch = $request->getHeaderLine('If-Match');

if ($ifMatch !== '' && $ifMatch !== $doc->etag()) {
    return $this->responseFactory->create(
        ['error' => 'Version conflict. Fetch the document and retry.'],
        412,  // Precondition Failed
    );
}
```

**If-Match 缺失**：无冲突检查的乐观更新（最后写入获胜）。
**If-Match 存在且匹配**：安全的并发更新。
**If-Match 存在但已过期**：412——客户端必须重新获取并重试。

### SQL 中的版本递增

使用数据库原子递增版本：

```sql
UPDATE documents
SET title = ?, version = version + 1, updated_at = ?
WHERE id = ? AND version = ?
```

`WHERE version = ?` 子句在 DB 层面双重检查乐观锁，防止并发写入在我们读写之间悄悄插入。

---

## V.php 集成

FT178 是第一个使用 `Nene2\Validation\V` 作为共享工具的 FT：

```php
// 查询参数
$page  = V::queryInt($params, 'page', 1, PHP_INT_MAX, 1);
$limit = V::queryInt($params, 'limit', 1, 50, 20);

// 认证请求头
$ownerId = V::userId($request->getHeaderLine('X-User-Id'));

// 字符串字段（含明确的长度限制）
$title = V::str($body['title'] ?? null, 200);

// 枚举校验
$status = V::enum($body['status'] ?? null, DocumentStatus::class);
```

### 可选请求体字段的 `?? ''` 陷阱

```php
// ❌ 错误——绕过 V::str 对超长输入返回 null
$text = V::str($body['body'] ?? null, 10000) ?? '';

// ✅ 正确——存在时校验，缺失时使用默认值
$rawText = $body['body'] ?? null;
if ($rawText !== null) {
    $text = V::str($rawText, 10000);
    if ($text === null) {
        return $this->responseFactory->create(['error' => 'body too long'], 422);
    }
} else {
    $text = '';
}
```

`V::str(null, ...)` 返回 `null`，因为 `null` 不是字符串。
`V::str(too_long_string, 10000)` 也返回 `null`。
使用 `?? ''` 会将两种情况都折叠为空字符串——静默接受超长输入。

---

## 路由参数提取

NENE2 Router 将路径参数存储在 `nene2.route.parameters` 属性中，而非单独的请求属性：

```php
// ❌ 错误
$id = $request->getAttribute('id');  // 路径参数始终为 null

// ✅ 正确
$id = Router::param($request, 'id');  // 从 nene2.route.parameters 读取
```

---

## 攻击检查清单（ATK-01 至 ATK-12）

| # | 测试 | 预期 |
|---|------|------|
| ATK-01 | PATCH `{"id": 999}` | 422——不可变字段 |
| ATK-02 | PATCH `{"owner_id": 99}` | 422——不可变字段 |
| ATK-03 | PATCH `{"version": 999}` | 422——不可变字段 |
| ATK-04 | PATCH `{"title": 42}`（类型混淆） | 422——V::str 拒绝非字符串 |
| ATK-05 | 非所有者 PATCH | 404——IDOR 保护 |
| ATK-06 | If-Match 带过期 ETag | 412——乐观锁冲突 |
| ATK-07 | PUT 缺少必填 title | 422 |
| ATK-08 | PATCH 空 `{}` | 200——有效无操作（RFC 7396 §3） |
| ATK-09 | PATCH `{"status": null}` | 200——重置为默认 `draft` |
| ATK-10 | PATCH `{"status": 2}`（类型混淆） | 422——V::enum 拒绝非字符串 |
| ATK-11 | PATCH `{"__proto__": {...}}` | 200——未知键被忽略，不崩溃 |
| ATK-12 | `?limit=999999`、`?page=-1`、20 位溢出 | 422——V::queryInt 防护 |
