# 操作指南：多租户隔离

本指南介绍如何使用 NENE2 构建多租户 API，使每个租户的数据严格隔离。跳过任何步骤都会产生静默的 IDOR（Insecure Direct Object Reference，不安全的直接对象引用），暴露所有租户的数据。

---

## 核心规则：每条查询都要加 `tenant_id` 过滤

从单条查询中省略租户过滤器会静默返回所有租户的数据：

```sql
-- ❌ 无租户过滤器——返回所有租户的记录
SELECT id, title, body FROM notes WHERE id = ?

-- ✅ 始终包含租户过滤器
SELECT id, title, body FROM notes WHERE id = ? AND tenant_id = ?
```

为数据仓库方法添加 `ForTenant` 后缀，使契约可见：

```php
public function findByIdForTenant(int $id, int $tenantId): ?Note
{
    /** @var array{id: int, tenant_id: int, title: string, body: string, created_at: string}|null $row */
    $row = $this->executor->fetchOne(
        'SELECT id, tenant_id, title, body, created_at FROM notes WHERE id = ? AND tenant_id = ?',
        [$id, $tenantId],
    );

    return $row !== null ? $this->hydrate($row) : null;
}

/** @return list<Note> */
public function findAllForTenant(int $tenantId): array
{
    /** @var list<array{id: int, tenant_id: int, title: string, body: string, created_at: string}> $rows */
    $rows = $this->executor->fetchAll(
        'SELECT id, tenant_id, title, body, created_at FROM notes WHERE tenant_id = ? ORDER BY id DESC',
        [$tenantId],
    );

    return array_map($this->hydrate(...), $rows);
}

public function delete(int $id, int $tenantId): bool
{
    $note = $this->findByIdForTenant($id, $tenantId);

    if ($note === null) {
        return false;
    }

    $this->executor->execute('DELETE FROM notes WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);

    return true;
}
```

`ForTenant` 后缀强制调用者提供租户 ID。这也使代码审查更直观：任何没有该后缀的方法都是 IDOR 审查的候选对象。

---

## 将 `tenant_id` 嵌入 JWT

在登录时一次性解析租户成员关系并嵌入令牌。这避免了每次请求的 DB 往返，并使租户上下文防篡改（JWT 签名覆盖它）。

```php
$now   = time();
$token = $this->issuer->issue([
    'sub'       => $user->id,
    'tenant_id' => $user->tenantId,  // 必须是 int
    'email'     => $user->email,
    'iat'       => $now,
    'exp'       => $now + self::TOKEN_TTL_SECONDS,
]);
```

在处理器中提取并校验声明。使用 `is_int()`——单独使用 `is_string()` 是不安全的；MySQL/PostgreSQL 可能静默接受字符串到整数的比较：

```php
private function tenantId(ServerRequestInterface $request): ?int
{
    /** @var array<string, mixed>|null $claims */
    $claims = $request->getAttribute('nene2.auth.claims');

    if (!is_array($claims) || !isset($claims['tenant_id']) || !is_int($claims['tenant_id'])) {
        return null;  // 触发 401
    }

    return $claims['tenant_id'];
}
```

`BearerTokenMiddleware` 将已验证的声明存储在 `nene2.auth.claims` 中。中间件在处理器运行之前拒绝过期令牌、篡改签名和 `alg: none` 攻击。

---

## 跨租户访问返回 404（而非 403）

返回 403 Forbidden 会暴露资源存在但调用者没有权限——这是跨租户的信息泄露。始终返回 404：

```php
// ❌ 403 泄露跨租户信息
if ($note->tenantId !== $tenantId) {
    return $this->problems->create($request, 'forbidden', 'Forbidden', 403);
}

// ✅ SQL 中的租户过滤器——跨租户记录简单地返回 null
$note = $this->notes->findByIdForTenant($id, $tenantId);

if ($note === null) {
    return $this->problems->create(
        $request,
        'not-found',
        'Note Not Found',
        404,
        "Note {$id} does not exist.",
    );
}
```

当 `WHERE id = ? AND tenant_id = ?` 没有匹配时，数据仓库返回 `null`，处理器返回 404——不需要显式的跨租户检查。

---

## 响应中排除 `tenant_id`

`tenant_id` 是基础设施标识符。在响应中暴露它让攻击者能够枚举所有租户 ID，并成为针对性攻击的起点：

