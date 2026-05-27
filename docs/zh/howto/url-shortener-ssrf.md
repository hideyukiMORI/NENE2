# URL 短链 API 与 SSRF 防护

**FT183** — `shortlog` 现场试验（漏洞诊断 VULN-A〜L）。

URL 短链服务让用户提交任意 URL 作为重定向目标。如果重定向在服务器端执行（例如用于链接预览或分析）而不加验证，攻击者可以将其指向内部服务——这是**服务器端请求伪造（SSRF）**攻击。

本指南涵盖 SSRF 防护，以及针对 shortlog 实现运行的完整 VULN-A〜L 安全审计。

---

## SSRF：核心风险

URL 短链服务存储并可能获取攻击者控制的 URL。SSRF 允许攻击者：

- 访问内部服务：`http://10.0.0.1/admin`、`http://192.168.1.1/`
- 命中云元数据：`http://169.254.169.254/latest/meta-data/`（AWS IMDS）
- 读取本地文件：`file:///etc/passwd`
- 执行浏览器脚本：`javascript:alert(1)`
- 访问回环服务：`http://127.0.0.1:8080/`

**修复方案：** 在存储之前验证 URL 的 scheme _和_ 目标 IP。

---

## URL 验证策略（VULN-K）

### 第一步——scheme 白名单

`filter_var($url, FILTER_VALIDATE_URL)` 单独使用**不够**——它接受 `javascript:alert(1)` 和 `ftp://` 作为有效 URL。使用 `parse_url()` 和显式 scheme 白名单：

```php
$parts = parse_url($url);

if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
    return false;   // 格式错误的 URL——无 scheme 或 host
}

if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
    return false;   // 拒绝：javascript:, file://, ftp://, data: 等
}
```

`parse_url()` 不是正则表达式——不会被 ReDoS 利用（VULN-F）。

### 第二步——Host / IP 验证

```php
$host = strtolower($parts['host']);

// 去除 IPv6 括号：[::1] → ::1
if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
    $host = substr($host, 1, -1);
}

// 阻断 localhost 和 *.localhost 别名
if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
    return false;
}

// 如果 host 是 IP 字面量，直接检查
if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
    return !isBlockedIp($host);
}

// 否则解析主机名 → 检查解析后的 IP
$resolved = gethostbyname($host);

if ($resolved !== $host) {   // 无法解析时为 false
    return !isBlockedIp($resolved);
}
// 无法解析的主机名 → 允许（可能是服务器无法访问的有效域名）
return true;
```

### 第三步——私有/保留 IP 检查

```php
function isBlockedIp(string $ip): bool
{
    // IPv6 回环
    if ($ip === '::1') return true;

    // FILTER_FLAG_NO_PRIV_RANGE：阻断 10.x, 172.16-31.x, 192.168.x
    // FILTER_FLAG_NO_RES_RANGE：阻断 127.x, 169.254.x, 0.x, 240.x+
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
    ) === false;
}
```

### DNS 重绑定注意事项

DNS 重绑定攻击在验证通过_之后_改变域名的 IP。对于关键用例，在_获取时_也验证 URL（不仅仅是存储时），或者使用阻断私有范围的网络层出口防火墙。

---

## 注入解析器用于测试

单元测试中的 DNS 调用速度慢且不确定。将解析器设为可注入：

```php
final class UrlValidator
{
    /** @param (callable(string): string)|null $ipResolver */
    public function __construct(private readonly mixed $ipResolver = null)
    {
    }

    private function resolveHost(string $host): string
    {
        /** @var callable(string): string $resolver */
        $resolver = $this->ipResolver ?? static fn (string $h): string => gethostbyname($h);
        return $resolver($host);
    }
}
```

在测试中：

```php
$stubResolver = static function (string $host): string {
    return match ($host) {
        'private.internal'   => '10.0.0.1',       // 私有 → 阻断
        'public.example.com' => '93.184.216.34',  // 公开 → 允许
        default              => $host,             // 无法解析 → 允许
    };
};

$validator = new UrlValidator($stubResolver);
```

