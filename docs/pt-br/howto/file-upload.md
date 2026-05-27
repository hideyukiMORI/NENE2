# Upload de arquivo (base64 JSON)

NENE2 é um framework que prioriza JSON. Ele não possui parsing nativo de multipart/form-data. O padrão recomendado para upload de arquivo em uma API JSON é receber arquivos como strings codificadas em base64 no corpo da requisição JSON.

## requestMaxBodyBytes — a primeira configuração a definir

A codificação base64 aumenta o tamanho do payload em aproximadamente 33%. Um arquivo de 2 MiB se torna ~2,7 MiB de caracteres base64 antes do overhead do envelope JSON.

O `requestMaxBodyBytes` padrão do NENE2 é **1 MiB**. Isso significa que arquivos maiores que ~750 KB serão rejeitados com `413 Request Entity Too Large` antes que o handler da rota seja chamado.

**Sempre defina `requestMaxBodyBytes` para pelo menos 1,4× o limite de tamanho de arquivo desejado:**

```php
new RuntimeApplicationFactory(
    $responseFactory,
    $streamFactory,
    routeRegistrars:     [...],
    requestMaxBodyBytes: 10 * 1024 * 1024,  // permite arquivos de ~7,5 MiB
)
```

| Tamanho máximo do arquivo | requestMaxBodyBytes mínimo |
|---|---|
| 500 KB | 700 KB |
| 1 MiB | 1,5 MiB |
| 2 MiB | 3 MiB |
| 10 MiB | 14 MiB |

## Detecção de tipo MIME — nunca confie no cliente

Sempre detecte o tipo MIME a partir dos bytes decodificados reais usando `finfo_buffer()`. Nunca confie no header `Content-Type` fornecido pelo cliente ou na extensão do arquivo — ambos podem ser falsificados.

```php
$bytes = base64_decode($base64Content, strict: true);
if ($bytes === false) {
    // base64 inválido
}

$finfo = new \finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->buffer($bytes);

$allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($mime, $allowed, true)) {
    // rejeitar — mesmo que o nome do arquivo diga .jpg
}
```

Isso rejeita corretamente um script PHP enviado como `avatar.jpg` — `finfo` lê os bytes reais, não o nome do arquivo.

## Validação de tamanho do arquivo

Valide a **contagem de bytes decodificados**, não o comprimento da string base64:

```php
$size = strlen($bytes);  // bytes após base64_decode(), não strlen($base64String)
if ($size > 2 * 1024 * 1024) {
    // 422: arquivo muito grande
}
```

## Sanitização de nome de arquivo — checklist de path traversal

Nunca escreva um arquivo usando um nome de arquivo fornecido pelo usuário diretamente. Aplique todos os seguintes passos:

```php
function sanitizeFilename(string $filename): string
{
    // 1. Remover componentes de diretório (path traversal)
    $name = basename($filename);

    // 2. Remover bytes nulos (ataque "image.png\x00.php")
    $name = str_replace("\x00", '', $name);

    // 3. Remover pontos iniciais (arquivos ocultos)
    $name = ltrim($name, '.');

    // 4. Substituir caracteres não alfanuméricos
    $name = preg_replace('/[^\w\-.]/', '_', $name) ?? '_';

    // 5. Neutralizar extensões de script perigosas
    $dangerousExts = ['php', 'phtml', 'phar', 'cgi', 'py', 'sh', 'exe'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, $dangerousExts, true)) {
        $name = pathinfo($name, PATHINFO_FILENAME) . '_' . $ext;
    }

    return $name !== '' ? $name : 'file';
}
```

**Sempre prefixe o nome do arquivo armazenado com um token aleatório** para prevenir enumeração:

```php
$stored = bin2hex(random_bytes(8)) . '_' . sanitizeFilename($original);
file_put_contents($storageDir . '/' . $stored, $bytes);
```

## Colisão de nome de propriedade em exceção PHP

Ao estender uma classe de exceção nativa, não declare uma propriedade readonly chamada `$code`, `$message`, `$file` ou `$line` — estas são não-readonly na classe base e o PHP lançará um erro fatal:

```php
// Erro fatal: Cannot redeclare non-readonly property Exception::$code
final class UploadException extends \InvalidArgumentException {
    public function __construct(
        public readonly string $code,  // ← conflito com Exception::$code
    ) {}
}

// Use um nome diferente:
final class UploadException extends \InvalidArgumentException {
    public function __construct(
        public readonly string $errorCode,  // ← seguro
    ) {}
}
```

## Exemplo completo

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

Conectar no handler da rota:

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
