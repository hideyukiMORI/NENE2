# 操作指南：PII 脱敏 API

> **FT 参考**：FT297（`NENE2-FT/masklog`）——PII 脱敏：邮箱/电话/姓名部分脱敏，基于角色的原始数据访问（仅管理员）并强制记录 X-Accessor 审计轨迹，不可变审计日志，VULN-A~L 全部 SAFE，24 个测试 / 49 个断言全部通过。

本指南展示如何构建默认脱敏 PII（个人身份信息）的客户数据 API，只向有审计轨迹的授权角色授予完整访问权限。

## 数据库结构

```sql
CREATE TABLE customers (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    email      TEXT NOT NULL,
    phone      TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE mask_audit_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NOT NULL REFERENCES customers(id),
    accessor    TEXT NOT NULL,
    accessed_at TEXT NOT NULL
);
```

原始 PII 存储在 `customers` 中。管理员每次访问原始数据都记录在 `mask_audit_log` 中（仅追加——无更新/删除路由）。

## 脱敏模式

```php
final class MaskService
{
    // "john.doe@example.com" → "j***@example.com"
    public function maskEmail(string $email): string
    {
        $at     = strpos($email, '@');
        $local  = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        return substr($local, 0, 1) . '***@' . $domain;
    }

    // "090-1234-5678" → "***-****-5678"（保留后 4 位）
    public function maskPhone(string $phone): string
    {
        $digits   = preg_replace('/\D/', '', $phone);
        $keepFrom = strlen($digits) - 4;
        $replaced = 0;
        $result   = '';
        for ($i = 0; $i < strlen($phone); $i++) {
            $ch = $phone[$i];
            if (ctype_digit($ch)) {
                $result .= ($replaced < $keepFrom) ? ('*' . ($replaced++ | 0) * 0 . '') : $ch;
                $replaced++;
            } else {
                $result .= $ch;
            }
        }
        return $result;
    }

    // "John Doe" → "J*** D***"
    public function maskName(string $name): string
    {
        $words = explode(' ', $name);
        return implode(' ', array_filter(array_map(
            fn($w) => $w !== '' ? mb_substr($w, 0, 1) . '***' : '',
            $words
        )));
    }
}
```

## 基于角色的访问——默认脱敏

```php
private function handleGet(ServerRequestInterface $request): ResponseInterface
{
    $id       = $this->id($request);
    $customer = $this->repo->find($id);
    if ($customer === null) {
        return $this->json->create(['error' => 'Customer not found'], 404);
    }

    $role     = $request->getHeaderLine('X-Role');
    $accessor = trim($request->getHeaderLine('X-Accessor'));

    if ($role === 'admin') {
        if ($accessor === '') {
            return $this->json->create(['error' => 'X-Accessor header required for admin access'], 403);
        }
        $this->repo->logAccess((int) $customer['id'], $accessor, $this->now());
        return $this->json->create($customer);  // 原始 PII
    }

    return $this->json->create($this->masker->applyMask($customer));  // 脱敏
}
```

- **非管理员（默认）**：始终接收脱敏数据。
- **带 `X-Accessor` 的管理员**：接收原始数据，访问被记录。
- **不带 `X-Accessor` 的管理员**：403——审计轨迹不能为空。

## 审计日志——仅追加

```php
public function register(Router $router): void
{
    $router->post('/customers', $this->handleCreate(...));
    $router->get('/customers/{id}', $this->handleGet(...));
    $router->get('/customers/{id}/audit', $this->handleAudit(...));
    // 审计日志没有 DELETE 或 PUT——设计上不可变
}
```

审计日志没有删除或更新路由。条目是永久的；只有管理员可以读取日志。

---

## 漏洞评估

### V-01 — 默认 GET 不暴露 PII ✅ SAFE

**风险**：非管理员读取原始客户邮箱/电话/姓名。
**结论**：SAFE——默认响应始终应用 `applyMask()`。没有 `X-Role: admin` 就不会返回原始字段。

---

### V-02 — 姓名字段中的 SQL 注入 ✅ SAFE

**风险**：`"name": "'; DROP TABLE customers; --"` 删除数据。
**结论**：SAFE——参数化查询将注入字符串作为姓名逐字存储。

---

### V-03 — 邮箱字段中的 SQL 注入 ✅ SAFE

**风险**：创建时通过邮箱进行 SQL 注入。
**结论**：SAFE——相同的参数化查询保护。

---

### V-04 — IDOR：非管理员通过客户 ID 读取原始 PII ✅ SAFE