```php
// ❌ tenant_id 在响应中泄露
return $this->json->create([
    'id'        => $note->id,
    'tenant_id' => $note->tenantId,  // 删除这行
    'title'     => $note->title,
    'body'      => $note->body,
]);

// ✅ 只包含客户端需要的字段
return $this->json->create([
    'id'         => $note->id,
    'title'      => $note->title,
    'body'       => $note->body,
    'created_at' => $note->createdAt,
]);
```

---

## PHPStan：`assertIsList()` 用于 `list<>` 返回类型

`json_decode()` 返回 `mixed`。`assertIsArray()` 之后，PHPStan 将类型收窄为 `array<mixed>`，但这不满足 `list<array<string, mixed>>`。添加 `assertIsList()` 进一步收窄：

```php
/** @return list<array<string, mixed>> */
private function jsonList(ResponseInterface $response): array
{
    $data = json_decode((string) $response->getBody(), true);

    $this->assertIsArray($data);
    $this->assertIsList($data);  // 收窄 array<mixed> → list<mixed>

    return $data;
}
```

PHPUnit 的 `assertIsList()` 在运行时也验证数组从 0 开始具有连续整数键——这对 API 列表响应是有用的正确性检查。

---

## 数据库结构设计

```sql
CREATE TABLE IF NOT EXISTS tenants (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id     INTEGER NOT NULL REFERENCES tenants(id),
    email         TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at    TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id  INTEGER NOT NULL REFERENCES tenants(id),
    title      TEXT NOT NULL,
    body       TEXT NOT NULL,
    created_at TEXT NOT NULL
);
```

每个租户范围的表都携带一个 `tenant_id NOT NULL` 外键。这在 DB 层以及应用层过滤器中都得到强制执行。

---

## 代码审查清单

审查多租户代码时，验证：

1. 每个 `SELECT`、`UPDATE` 和 `DELETE` 都包含 `WHERE tenant_id = ?`
2. `tenant_id` 来自 JWT 声明，而非 URL 参数或请求体
3. 跨租户访问返回 404，而非 403
4. 响应中不包含 `tenant_id`
5. 没有 `JOIN` 在没有租户过滤器的情况下跨越租户边界
6. 存在 `is_int($claims['tenant_id'])` 类型检查

---

## 测试隔离

单元测试是不够的——编写跨租户集成测试，实际尝试访问另一个租户的数据：

```php
public function testCrossTenantGetReturns404NotForbidden(): void
{
    $aliceToken = $this->loginAs('alice@acme.com');
    $bobToken   = $this->loginAs('bob@beta.com');

    $res    = $this->post('/notes', ['title' => 'Secret', 'body' => 'Acme secret'], $aliceToken);
    $noteId = $this->json($res)['id'];

    // Bob 尝试访问 Alice 的笔记
    $crossRes = $this->get('/notes/' . $noteId, $bobToken);

    // 必须是 404——不是 403
    $this->assertSame(404, $crossRes->getStatusCode());
}

public function testListNotesShowsOnlyCurrentTenantNotes(): void
{
    $aliceToken = $this->loginAs('alice@acme.com');
    $bobToken   = $this->loginAs('bob@beta.com');

    $this->post('/notes', ['title' => 'Alice Note', 'body' => 'Acme'], $aliceToken);
    $this->post('/notes', ['title' => 'Bob Note',   'body' => 'Beta'], $bobToken);

    $aliceNotes = $this->jsonList($this->get('/notes', $aliceToken));
    $bobNotes   = $this->jsonList($this->get('/notes', $bobToken));

    $this->assertCount(1, $aliceNotes);
    $this->assertSame('Alice Note', $aliceNotes[0]['title']);

    $this->assertCount(1, $bobNotes);
    $this->assertSame('Bob Note', $bobNotes[0]['title']);
}
```

快乐路径测试只验证自己租户的数据有效。跨租户测试是发现隔离失败的唯一方法。

---

## 参见

- `docs/howto/jwt-authentication.md` — JWT 签发和验证
- `docs/howto/rbac.md` — 基于 JWT 的角色访问控制
- `docs/howto/enforce-resource-ownership.md` — 每用户所有权检查
- `docs/field-trials/2026-05-field-trial-112.md` — 多租户隔离字段试验
