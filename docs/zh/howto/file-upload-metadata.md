# 操作指南：文件上传元数据 API（VULN-A~L）

本指南演示涵盖 VULN-A 至 VULN-L 的安全文件上传元数据管理。

## 模式概述

此 API 不存储文件本身——只记录元数据（文件名、MIME 类型、大小）。实际文件传输由其他方式处理（例如直传 S3）。这是追踪上传历史和执行约束的常见模式。

## 数据库结构

```sql
CREATE TABLE IF NOT EXISTS uploads (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    filename    TEXT    NOT NULL,
    mime_type   TEXT    NOT NULL,
    size_bytes  INTEGER NOT NULL,
    is_public   INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL
);
```

## VULN-A：SQL 注入

所有查询使用 PDO 预处理语句。用户提交的文件名和 MIME 类型从不插值到 SQL 字符串中。

## VULN-B：大量赋值 + MIME 白名单

仅接受明确白名单中的 MIME 类型：

```php
private const array ALLOWED_MIMES = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf', 'text/plain', 'text/csv',
];
```

未知的 MIME 类型（例如 `application/x-msdownload`、`application/x-sh`）会被拒绝并返回 422。

## VULN-C：IDOR

非管理员用户只能访问自己的上传。其他用户的上传返回 404（而非 403）：

```php
if (!$isAdmin && (int) $upload['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Upload not found.');
}
```

## VULN-D：管理员失败关闭

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

## VULN-F：路径遍历

文件名中拒绝目录分隔符和 `..`：

```php
if (str_contains($filename, '/') || str_contains($filename, '\\') || str_contains($filename, '..')) {
    return $this->problem(422, 'validation-failed', 'filename must not contain path separators.');
}
```

这可防止 `../etc/passwd`、`C:\Windows\cmd.exe` 或 `subdir/evil.php` 等文件名。

## VULN-G：ReDoS

路径参数中的 ID 使用 `ctype_digit()` 校验，从不使用正则表达式。

## VULN-I：负值 / 零值

```php
if (!is_int($sizeBytes) || $sizeBytes < 1 || $sizeBytes > self::MAX_SIZE) {
    return $this->problem(422, ...);
}
```

零值和负值大小会被拒绝。

## VULN-J：类型混淆

- `mime_type` 必须是 `is_string()` — 整数 `123` 会被拒绝。
- `size_bytes` 必须是 `is_int()` — 字符串 `"1024"` 和浮点数 `100.5` 会被拒绝。
- `is_public` 必须是 `is_bool()` — 字符串 `"true"` 和整数 `1` 会被拒绝。

## 校验汇总

| 字段 | 规则 |
|------|------|
| `X-User-Id` | POST/DELETE 必填；`ctype_digit`，>0 |
| `filename` | 非空，最多 255 字符，不含 `/`、`\`、`..` |
| `mime_type` | 字符串；必须在白名单中 |
| `size_bytes` | 整数 1–104,857,600（100 MiB） |
| `is_public` | 仅布尔型 |

## 路由

```
POST   /uploads              注册上传元数据（需要 X-User-Id）
GET    /uploads/{id}         获取元数据（所有者或管理员）
DELETE /uploads/{id}         删除记录（所有者或管理员）
GET    /users/{userId}/uploads  列出用户的上传（所有者或管理员）
```

## 参见

- FT210 源码：`../NENE2-FT/uploadlog/`
- 相关：`docs/howto/wish-list-api.md`（FT207，也包含 VULN）
