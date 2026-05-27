# 操作指南：JWT 多租户隔离

> **FT 参考**：FT342（`NENE2-FT/tenantlog`）——带 JWT Bearer 认证的多租户笔记 API，tenant_id 嵌入令牌声明，严格的每租户查询范围限定，跨租户 IDOR 以 404 阻断，tenant_id 从不在响应中暴露，13 个测试 / 30+ 个断言全部通过。

本指南展示如何使用 JWT 令牌携带 `tenant_id` 声明，将所有查询限定在已认证的租户范围内，并防止跨租户数据访问。

## 数据库结构

```sql
CREATE TABLE tenants (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id     INTEGER NOT NULL REFERENCES tenants(id),
    email         TEXT    NOT NULL UNIQUE,
    password_hash TEXT    NOT NULL,
    created_at    TEXT    NOT NULL
);

CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id  INTEGER NOT NULL REFERENCES tenants(id),
    user_id    INTEGER NOT NULL REFERENCES users(id),
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL
);
```

## 认证

```
POST /auth/login  →  Bearer token（JWT）
所有其他端点 → Authorization: Bearer <token>
```

### 登录

```php
POST /auth/login
{"email": "alice@acme.com", "password": "password"}
→ 200  {"token": "eyJhbGci..."}

// 凭据错误或邮箱不存在
→ 401  {"type": ".../invalid-credentials", "detail": "Invalid email or password"}
// 两种失败返回相同消息（防止用户枚举）
```

### JWT 声明

```php
// 令牌载荷（解码后）
{
  "sub": 1,           // user_id
  "tenant_id": 1,     // 用户所属租户
  "exp": 1748427600
}
```

`tenant_id` 声明是租户身份的权威来源——永远不要信任来自请求体或请求头的 `tenant_id`。

### 验证

```php
$verifier = new LocalBearerTokenVerifier($secret);
$claims   = $verifier->verify($token);
// $claims['tenant_id'] 是可信的租户范围
```

篡改的令牌（无效签名）→ 401。

## 租户范围端点

所有笔记操作都需要有效的 Bearer 令牌。`tenant_id` 从已验证的 JWT 声明中提取。

### 创建笔记

```php
POST /notes
Authorization: Bearer <alice_token>
{"title": "Alice Note", "body": "Acme content"}
→ 201
{
  "id": 1,
  "title": "Alice Note",
  "body": "Acme content",
  "created_at": "..."
  // tenant_id 不返回——绝不泄露给客户端
}

// 无令牌 → 401
// 令牌无效 → 401
```

**`tenant_id` 始终取自 JWT 声明，而非请求体。**

### 列出笔记

```php
GET /notes
Authorization: Bearer <alice_token>
→ 200  [{"id": 1, "title": "Alice Note", ...}]

// Bob 的令牌只能看到 Bob 的笔记——Alice 的笔记绝不出现
GET /notes
Authorization: Bearer <bob_token>
→ 200  [{"id": 2, "title": "Bob Note", ...}]
```

```sql
SELECT * FROM notes WHERE tenant_id = ? ORDER BY created_at DESC
-- tenant_id 从 JWT 声明绑定，而非来自请求
```

### 获取笔记（IDOR 防护）

```php
// Alice 的笔记
GET /notes/1
Authorization: Bearer <alice_token>
→ 200  {"id": 1, "title": "Alice Note", ...}

// Bob 尝试访问 Alice 的笔记（笔记 id 1 属于租户 1）
GET /notes/1
Authorization: Bearer <bob_token>
→ 404  // 不是 403——防止跨租户存在枚举
```

**跨租户访问返回 404，而非 403。** 403 会透露资源存在于另一个租户。

### 删除笔记

```php
DELETE /notes/1
Authorization: Bearer <alice_token>
→ 204

// 跨租户删除
DELETE /notes/1
Authorization: Bearer <bob_token>
→ 404  // 笔记完好无损；Bob 的令牌无法访问它
```

## 实现模式

```php
// 中间件提取并验证 JWT
$claims = $verifier->verify($bearerToken);
$request = $request->withAttribute('tenant_id', $claims['tenant_id']);
$request = $request->withAttribute('user_id', $claims['sub']);

// 控制器从请求属性读取（绝不从请求体读取）
$tenantId = (int) $request->getAttribute('tenant_id');

// 数据仓库始终限定到租户范围
public function findById(int $id, int $tenantId): ?array
{
    $stmt = $this->db->prepare(
        'SELECT id, title, body, created_at FROM notes WHERE id = ? AND tenant_id = ?'
    );
    $stmt->execute([$id, $tenantId]);
    return $stmt->fetch() ?: null;
}

// null 返回 → 404 响应（绝不是 403）
if ($note === null) {
    return $this->json->create(['error' => 'Not found'], 404);
}
```

## 令牌篡改拒绝

```php
// 攻击者手动构造带不同 tenant_id 的令牌
$fakeToken = 'eyJhbGciOiJIUzI1NiJ9.tampered.invalidsignature';

GET /notes/1
Authorization: Bearer $fakeToken
→ 401  // 签名验证失败
```

服务器拒绝任何 HMAC-SHA256 签名与服务器密钥不匹配的令牌。

---

## 反模式

| 反模式 | 风险 |
|--------|------|
| 从请求体或查询参数读取 `tenant_id` | 攻击者设置 `tenant_id=2` 访问其他租户的数据 |
| 跨租户访问返回 403 | 确认资源存在于另一个租户——信息泄露 |
| 在笔记响应中包含 `tenant_id` | 暴露内部租户拓扑；客户端不需要 |
| 查询中跳过 `AND tenant_id = ?` | 跨租户泄露——持有有效令牌的攻击者可看到所有租户的数据 |
| 将 JWT 密钥与数据一起存储在配置中 | 密钥泄露允许为任意租户伪造令牌 |
| 信任来自 `X-Tenant-Id` 请求头的 `tenant_id` | 请求头可被任何客户端设置；只信任已验证的 JWT 声明 |
