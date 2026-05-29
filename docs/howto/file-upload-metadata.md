---
title: "How-to: File Upload Metadata API (VULN-A~L)"
category: security
tags: [file-upload, metadata, vulnerability-assessment, ownership]
difficulty: advanced
related: [file-upload, file-metadata-sharing, file-sharing-api]
---

# How-to: File Upload Metadata API (VULN-A~L)

This guide demonstrates secure file upload metadata management covering VULN-A through VULN-L.

## Pattern Overview

Files are not stored by this API — only their metadata (filename, MIME type, size) is recorded. The actual file transfer is handled separately (e.g., direct-to-S3). This is a common pattern for tracking upload history and enforcing constraints.

## Schema

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

## VULN-A: SQL Injection

All queries use PDO prepared statements. Filenames and MIME types submitted by users are never interpolated into SQL strings.

## VULN-B: Mass Assignment + MIME Allowlist

Only an explicit allowlist of MIME types is accepted:

```php
private const array ALLOWED_MIMES = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf', 'text/plain', 'text/csv',
];
```

Unknown MIME types (e.g., `application/x-msdownload`, `application/x-sh`) are rejected with 422.

## VULN-C: IDOR

Non-admin users can only access their own uploads. Other users' uploads return 404 (not 403):

```php
if (!$isAdmin && (int) $upload['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Upload not found.');
}
```

## VULN-D: Admin Fail-Closed

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

## VULN-F: Path Traversal

Directory separators and `..` are rejected in filenames:

```php
if (str_contains($filename, '/') || str_contains($filename, '\\') || str_contains($filename, '..')) {
    return $this->problem(422, 'validation-failed', 'filename must not contain path separators.');
}
```

This prevents filenames like `../etc/passwd`, `C:\Windows\cmd.exe`, or `subdir/evil.php`.

## VULN-G: ReDoS

IDs in path params are validated with `ctype_digit()`, never regex.

## VULN-I: Negative / Zero Values

```php
if (!is_int($sizeBytes) || $sizeBytes < 1 || $sizeBytes > self::MAX_SIZE) {
    return $this->problem(422, ...);
}
```

Zero and negative sizes are rejected.

## VULN-J: Type Confusion

- `mime_type` must be `is_string()` — integer `123` is rejected.
- `size_bytes` must be `is_int()` — string `"1024"` and float `100.5` are rejected.
- `is_public` must be `is_bool()` — string `"true"` and integer `1` are rejected.

## Validation Summary

| Field | Rule |
|---|---|
| `X-User-Id` | Required for POST/DELETE; `ctype_digit`, >0 |
| `filename` | Non-empty, max 255 chars, no `/`, `\`, `..` |
| `mime_type` | String; must be in allowlist |
| `size_bytes` | Integer 1–104,857,600 (100 MiB) |
| `is_public` | Boolean only |

## Routes

```
POST   /uploads              Register upload metadata (X-User-Id required)
GET    /uploads/{id}         Get metadata (owner or admin)
DELETE /uploads/{id}         Delete record (owner or admin)
GET    /users/{userId}/uploads  List user's uploads (owner or admin)
```

## See Also

- FT210 source: `../NENE2-FT/uploadlog/`
- Related: `docs/howto/wish-list-api.md` (FT207, also VULN)
