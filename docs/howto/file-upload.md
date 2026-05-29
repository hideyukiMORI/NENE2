---
title: "File upload (base64 JSON)"
category: api-design
tags: [file-upload, base64, json-api, request-body]
difficulty: beginner
related: [file-upload-metadata, file-sharing-api]
---

# File upload (base64 JSON)

NENE2 is a JSON-first framework. It does not have built-in multipart/form-data parsing. The recommended pattern for file upload in a JSON API is to receive files as base64-encoded strings in the JSON request body.

## requestMaxBodyBytes — the first thing to set

base64 encoding increases payload size by approximately 33%. A 2 MiB file becomes ~2.7 MiB of base64 characters before JSON envelope overhead.

NENE2's default `requestMaxBodyBytes` is **1 MiB**. This means files larger than ~750 KB will be rejected with `413 Request Entity Too Large` before your route handler is called.

**Always set `requestMaxBodyBytes` to at least 1.4× your intended file size limit:**

```php
new RuntimeApplicationFactory(
    $responseFactory,
    $streamFactory,
    routeRegistrars:     [...],
    requestMaxBodyBytes: 10 * 1024 * 1024,  // allows ~7.5 MiB files
)
```

| Max file size | Minimum requestMaxBodyBytes |
|---|---|
| 500 KB | 700 KB |
| 1 MiB | 1.5 MiB |
| 2 MiB | 3 MiB |
| 10 MiB | 14 MiB |

## MIME type detection — never trust the client

Always detect MIME type from the actual decoded bytes using `finfo_buffer()`. Never rely on the client-supplied `Content-Type` header or the file extension — both can be spoofed.

```php
$bytes = base64_decode($base64Content, strict: true);
if ($bytes === false) {
    // invalid base64
}

$finfo = new \finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->buffer($bytes);

$allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($mime, $allowed, true)) {
    // reject — even if filename says .jpg
}
```

This correctly rejects a PHP script uploaded as `avatar.jpg` — `finfo` reads the actual bytes, not the filename.

## File size validation

Validate the **decoded byte count**, not the length of the base64 string:

```php
$size = strlen($bytes);  // bytes after base64_decode(), not strlen($base64String)
if ($size > 2 * 1024 * 1024) {
    // 422: file too large
}
```

## Filename sanitization — path traversal checklist

Never write a file using a user-supplied filename directly. Apply all of the following:

```php
function sanitizeFilename(string $filename): string
{
    // 1. Strip directory components (path traversal)
    $name = basename($filename);

    // 2. Remove null bytes ("image.png\x00.php" attack)
    $name = str_replace("\x00", '', $name);

    // 3. Remove leading dots (hidden files)
    $name = ltrim($name, '.');

    // 4. Replace non-alphanumeric characters
    $name = preg_replace('/[^\w\-.]/', '_', $name) ?? '_';

    // 5. Neutralize dangerous script extensions
    $dangerousExts = ['php', 'phtml', 'phar', 'cgi', 'py', 'sh', 'exe'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, $dangerousExts, true)) {
        $name = pathinfo($name, PATHINFO_FILENAME) . '_' . $ext;
    }

    return $name !== '' ? $name : 'file';
}
```

**Always prefix the stored filename with a random token** to prevent enumeration:

```php
$stored = bin2hex(random_bytes(8)) . '_' . sanitizeFilename($original);
file_put_contents($storageDir . '/' . $stored, $bytes);
```

## PHP exception property name collision

When extending a built-in exception class, do not declare a readonly property named `$code`, `$message`, `$file`, or `$line` — these are non-readonly on the base class and PHP will throw a fatal error:

```php
// Fatal error: Cannot redeclare non-readonly property Exception::$code
final class UploadException extends \InvalidArgumentException {
    public function __construct(
        public readonly string $code,  // ← conflict with Exception::$code
    ) {}
}

// Use a different name:
final class UploadException extends \InvalidArgumentException {
    public function __construct(
        public readonly string $errorCode,  // ← safe
    ) {}
}
```

## Complete example

```php
final class FileValidator
{
    private const array ALLOWED_MIME  = ['image/jpeg', 'image/png', 'image/gif'];
    private const int   MAX_BYTES     = 2 * 1024 * 1024;

    /** @return array{bytes: string, mime: string, size: int} */
    public function validate(string $base64, string $filename): array
    {
        $bytes = base64_decode($base64, strict: true);
        if ($bytes === false) {
            throw new UploadValidationException(field: 'content', errorCode: 'invalid-base64', message: '...');
        }
        $size = strlen($bytes);
        if ($size > self::MAX_BYTES) {
            throw new UploadValidationException(field: 'content', errorCode: 'file-too-large', message: '...');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            throw new UploadValidationException(field: 'content', errorCode: 'unsupported-mime-type', message: '...');
        }
        return ['bytes' => $bytes, 'mime' => $mime, 'size' => $size];
    }
}
```

Wire in the route handler:

```php
$body     = JsonRequestBodyParser::parse($request);
$content  = is_string($body['content'] ?? null) ? $body['content'] : '';
$filename = is_string($body['filename'] ?? null) ? $body['filename'] : '';

if ($content === '' || $filename === '') { /* 422 */ }

try {
    $validated = $validator->validate($content, $filename);
} catch (UploadValidationException $e) {
    return $problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => $e->field, 'code' => $e->errorCode, 'message' => $e->getMessage()]],
    ]);
}

$stored = bin2hex(random_bytes(8)) . '_' . sanitizeFilename($filename);
file_put_contents('/var/storage/' . $stored, $validated['bytes']);
```