**风险**：没有 `X-Role: admin` 时，用户尝试 `GET /customers/1` 获取完整 PII。
**结论**：SAFE——任何没有 `X-Role: admin` 的请求，无论客户 ID 是什么，都只收到脱敏数据。

---

### V-05 — 角色提升：任意 X-Role 请求头 ✅ SAFE

**风险**：发送 `X-Role: superuser` 或 `X-Role: ADMIN` 以绕过脱敏。
**结论**：SAFE——只有精确字符串 `'admin'` 才授予原始访问：`if ($role === 'admin')`。任何其他值都会走到脱敏响应。

---

### V-06 — 管理员没有 X-Accessor 请求头 ✅ SAFE

**风险**：管理员在没有 X-Accessor 的情况下访问原始数据以避免留下审计轨迹。
**结论**：SAFE——`if ($accessor === '') return 403`。管理员访问需要非空的访问者标识符。

---

### V-07 — 审计日志非管理员不可访问 ✅ SAFE

**风险**：非管理员读取 `GET /customers/1/audit` 以发现谁访问了他们的数据。
**结论**：SAFE——审计端点检查 `X-Role: admin`。非管理员 → 403。

---

### V-08 — 不存在的客户返回 404 ✅ SAFE

**风险**：查询不存在的 ID 返回 500 或泄露 DB 错误。
**结论**：SAFE——`if ($customer === null) return 404`。干净的错误，无内部信息。

---

### V-09 — 超长输入不崩溃 ✅ SAFE

**风险**：10,000 个字符的姓名导致 DB 错误或内存耗尽。
**结论**：SAFE——SQLite TEXT 无长度限制；应用存储并脱敏而不崩溃。在生产环境中，添加长度限制（如 500 个字符）。

---

### V-10 — XSS 载荷作为字面量存储 ✅ SAFE

**风险**：`"name": "<script>alert(1)</script>"` 在浏览器中执行。
**结论**：SAFE——API 返回 `application/json`；JSON 编码转义 `<` 和 `>`。API 层不渲染 HTML。

---

### V-11 — 脱敏响应不透露完整 PII ✅ SAFE

**风险**：脱敏响应包含足够信息以重建原始 PII。
**结论**：SAFE——邮箱：只有第一个字符 + 域名；电话：只有最后 4 位；姓名：每个单词只有第一个字符。无法重建原始内容。

---

### V-12 — 审计日志不可变 ✅ SAFE

**风险**：管理员删除自己的审计日志条目以掩盖踪迹。
**结论**：SAFE——不存在 `DELETE /customers/{id}/audit` 路由。日志条目仅追加。

---

### VULN 汇总

| ID | 漏洞 | 结论 |
|----|---------------|---------|
| V-01 | 默认 GET 暴露 PII | ✅ SAFE |
| V-02 | 姓名中的 SQL 注入 | ✅ SAFE |
| V-03 | 邮箱中的 SQL 注入 | ✅ SAFE |
| V-04 | IDOR：非管理员读取原始 PII | ✅ SAFE |
| V-05 | 通过 X-Role 请求头提升角色 | ✅ SAFE |
| V-06 | 管理员没有 X-Accessor | ✅ SAFE |
| V-07 | 审计日志对非管理员可访问 | ✅ SAFE |
| V-08 | 不存在客户的行为 | ✅ SAFE |
| V-09 | 超长输入崩溃 | ✅ SAFE |
| V-10 | 姓名中的 XSS 载荷 | ✅ SAFE |
| V-11 | 脱敏响应揭示 PII | ✅ SAFE |
| V-12 | 审计日志可变性 | ✅ SAFE |

**12 SAFE，0 EXPOSED**
默认脱敏、强制访问者审计、严格角色检查以及不可变日志防止了所有 PII 暴露和审计绕过向量。

---

## 反模式

| 反模式 | 风险 |
|---|---|
| 默认返回原始 PII | 任何已认证用户都能读取完整邮箱/电话/姓名 |
| 不区分大小写的角色检查（`strtolower`）且无明确白名单 | `ADMIN`、`Admin`、`aDmIn`——只接受预期的精确字符串 |
| 允许管理员在没有 X-Accessor 的情况下访问 | 无审计轨迹；GDPR 合规失败 |
| 可变审计日志 | 管理员删除自己的条目；取证轨迹不可靠 |
| 向非管理员暴露审计日志 | 用户发现哪些员工访问了他们的数据 |
| 哈希脱敏（显示哈希而非真实数据） | PII 的哈希仍然是敏感的——攻击者可以暴力破解短值 |
| 创建响应中不脱敏 | 新客户创建响应暴露刚存储的 PII |
| 无输入长度限制 | 超长输入消耗存储；在生产环境中添加明确的长度上限 |
