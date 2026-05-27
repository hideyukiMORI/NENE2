# Datei-Upload (Base64-JSON)

NENE2 ist ein JSON-first-Framework. Es verfügt über keine eingebaute `multipart/form-data`-Verarbeitung. Das empfohlene Muster für Datei-Uploads in einer JSON-API ist der Empfang von Dateien als base64-kodierte Strings im JSON-Request-Body.

## requestMaxBodyBytes — das Erste, was eingestellt werden muss

Base64-Encoding erhöht die Payload-Größe um etwa 33%. Eine 2-MiB-Datei wird zu ~2,7 MiB Base64-Zeichen vor dem JSON-Envelope-Overhead.

NENEs Standardwert für `requestMaxBodyBytes` ist **1 MiB**. Das bedeutet, Dateien größer als ~750 KB werden mit `413 Request Entity Too Large` abgelehnt, bevor der Route-Handler aufgerufen wird.

**`requestMaxBodyBytes` immer auf mindestens 1,4× der gewünschten Dateigrößenbegrenzung setzen:**

```php
new RuntimeApplicationFactory(
    $responseFactory,
    $streamFactory,
    routeRegistrars:     [...],
    requestMaxBodyBytes: 10 * 1024 * 1024,  // erlaubt ~7,5-MiB-Dateien
)
```

| Max-Dateigröße | Mindest-requestMaxBodyBytes |
|---|---|
| 500 KB | 700 KB |
| 1 MiB | 1,5 MiB |
| 2 MiB | 3 MiB |
| 10 MiB | 14 MiB |

## MIME-Typ-Erkennung — dem Client niemals vertrauen

Den MIME-Typ immer aus den tatsächlich dekodierten Bytes mit `finfo_buffer()` erkennen. Niemals dem vom Client gelieferten `Content-Type`-Header oder der Dateiendung vertrauen — beides kann gefälscht werden.

```php
$bytes = base64_decode($base64Content, strict: true);
if ($bytes === false) {
    // ungültiges Base64
}

$finfo = new \finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->buffer($bytes);

$allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($mime, $allowed, true)) {
    // ablehnen — auch wenn der Dateiname .jpg lautet
}
```

Dies lehnt korrekt ein PHP-Skript ab, das als `avatar.jpg` hochgeladen wird — `finfo` liest die tatsächlichen Bytes, nicht den Dateinamen.

## Dateigrößen-Validierung

Die **dekodierten Byte-Anzahl** validieren, nicht die Länge des Base64-Strings:

```php
$size = strlen($bytes);  // Bytes nach base64_decode(), nicht strlen($base64String)
if ($size > 2 * 1024 * 1024) {
    // 422: Datei zu groß
}
```

## Dateinamen-Bereinigung — Path-Traversal-Checkliste

Niemals eine Datei mit einem vom Benutzer gelieferten Dateinamen direkt schreiben. Alle folgenden Schritte anwenden:

```php
function sanitizeFilename(string $filename): string
{
    // 1. Verzeichniskomponenten entfernen (Path Traversal)
    $name = basename($filename);

    // 2. Null-Bytes entfernen ("image.png\x00.php"-Angriff)
    $name = str_replace("\x00", '', $name);

    // 3. führende Punkte entfernen (versteckte Dateien)
    $name = ltrim($name, '.');

    // 4. nicht-alphanumerische Zeichen ersetzen
    $name = preg_replace('/[^\w\-.]/', '_', $name) ?? '_';

    // 5. gefährliche Script-Erweiterungen neutralisieren
    $dangerousExts = ['php', 'phtml', 'phar', 'cgi', 'py', 'sh', 'exe'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, $dangerousExts, true)) {
        $name = pathinfo($name, PATHINFO_FILENAME) . '_' . $ext;
    }

    return $name !== '' ? $name : 'file';
}
```

**Den gespeicherten Dateinamen immer mit einem Zufalls-Token präfixen**, um Enumeration zu verhindern:

```php
$stored = bin2hex(random_bytes(8)) . '_' . sanitizeFilename($original);
file_put_contents($storageDir . '/' . $stored, $bytes);
```

## PHP-Exception-Property-Namenskonflikt

Beim Erweitern einer eingebauten Exception-Klasse keine readonly-Property mit dem Namen `$code`, `$message`, `$file` oder `$line` deklarieren — diese sind non-readonly in der Basisklasse und PHP wirft einen fatalen Fehler:

```php
// Fataler Fehler: Cannot redeclare non-readonly property Exception::$code
final class UploadException extends \InvalidArgumentException {
    public function __construct(
        public readonly string $code,  // ← Konflikt mit Exception::$code
    ) {}
}

// Anderen Namen verwenden:
final class UploadException extends \InvalidArgumentException {
    public function __construct(
        public readonly string $errorCode,  // ← sicher
    ) {}
}
```

## Vollständiges Beispiel

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

Im Route-Handler verdrahten:

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
