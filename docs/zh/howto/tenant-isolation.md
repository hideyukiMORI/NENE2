# 操作指南：租户隔离与跨租户 IDOR 防护

**FT179 — isolationlog**

在多租户 API 中防止跨租户数据泄露——作用域限定的 SQL 查询、基于头部的身份标识以及请求体注入防护。

---

## 威胁：跨租户 IDOR

在多租户系统中，每个资源都属于某个租户。
控制一个租户账户的攻击者会探测其他租户的 ID：

```
GET /notes/42          X-Tenant-Id: 2   ← 攻击者是租户 2
                                         笔记 42 属于租户 1
```

如果服务器返回该笔记，攻击者就读取了另一个租户的数据——这是租户边界处的**不安全直接对象引用（IDOR）**。

---

## 隔离模式

### 1. 在 SQL 层面限定所有读取范围

永远不要仅通过 ID 查询。始终添加 `AND tenant_id = ?`：

```php
// ❌ 错误 — 仅靠 ID，跨租户可读
'SELECT * FROM notes WHERE id = ?'

// ✅ 正确 — SQL 中同时强制 ID + 租户
'SELECT * FROM notes WHERE id = ? AND tenant_id = ?'
```

跨租户访问返回 `null`，转化为 404。
攻击者对笔记 42 一无所知——甚至不知道它是否存在。

### 2. 列表查询始终有作用域限制

```php
// ❌ 错误 — 可能被 ?tenant_id=... 注入增强
'SELECT * FROM notes ORDER BY id DESC LIMIT ?'

// ✅ 正确 — WHERE tenant_id = ? 从不可选
'SELECT * FROM notes WHERE tenant_id = ? ORDER BY id DESC LIMIT ?'
```

### 3. 删除使用相同模式

```sql
DELETE FROM notes WHERE id = ? AND tenant_id = ?
```

如果笔记不属于该租户，`rowCount()` 返回 0 → 404。

---

## 基于头部的租户身份

使用 `X-Tenant-Id` + `X-User-Id` 头部作为租户范围端点的标识。
使用 `V::userId()`（ctype_digit + 溢出防护 + > 0）验证两者：

```php
private function resolveTenantUser(ServerRequestInterface $request): array
{
    $tenantId = V::userId($request->getHeaderLine('X-Tenant-Id'));
    $userId   = V::userId($request->getHeaderLine('X-User-Id'));

    return [$tenantId, $userId];
}
```

`V::userId()` 拒绝：
- 空字符串（`ctype_digit('') === false`）
- 零（`id <= 0`）
- 负数（`'-'` 无法通过 `ctype_digit`）
- 浮点字符串（`'1.5'` 无法通过 `ctype_digit`）
- 20+ 位溢出（strlen > 18 防护）
- SQL 注入尝试（`'1 OR 1=1'` 无法通过 `ctype_digit`）

---

## 请求体注入防护

攻击者可能在 POST 请求体中包含 `tenant_id`，试图将资源分配给不同的租户：

```json
POST /notes
X-Tenant-Id: 1
{ "content": "Injection", "tenant_id": 99 }
```

**永远不要从请求体读取 `tenant_id`。** 始终使用服务器验证的头部值：

```php
// ATK-04：永远不读取 body['tenant_id'] — 始终使用来自头部的 $tenantId
$note = $this->notes->create($tenantId, $userId, $content, date('c'));
//                            ^^^^^^^^^
//                            来自 V::userId(X-Tenant-Id)，而非 $body
```

---

## 写入时检查租户是否存在

创建资源前，验证租户是否存在：

```php
if (!$this->tenants->exists($tenantId)) {
    return $this->responseFactory->create(['error' => 'Tenant not found.'], 422);
}
```

若没有此检查，笔记将为不存在于 tenants 表的幽灵租户 ID 创建，破坏引用完整性。

---

## 攻击检查清单（ATK-01 到 ATK-12）

| # | 测试 | 预期结果 |
|---|------|----------|
| ATK-01 | 无认证头部 | 401 |
| ATK-02 | 跨租户 GET（IDOR） | 404 — 笔记存在但不属于此租户 |
| ATK-03 | X-Tenant-Id: `"1"`、`1.5`、`+1`、`1 OR 1=1` | 401 — V::userId 拒绝 |
| ATK-04 | POST 请求体包含 `tenant_id: 99` | 201 — 请求体 tenant_id 被忽略 |
| ATK-05 | 跨租户 DELETE | 404 — 笔记未被删除 |
| ATK-06 | X-Tenant-Id: `0`、`-1` | 401 |
| ATK-07 | X-Tenant-Id: 20 位溢出 | 401 |
| ATK-08 | 无 X-Admin-Key 创建租户 | 401 |
| ATK-09 | 错误的 X-Admin-Key | 401 |
| ATK-10 | 为不存在的租户 ID 创建笔记 | 422 |
| ATK-11 | 列表：T1 只看到 T1 的笔记，看不到 T2 的 | 通过 SQL WHERE tenant_id 强制执行 |
| ATK-12 | `?limit=-1`、`?limit=10.5`、20 位 limit | 422 — V::queryInt 防护 |

---

## 响应策略：404 而非 403

检测到跨租户 IDOR 时，返回 **404** 而非 403 Forbidden。

- `403` 泄露存在性："资源存在但你无法访问它"
- `404` 不透露任何信息："此租户没有这样的资源"

这防止了租户枚举攻击。
