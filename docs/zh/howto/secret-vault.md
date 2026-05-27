# 操作指南：个人密钥保险箱 API

演示带 HMAC 完整性、IDOR 防护和仅管理员元数据访问的按用户键值存储。字段试验：FT195（`../NENE2-FT/vaultlog/`）。包含 VULN-A~L 安全审计。

---

## 模式摘要

| 关注点 | 方式 |
|---|---|
| 用户隔离 | 每个查询都有 `WHERE user_id = :uid`——IDOR 不可能 |
| 管理员永远看不到值 | 管理员端点只返回 `user_id + key` |
| HMAC 完整性 | `HMAC-SHA256(userId|key|value, secret)` 按条目存储 |
| 密钥校验 | `preg_match('/\A[a-z0-9_-]{1,64}\z/', $key)`——安全，无 ReDoS 风险 |
| 用户 ID 校验 | `ctype_digit()` + 长度守护 + `> 0` 检查 |
| 管理员密钥 | `hash_equals()` 恒定时间，空密钥时故障关闭 |
| Upsert | `UNIQUE(user_id, key_name)` → 首次存储（201）或更新（200） |

---

## 路由

| 方法 | 路径 | 认证 | 说明 |
|---|---|---|---|
| `POST` | `/vault` | `X-User-Id` | 存储或更新密钥 |
| `GET` | `/vault` | `X-User-Id` | 列出用户的密钥名称（无值） |
| `GET` | `/vault/{key}` | `X-User-Id` | 获取用户的密钥值 |
| `DELETE` | `/vault/{key}` | `X-User-Id` | 删除用户的密钥 |
| `GET` | `/admin/vault` | `X-Admin-Key` | 列出所有用户 + 密钥（无值） |
| `GET` | `/admin/vault/{userId}` | `X-Admin-Key` | 列出特定用户的密钥 |

---

## 数据库结构

```sql
CREATE TABLE vault_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    key_name   TEXT    NOT NULL,
    value      TEXT    NOT NULL,
    hmac       TEXT    NOT NULL,   -- HMAC-SHA256 完整性标签
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE (user_id, key_name)
);
```

`UNIQUE(user_id, key_name)` 约束强制每个（用户，密钥）对只有一条记录。

---

## HMAC 完整性

```php
private function computeHmac(int $userId, string $key, string $value): string
{
    return hash_hmac('sha256', "{$userId}|{$key}|{$value}", $this->hmacSecret);
}
```

在 GET 时，处理器验证存储的 HMAC：

```php
if (!$this->repo->verifyIntegrity($entry)) {
    return $this->problem(500, 'integrity-error', 'Secret integrity check failed.');
}
```

这可以检测直接的 DB 篡改（例如，被入侵的 DBA 不通过 API 直接修改值）。

---

## IDOR 防护

每个查询都包含 `user_id = :uid`：

```sql
SELECT * FROM vault_entries WHERE user_id = :uid AND key_name = :key
```

用户 200 查询用户 100 拥有的密钥 `private-key` 会得到 404——与"未找到"完全相同，防止枚举其他用户存在哪些密钥。

管理员端点从不返回 `value`：

```php
// 用户看到自己的值
public function toUserArray(): array
{
    return ['key' => ..., 'value' => $this->value, ...];
}

// 管理员只看到元数据——无值
public function toAdminArray(): array
{
    return ['user_id' => ..., 'key' => ..., ...];
}
```

---

## 密钥校验

```php
private const string KEY_PATTERN = '/\A[a-z0-9_-]{1,64}\z/';
```

`\A` 和 `\z` 锚点防止部分匹配。字符类最小：小写字母数字、连字符、下划线。长度有界 `{1,64}`——无回溯放大。

拒绝：
- 大写字母（`UPPER_CASE`）
- 空格或特殊字符
- 路径遍历片段（`../etc/passwd`）
- 可 SQL 注入字符串（`' OR '1'='1`）
- 空字符串或超过 64 个字符的字符串

---

## 用户 ID 校验

```php
private function resolveUserId(ServerRequestInterface $request): ?int
{
    $raw = $request->getHeaderLine('X-User-Id');
    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) return null;
    $id = (int) $raw;
    return $id > 0 ? $id : null;
}
```

- `ctype_digit()` 拒绝负数（`-` 符号不是数字）
- `strlen > 18` 防止整数溢出（`PHP_INT_MAX` 有 19 位数字）
- `> 0` 拒绝 `"0"` 作为无效用户 ID

---

## Upsert 模式

```php
public function store(int $userId, string $key, string $value): string
{
    $existing = $this->findEntry($userId, $key);
    if ($existing !== null) {
        // UPDATE ...
        return 'updated';  // → 200
    }
    // INSERT ...
    return 'stored';  // → 201
}
```

首次写入返回 `'stored'`（201），覆盖写入返回 `'updated'`（200）。处理器将这些映射到 HTTP 状态码。

---

## VULN-A~L 结果

| 检查 | 测试 | 结果 |
|---|---|---|
| VULN-A | 密钥参数/请求体中的 SQL 注入 | PASS——密钥校验在查询前拒绝 |
| VULN-B | IDOR：用户读取/删除其他用户的密钥 | PASS——跨用户访问返回 404 |
| VULN-C | 列表只返回自己的条目 | PASS——`WHERE user_id` 作用域 |
| VULN-D | 管理员密钥暴力破解/绕过 | PASS——`hash_equals` + 故障关闭 |
| VULN-E | 值中的 XSS | PASS——原样存储，JSON 响应不是 HTML |
| VULN-F | 密钥 upsert 幂等性 | PASS——最后写入胜出，无重复 |
| VULN-G | 密钥中的路径遍历 | PASS——模式拒绝 `..` 和斜杠 |
| VULN-H | 负数/零用户 ID | PASS——`ctype_digit` + `> 0` 守护 |
| VULN-I | 极大用户 ID（溢出） | PASS——`strlen > 18` 守护 |
| VULN-J | 路径中的空字节 | PASS——路由器/模式拒绝 |
| VULN-K | 请求体中的密钥过长 | PASS——422 校验 |
| VULN-L | 空 HMAC 密钥（不崩溃） | PASS——确定性 HMAC 空密钥，不崩溃 |

---

## 测试说明

- `AppFactory::create(?PDO, ?string adminKey, ?string hmacSecret)` — 所有参数可注入用于单元测试。
- `withParsedBody($body)` 在测试辅助方法中是必需的（Nyholm PSR-7 不自动解析 JSON）。
- IDOR 测试：以用户 100 存储，尝试以用户 200 访问 → 必须返回 404。
- 管理员测试：验证每个响应数组中都没有 `value` 键。
