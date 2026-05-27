# 操作指南：租户隔离与 IDOR 防护

> **FT 参考**：FT318（`NENE2-FT/isolationlog`）— 多租户数据隔离、跨租户 IDOR 防护、头部类型混淆加固、请求体 tenant_id 注入防护，34 tests / 133 assertions 全部 PASS。

本指南展示如何强制执行严格的租户级数据隔离，使任何租户都无法读取、修改或枚举其他租户的数据——即使他们操纵请求头或请求体。

## 数据库结构

```sql
CREATE TABLE tenants (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE notes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id  INTEGER NOT NULL REFERENCES tenants(id),
    user_id    INTEGER NOT NULL,
    content    TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);
```

## 认证模型

```
管理员端点  → X-Admin-Key: <server_secret>       （例如环境变量 ADMIN_KEY）
租户端点    → X-Tenant-Id: <int>  X-User-Id: <int>
```

### 头部验证规则

`X-Tenant-Id` 和 `X-User-Id` 必须通过**严格的正整数**验证：

| 输入 | 结果 |
|------|------|
| `"1"`（有效） | ✅ 接受 |
| `"0"` | ❌ 401 — 必须 > 0 |
| `"-1"` | ❌ 401 — 负数被拒绝 |
| `"1.5"` | ❌ 401 — 浮点数被拒绝 |
| `"+1"` | ❌ 401 — 符号前缀被拒绝 |
| `"1 OR 1=1"` | ❌ 401 — SQL 注入尝试被拒绝 |
| `""`（缺失） | ❌ 401 — 缺少头部 |
| `"99999999999999999999"`（20位） | ❌ 401 — 溢出被拒绝 |

```php
// 使用 ctype_digit + 范围检查的验证模式
$raw = $request->getHeaderLine('X-Tenant-Id');
if (!ctype_digit($raw) || ($id = (int) $raw) <= 0 || strlen($raw) > 10) {
    return $this->json->create(['error' => 'Unauthorized'], 401);
}
```

## 管理员端点

```php
POST /tenants   X-Admin-Key: admin-secret
{"name": "Acme Corp"}
→ 201  {"id": 1, "name": "Acme Corp", "created_at": "..."}

GET  /tenants   X-Admin-Key: admin-secret
→ 200  {"total": 2, "tenants": [...]}

GET  /tenants/1  X-Admin-Key: admin-secret
→ 200  {"id": 1, "name": "Acme Corp", ...}

// 无管理员密钥
POST /tenants  （无 X-Admin-Key）   → 401
POST /tenants  X-Admin-Key: wrong → 401
```

## 租户端点 — IDOR 防护

### 创建笔记（服务器分配租户）

```php
POST /notes  X-Tenant-Id: 1  X-User-Id: 42
{"content": "Hello"}
→ 201  {"id": 1, "tenant_id": 1, "content": "Hello", ...}
```

**请求体中的 `tenant_id` 始终被忽略。** 服务器仅使用头部值：

```php
// 攻击者发送 X-Tenant-Id: 1 但请求体尝试注入租户 2
POST /notes  X-Tenant-Id: 1
{"content": "Injection", "tenant_id": 2}  // ← 被忽略

→ 201  {"tenant_id": 1, ...}   // 从头部分配，而非从请求体
```

### 跨租户 IDOR — 返回 404

```php
// 笔记 5 属于租户 1
GET  /notes/5  X-Tenant-Id: 2  → 404   // IDOR 被阻止
DELETE /notes/5  X-Tenant-Id: 2 → 404  // IDOR 被阻止

// 所有者仍可访问
GET  /notes/5  X-Tenant-Id: 1  → 200   ✅
```

所有查询包含 `WHERE tenant_id = $tenantId`。不存在的行返回 404——**而非 403**——以防止存在性枚举。

### 列表隔离

```php
// T1 有 2 条笔记，T2 有 1 条笔记
GET /notes  X-Tenant-Id: 1  → {"data": [note_A, note_B], "tenant_id": 1}
GET /notes  X-Tenant-Id: 2  → {"data": [note_X],         "tenant_id": 2}
// T2 永远看不到 T1 的笔记
```

```sql
SELECT * FROM notes WHERE tenant_id = ? ORDER BY id DESC LIMIT ?
-- 始终按已验证头部中的 tenant_id 过滤
```

### 查询参数验证

```php
GET /notes?limit=-1       → 422  // 负数
GET /notes?limit=10.5     → 422  // 浮点数
GET /notes?limit=999999   → 422  // 超过最大值（如 100）
GET /notes?limit=99999999999999999999  → 422  // 溢出
GET /notes                → 200  // 应用默认限制
```

## 为笔记创建不存在的租户

```php
POST /notes  X-Tenant-Id: 9999  X-User-Id: 1
{"content": "test"}
→ 422  // 租户 9999 不存在
```

---

## 不应做的事

| 反模式 | 风险 |
|---|---|
| 信任请求体中的 `tenant_id` | 攻击者可将笔记分配给任意租户 |
| IDOR 时返回 403 而非 404 | 403 暴露资源存在；404 防止枚举 |
| 直接转换头部：`(int) $header`（不使用 ctype_digit） | `-1`、`+1`、`1.5`、溢出都会产生意外整数 |
| 列表查询中无 `WHERE tenant_id = ?` | 完整的跨租户数据泄露 |
| 在客户端响应中共享管理员密钥 | 管理员密钥必须保留在服务器端 |
| 允许 `X-Tenant-Id: 0` | 零通常是默认/未设置状态；只接受正整数 |