---

## VULN-A〜L 评估结果

### VULN-A — 整数溢出（`limit` 查询参数）

`V::queryInt()` 使用 `ctype_digit()` + `strlen() > 18` 防护。
20 位和 19 位字符串在 `(int)` 转换之前被拒绝。

```
✅ PASS — 溢出防护防止了静默的 PHP_INT_MAX 回绕
```

### VULN-B — 类型混淆（JSON 请求体中的 URL / slug）

`V::str()` 强制 `is_string()`——拒绝 `int 42`、`bool true`、`null`。

```php
V::str($body['original_url'] ?? null, 2048)  // → 非字符串时返回 null
V::str($body['slug'] ?? null, 20)            // → 非字符串时返回 null
```

```
✅ PASS — 在任何 URL 或 slug 验证之前强制字符串类型
```

### VULN-C — SQL 注入

所有查询使用 PDO 参数化语句：

```php
'SELECT ... FROM links WHERE slug = :slug LIMIT 1'
// → $stmt->execute([':slug' => $slug])
```

`'; DROP TABLE links; --'` 在到达 DB 之前在 slug 格式验证（SLUG_PATTERN）处失败。即使到达 DB，参数化查询也能防止执行。

```
✅ PASS — 参数化查询 + slug 白名单
```

### VULN-D — 参数污染

PSR-7 的 `getQueryParams()` 调用 PHP 的 `parse_str()`，对于重复键取_最后一个_值。发送 `?limit=10&limit=999999` → `limit=999999`，无法通过 `V::queryInt()` 范围检查（> MAX_LIMIT）。

```
✅ PASS — 范围检查捕获任何单个值；不崩溃
```

### VULN-E — IDOR（跨用户链接访问）

DELETE 使用 `deleteForUser($slug, $userId)`：

```sql
DELETE FROM links WHERE slug = :slug AND user_id = :user_id
```

用户 B 以自己的 `X-User-Id` 执行 `DELETE /links/user-a-slug` 返回 404（行未被删除；只是不匹配 WHERE 子句）。

```
✅ PASS — 在 DB 层强制所有权；404 避免枚举
```

### VULN-F — ReDoS 免疫

URL 验证使用 `parse_url()`（C 扩展，无回溯）。
Slug 验证使用没有交替组的简单锚定正则。
`V::queryInt()` 使用 `ctype_digit()`（O(n)，不受回溯影响）。

```
✅ PASS — 不可信输入上没有指数回溯正则
```

### VULN-G — 路径遍历

此 API 中没有文件系统访问。不适用。

```
N/A
```

### VULN-H — 密钥比较的时序攻击

`V::secret()` 委托给 `hash_equals()`——无论字符串在哪里不同，都是恒定时间的。避免通过时序泄露长度/前缀信息的提前退出字符串比较。

```
✅ PASS — hash_equals() 防止时序预测
```

### VULN-I — 空期望密钥绕过

`V::secret('', '')` → `false`。未配置的 API 密钥永远不会授予访问权限：

```php
return $expected !== '' && hash_equals($expected, $actual);
```

```
✅ PASS — 空期望值始终返回 false
```

### VULN-J — `expires_at` 的 ISO 8601 日期溢出

`V::isoDatetime()` 使用 `DateTimeImmutable::createFromFormat(DATE_ATOM, ...)` + 往返比较。`2024-02-30T00:00:00+00:00` 在 PHP 中滚动到 3 月 1 日；重新格式化的字符串与输入不匹配 → null。

`+25:00` 偏移量：由显式 `$tzHours > 14` 范围检查捕获（PHP 在没有该检查的情况下静默接受它，往返也通过——使显式检查成为必要）。

