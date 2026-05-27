# 操作指南：带 SSRF 防护的 URL 短链服务

> **FT 参考**：FT337（`NENE2-FT/shortlog`）— 带 SSRF 阻断的 URL 短链服务（私有 IP、回环、链路本地、危险 scheme），slug 验证、大量赋值防护、ISO 8601 日期验证、ReDoS 安全的 limit 解析，50+ tests 全部 PASS。

本指南展示如何构建一个只接受安全公开 URL 的 URL 短链服务，同时验证 slug、防止大量赋值，并防范服务器端请求伪造（SSRF）。

## 数据库结构

```sql
CREATE TABLE links (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    slug         TEXT    NOT NULL UNIQUE,
    original_url TEXT    NOT NULL,
    expires_at   TEXT,               -- ISO 8601，可为空
    click_count  INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT    NOT NULL
);
```

## 端点

| 方法 | 路径 | 说明 |
|------|------|------|
| `POST` | `/links` | 创建短链 |
| `GET` | `/links` | 列出自己的链接 |
| `GET` | `/links/{slug}` | 按 slug 获取链接 |
| `DELETE` | `/links/{slug}` | 删除自己的链接 |

## 创建短链

```php
POST /links
X-User-Id: 1
{
  "original_url": "https://example.com/very/long/path",
  "slug": "my-link",
  "expires_at": "2030-12-31T23:59:59+09:00"
}
→ 201
{
  "id": 1,
  "user_id": 1,
  "slug": "my-link",
  "original_url": "https://example.com/very/long/path",
  "expires_at": "2030-12-31T23:59:59+09:00",
  "click_count": 0,
  "created_at": "..."
}
```

`slug` 可选——省略时自动生成（`[a-z0-9_-]+`）。

### 缺少认证

```php
POST /links  （无 X-User-Id 头部）
→ 401
```

### 重复 slug

```php
POST /links  {"slug": "my-link"}  // 已存在
→ 409
```

## Slug 验证

```
有效：小写字母、数字、连字符、下划线
长度：3–20 个字符

有效示例："abc"、"my-link"、"link123"、"test-link-01"
```

```php
POST /links  {"slug": "ab"}          → 422  // 过短（最小 3）
POST /links  {"slug": "a".repeat(21)} → 422  // 过长（最大 20）
POST /links  {"slug": "MySlug"}       → 422  // 不允许大写
POST /links  {"slug": "sl@g!"}        → 422  // 特殊字符
POST /links  {"slug": "my slug"}      → 422  // 不允许空格
POST /links  {"slug": 42}             → 422  // 类型必须是字符串（VULN-B）
```

## URL 验证

```php
POST /links  {"original_url": ""}              → 422  // 空
POST /links  {}                                → 422  // 缺失
POST /links  {"original_url": 42}              → 422  // 非字符串（VULN-B）
POST /links  {"original_url": true}            → 422  // 布尔（VULN-B）
POST /links  {"original_url": null}            → 422  // null（VULN-B）
POST /links  {"original_url": "https://..."+"x".repeat(2030)}  → 422  // 过长
```

## SSRF 防护

阻断会让服务器调用内部基础设施的 URL：

### 被阻断的 scheme

```php
POST /links  {"original_url": "javascript:alert(1)"}  → 422
POST /links  {"original_url": "file:///etc/passwd"}   → 422
POST /links  {"original_url": "ftp://example.com/"}   → 422
```

只允许 `http://` 和 `https://`。

### 被阻断的 IP 范围

```php
// 回环
POST /links  {"original_url": "http://127.0.0.1/admin"}     → 422
POST /links  {"original_url": "http://localhost/secret"}     → 422
POST /links  {"original_url": "http://internal.localhost/"}  → 422  // *.localhost

// RFC 1918 私有范围
POST /links  {"original_url": "http://10.0.0.1/metadata"}    → 422
POST /links  {"original_url": "http://192.168.1.1/router"}   → 422
POST /links  {"original_url": "http://172.16.0.1/internal"}  → 422

// 链路本地（AWS 元数据等）
POST /links  {"original_url": "http://169.254.169.254/latest/meta-data/"}  → 422

// 公共 IP — 接受
POST /links  {"original_url": "https://8.8.8.8/"}            → 201  ✅
```

### DNS 重绑定防护

解析到私有 IP 的主机名也被阻断：

