# 文件上传（base64 JSON）

NENE2 是一个 JSON 优先的框架，没有内置的 multipart/form-data 解析。JSON API 中推荐的文件上传模式是在 JSON 请求体中以 base64 编码字符串接收文件。

## requestMaxBodyBytes——首要配置项

base64 编码会将负载大小增加约 33%。一个 2 MiB 的文件在 JSON 信封开销之前会变成约 2.7 MiB 的 base64 字符。

NENE2 默认的 `requestMaxBodyBytes` 为 **1 MiB**。这意味着超过约 750 KB 的文件在路由处理程序被调用之前就会被拒绝，返回 `413 Request Entity Too Large`。

**始终将 `requestMaxBodyBytes` 设置为至少 1.4 倍预期文件大小限制：**

```php
new RuntimeApplicationFactory(
    $responseFactory,
    $streamFactory,
    routeRegistrars:     [...],
    requestMaxBodyBytes: 10 * 1024 * 1024,  // 允许约 7.5 MiB 的文件
)
```

| 最大文件大小 | 最小 requestMaxBodyBytes |
|---|---|
| 500 KB | 700 KB |
| 1 MiB | 1.5 MiB |
| 2 MiB | 3 MiB |
| 10 MiB | 14 MiB |

## MIME 类型检测——永远不要信任客户端

始终使用 `finfo_buffer()` 从实际解码字节中检测 MIME 类型。永远不要依赖客户端提供的 `Content-Type` 请求头或文件扩展名——两者都可以被伪造。

```php
$bytes = base64_decode($base64Content, strict: true);
if ($bytes === false) {
    // base64 无效
}

$finfo = new \finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->buffer($bytes);

$allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($mime, $allowed, true)) {
    // 拒绝——即使文件名是 .jpg
}
```

这可以正确拒绝以 `avatar.jpg` 上传的 PHP 脚本——`finfo` 读取的是实际字节，而非文件名。

## 文件大小校验

校验**解码后的字节数**，而非 base64 字符串的长度：

```php
$size = strlen($bytes);  // base64_decode() 后的字节数，而非 strlen($base64String)
if ($size > 2 * 1024 * 1024) {
    // 422：文件过大
}
```

## 文件名清理——路径遍历检查清单

永远不要直接使用用户提供的文件名写入文件。需应用以下所有步骤：

```php
function sanitizeFilename(string $filename): string
{
    // 1. 去除目录组件（路径遍历）
    $name = basename($filename);

    // 2. 去除空字节（"image.png\x00.php" 攻击）
    $name = str_replace("\x00", '', $name);

    // 3. 去除前导点（隐藏文件）
    $name = ltrim($name, '.');

    // 4. 替换非字母数字字符
    $name = preg_replace('/[^\w\-.]/', '_', $name) ?? '_';

    // 5. 中和危险脚本扩展名
    $dangerousExts = ['php', 'phtml', 'phar', 'cgi', 'py', 'sh', 'exe'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, $dangerousExts, true)) {
        $name = pathinfo($name, PATHINFO_FILENAME) . '_' . $ext;
    }

    return $name !== '' ? $name : 'file';
}
```

**始终为存储的文件名添加随机令牌前缀**以防止枚举攻击：

```php
$stored = bin2hex(random_bytes(8)) . '_' . sanitizeFilename($original);
file_put_contents($storageDir . '/' . $stored, $bytes);
```

## PHP 异常属性名冲突

扩展内置异常类时，不要声明名为 `$code`、`$message`、`$file` 或 `$line` 的 readonly 属性——这些在基类中是非 readonly 的，PHP 会抛出致命错误：

```php
// 致命错误：Cannot redeclare non-readonly property Exception::$code
final class UploadException extends \InvalidArgumentException {
    public function __construct(
        public readonly string $code,  // ← 与 Exception::$code 冲突
    ) {}
}

// 使用不同的名称：
final class UploadException extends \InvalidArgumentException {
    public function __construct(
        public readonly string $errorCode,  // ← 安全
    ) {}
}
```

## 完整示例

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

在路由处理程序中接入：

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