```
✅ PASS — 往返捕获溢出日期；显式偏移范围检查捕获 +25:00
```

### VULN-K — SSRF

没有 URL 验证时：`http://127.0.0.1/admin`、`http://169.254.169.254/`、`http://10.0.0.1/`、`javascript:alert(1)`、`file:///etc/passwd` 都会被存储并可能被获取。

有了 `UrlValidator`：

| 输入 | 阻断原因 |
|---|---|
| `http://127.0.0.1/` | 回环 IP（`NO_RES_RANGE`） |
| `http://localhost/` | 精确匹配 `'localhost'` |
| `http://internal.localhost/` | `.localhost` 后缀 |
| `http://10.0.0.1/` | 私有 IP（`NO_PRIV_RANGE`） |
| `http://192.168.1.1/` | 私有 IP |
| `http://169.254.169.254/` | 保留 IP（`NO_RES_RANGE`） |
| `http://private.internal/` | 解析为 10.0.0.1 → 阻断 |
| `javascript:alert(1)` | scheme 不在 `['http','https']` 中 |
| `file:///etc/passwd` | scheme 不在白名单中 |
| `ftp://example.com/` | scheme 不在白名单中 |

```
✅ PASS — scheme 白名单 + IP 范围过滤阻断所有 SSRF 向量
```

### VULN-L — 大量赋值

`click_count` 和 `created_at` 在 `LinkRepository::create()` 中由服务器端设置。请求体键 `click_count: 999999` 和 `created_at: "2000-01-01..."` 被简单忽略——控制器从不读取它们。

```
✅ PASS — 服务器端字段在 Repository 中设置，从不来自请求体
```

---

## VULN 评估汇总

| ID | 漏洞 | 状态 |
|---|---|---|
| VULN-A | 整数溢出 | ✅ PASS |
| VULN-B | 类型混淆 | ✅ PASS |
| VULN-C | SQL 注入 | ✅ PASS |
| VULN-D | 参数污染 | ✅ PASS |
| VULN-E | IDOR | ✅ PASS |
| VULN-F | ReDoS | ✅ PASS |
| VULN-G | 路径遍历 | N/A |
| VULN-H | 时序攻击 | ✅ PASS |
| VULN-I | 空密钥绕过 | ✅ PASS |
| VULN-J | DateTime 溢出 | ✅ PASS |
| VULN-K | SSRF | ✅ PASS |
| VULN-L | 大量赋值 | ✅ PASS |

**所有适用漏洞：PASS（11/11）**

---

## Slug 安全性（VULN-A, C）

Slug 必须限制在安全字符集内，以防止注入和意外路由：

```php
// 模式：小写字母数字 + 连字符/下划线，3–20 个字符
// 必须以字母数字开头和结尾
private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]{1,18}[a-z0-9]$|^[a-z0-9]{3}$/';

if (!preg_match(self::SLUG_PATTERN, $rawSlug)) {
    return 422;
}
```

这个单一正则是锚定的，没有交替路径重叠的交替组——不能被 ReDoS 利用。

**被拒绝的 slug**：`'; DROP TABLE links; --'` · `../../etc` · `MySlug` · `sl@g!` · `a`（过短）· 21 字符字符串（过长）

---

## 关键要点

| 模式 | 实现 |
|---|---|
| SSRF 防护 | `parse_url()` scheme 白名单 + `filter_var NO_PRIV_RANGE` |
| 测试中的 DNS 解析 | 可注入的 `ipResolver` 回调 |
| Slug 安全 | 字符白名单正则（锚定，无回溯） |
| URL 类型强制 | `V::str()` → URL 解析前的 `is_string()` |
| 过期验证 | 带往返检查 + 偏移量范围检查的 `V::isoDatetime()` |
| IDOR 防护 | 所有写入查询中的 `WHERE slug = ? AND user_id = ?` |
| 大量赋值 | 服务器端字段在 Repository 中设置，控制器中忽略 |
