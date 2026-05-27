# ファイルアップロード（base64 JSON）

NENE2 は JSON ファースト フレームワークです。multipart/form-data パースを組み込みでサポートしていません。JSON API でのファイルアップロードの推奨パターンは、JSON リクエストボディで base64 エンコードされた文字列としてファイルを受信することです。

## requestMaxBodyBytes — 最初に設定すること

base64 エンコードはペイロードサイズを約 33% 増加させます。2 MiB のファイルは JSON エンベロープのオーバーヘッド前に約 2.7 MiB の base64 文字列になります。

NENE2 のデフォルト `requestMaxBodyBytes` は **1 MiB** です。これは約 750 KB 以上のファイルは、ルートハンドラーが呼ばれる前に `413 Request Entity Too Large` で拒否されることを意味します。

**`requestMaxBodyBytes` には常に意図したファイルサイズ制限の少なくとも 1.4 倍を設定してください:**

```php
new RuntimeApplicationFactory(
    $responseFactory,
    $streamFactory,
    routeRegistrars:     [...],
    requestMaxBodyBytes: 10 * 1024 * 1024,  // 約 7.5 MiB のファイルを許可
)
```

| 最大ファイルサイズ | 最小 requestMaxBodyBytes |
|---|---|
| 500 KB | 700 KB |
| 1 MiB | 1.5 MiB |
| 2 MiB | 3 MiB |
| 10 MiB | 14 MiB |

## MIME タイプ検出 — クライアントを信頼してはいけない

`finfo_buffer()` を使って実際にデコードされたバイト列から MIME タイプを常に検出してください。クライアントが提供する `Content-Type` ヘッダーやファイル拡張子には絶対に依存しないでください — 両方とも偽造できます。

```php
$bytes = base64_decode($base64Content, strict: true);
if ($bytes === false) {
    // 無効な base64
}

$finfo = new \finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->buffer($bytes);

$allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($mime, $allowed, true)) {
    // 拒否 — ファイル名が .jpg と言っていても
}
```

これにより `avatar.jpg` としてアップロードされた PHP スクリプトを正しく拒否します — `finfo` はファイル名ではなく実際のバイト列を読みます。

## ファイルサイズバリデーション

base64 文字列の長さではなく、**デコードされたバイト数**をバリデーションしてください:

```php
$size = strlen($bytes);  // base64_decode() 後のバイト数。strlen($base64String) ではない
if ($size > 2 * 1024 * 1024) {
    // 422: ファイルが大きすぎる
}
```

## ファイル名のサニタイゼーション — パストラバーサルチェックリスト

ユーザーが提供したファイル名を直接使ってファイルを書き込んではいけません。以下のすべてを適用してください:

```php
function sanitizeFilename(string $filename): string
{
    // 1. ディレクトリコンポーネントを取り除く（パストラバーサル）
    $name = basename($filename);

    // 2. ヌルバイトを削除する（"image.png\x00.php" 攻撃）
    $name = str_replace("\x00", '', $name);

    // 3. 先頭のドットを削除する（隠しファイル）
    $name = ltrim($name, '.');

    // 4. 英数字以外の文字を置換する
    $name = preg_replace('/[^\w\-.]/', '_', $name) ?? '_';

    // 5. 危険なスクリプト拡張子を無効化する
    $dangerousExts = ['php', 'phtml', 'phar', 'cgi', 'py', 'sh', 'exe'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, $dangerousExts, true)) {
        $name = pathinfo($name, PATHINFO_FILENAME) . '_' . $ext;
    }

    return $name !== '' ? $name : 'file';
}
```

**列挙を防ぐために、保存されるファイル名には常にランダムトークンをプレフィックスとして付けてください:**

```php
$stored = bin2hex(random_bytes(8)) . '_' . sanitizeFilename($original);
file_put_contents($storageDir . '/' . $stored, $bytes);
```

## PHP 例外プロパティ名の衝突

組み込み例外クラスを継承する際、`$code`、`$message`、`$file`、`$line` という名前の readonly プロパティを宣言しないでください — これらはベースクラスで non-readonly であり、PHP は致命的なエラーをスローします:

```php
// 致命的エラー: Cannot redeclare non-readonly property Exception::$code
final class UploadException extends \InvalidArgumentException {
    public function __construct(
        public readonly string $code,  // ← Exception::$code と衝突
    ) {}
}

// 別の名前を使用する:
final class UploadException extends \InvalidArgumentException {
    public function __construct(
        public readonly string $errorCode,  // ← 安全
    ) {}
}
```

## 完全な例

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

ルートハンドラーでの接続:

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