```php
// "private.internal" 解析为 10.0.0.1 → 阻断
POST /links  {"original_url": "http://private.internal/data"}  → 422

// "public.example.com" 解析为 93.184.216.34 → 允许
POST /links  {"original_url": "https://public.example.com/page"}  → 201  ✅
```

### 实现

```php
private const BLOCKED_RANGES = [
    '127.',          // 回环
    '10.',           // RFC 1918
    '172.16.', '172.17.', '172.18.', '172.19.',
    '172.20.', '172.21.', '172.22.', '172.23.',
    '172.24.', '172.25.', '172.26.', '172.27.',
    '172.28.', '172.29.', '172.30.', '172.31.',  // RFC 1918
    '192.168.',      // RFC 1918
    '169.254.',      // 链路本地
];

private const ALLOWED_SCHEMES = ['http', 'https'];

public function validate(string $url): bool
{
    $parsed = parse_url($url);
    if (!$parsed || !in_array($parsed['scheme'] ?? '', self::ALLOWED_SCHEMES, true)) {
        return false;
    }

    $host = $parsed['host'] ?? '';

    // 阻断 *.localhost
    if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
        return false;
    }

    // 将主机名解析为 IP
    $ip = ($this->dnsResolver)($host);

    foreach (self::BLOCKED_RANGES as $prefix) {
        if (str_starts_with($ip, $prefix)) {
            return false;
        }
    }

    return true;
}
```

## 大量赋值防护

```php
// 攻击者尝试设置 click_count 或 created_at
POST /links
{
  "original_url": "https://example.com",
  "slug": "attack",
  "click_count": 999999,
  "created_at": "2000-01-01T00:00:00+00:00"
}
→ 201  {"click_count": 0, "created_at": "2026-..."}  // 字段被忽略
```

只从请求体中白名单 `original_url`、`slug`、`expires_at`。永远不要从请求体读取 `click_count`、`created_at` 或 `user_id`。

## ISO 8601 日期验证

```php
// 无效的日历日期
POST /links  {"expires_at": "2024-02-30T00:00:00+00:00"}  → 422  // 2月30日
POST /links  {"expires_at": "2024-13-01T00:00:00+00:00"}  → 422  // 13月
POST /links  {"expires_at": "2030-06-01T00:00:00+25:00"}  → 422  // +25:00 偏移

// 有效
POST /links  {"expires_at": "2030-06-01T00:00:00+09:00"}  → 201  ✅
```

验证模式：使用 `DateTimeImmutable::createFromFormat()` 解析并验证往返一致性：

```php
$dt = DateTimeImmutable::createFromFormat(DATE_RFC3339, $value);
if ($dt === false) return false;
// 往返检查捕获 PHP 将 "2024-02-30" 规范化为 "2024-03-01" 的情况
return $dt->format(DATE_RFC3339) === $value;
```

## ReDoS 安全的 Limit 验证

```php
// ctype_digit 为 O(n) — 不受 ReDoS 影响
GET /links?limit=10       → 200  ✅
GET /links?limit=999999   → 422  // 超过 MAX_LIMIT
GET /links?limit=9...9 (19位)  → 422  // 溢出防护
GET /links?limit=111...1x (51字符含x)  → 422, <100ms  // ReDoS 载荷
```

## IDOR 防护

```php
// 用户 2 尝试删除用户 1 的链接
DELETE /links/user1-link
X-User-Id: 2
→ 404  // 不是 403 — 防止枚举
```

链接存在，但查找限定为 `WHERE slug = ? AND user_id = ?`。不匹配返回 404，如同链接不存在。

---

## 不应做的事

| 反模式 | 风险 |
|---|---|
| 允许 `http://localhost` 或 `http://127.0.0.1` | 服务器通过短链获取自己的管理端点 |
| 跳过 DNS 解析检查 | 攻击者注册 `evil.example.com` → A 记录 `10.0.0.1` 绕过 IP 字面量检查 |
| 允许 `javascript:` scheme | 在任何打开重定向的浏览器中通过短链实现 XSS |
| 允许 `file://` scheme | 短链服务在创建时获取 URL 则读取 `/etc/passwd` |
| 从请求体接受 `click_count` | 攻击者虚增点击指标 |
| 无 slug 长度/字符集限制 | `slug = "' OR 1=1--"` 通过验证到达 SQL |
| 使用正则 `/^\d+$/` 进行 limit 验证 | 长混合数字载荷产生 ReDoS |
| 从请求体返回 `created_at` | 时间篡改破坏审计跟踪 |
