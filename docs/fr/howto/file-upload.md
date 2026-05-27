# Téléversement de fichier (base64 JSON)

NENE2 est un framework JSON-first. Il n'a pas de parsing intégré de multipart/form-data. Le pattern recommandé pour le téléversement de fichiers dans une API JSON est de recevoir les fichiers sous forme de chaînes encodées en base64 dans le corps de la requête JSON.

## requestMaxBodyBytes — la première chose à configurer

L'encodage base64 augmente la taille du payload d'environ 33%. Un fichier de 2 Mio devient ~2,7 Mio de caractères base64 avant la surcharge de l'enveloppe JSON.

La valeur par défaut de `requestMaxBodyBytes` de NENE2 est **1 Mio**. Cela signifie que les fichiers plus grands que ~750 Ko seront rejetés avec `413 Request Entity Too Large` avant que votre gestionnaire de route soit appelé.

**Toujours définir `requestMaxBodyBytes` à au moins 1,4× votre limite de taille de fichier prévue :**

```php
new RuntimeApplicationFactory(
    $responseFactory,
    $streamFactory,
    routeRegistrars:     [...],
    requestMaxBodyBytes: 10 * 1024 * 1024,  // permet des fichiers ~7,5 Mio
)
```

| Taille max fichier | requestMaxBodyBytes minimum |
|---|---|
| 500 Ko | 700 Ko |
| 1 Mio | 1,5 Mio |
| 2 Mio | 3 Mio |
| 10 Mio | 14 Mio |

## Détection du type MIME — ne jamais faire confiance au client

Toujours détecter le type MIME depuis les octets décodés réels en utilisant `finfo_buffer()`. Ne jamais se fier à l'en-tête `Content-Type` fourni par le client ni à l'extension du fichier — les deux peuvent être falsifiés.

```php
$bytes = base64_decode($base64Content, strict: true);
if ($bytes === false) {
    // base64 invalide
}

$finfo = new \finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->buffer($bytes);

$allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($mime, $allowed, true)) {
    // rejeter — même si le nom de fichier dit .jpg
}
```

Cela rejette correctement un script PHP téléversé sous le nom `avatar.jpg` — `finfo` lit les octets réels, pas le nom de fichier.

## Validation de la taille du fichier

Valider le **nombre d'octets décodés**, pas la longueur de la chaîne base64 :

```php
$size = strlen($bytes);  // octets après base64_decode(), pas strlen($base64String)
if ($size > 2 * 1024 * 1024) {
    // 422 : fichier trop grand
}
```

## Assainissement du nom de fichier — liste de contrôle traversée de chemin

Ne jamais écrire un fichier en utilisant directement un nom de fichier fourni par l'utilisateur. Appliquer tous les points suivants :

```php
function sanitizeFilename(string $filename): string
{
    // 1. Supprimer les composants de répertoire (traversée de chemin)
    $name = basename($filename);

    // 2. Supprimer les octets nuls (attaque "image.png\x00.php")
    $name = str_replace("\x00", '', $name);

    // 3. Supprimer les points initiaux (fichiers cachés)
    $name = ltrim($name, '.');

    // 4. Remplacer les caractères non alphanumériques
    $name = preg_replace('/[^\w\-.]/', '_', $name) ?? '_';

    // 5. Neutraliser les extensions de script dangereuses
    $dangerousExts = ['php', 'phtml', 'phar', 'cgi', 'py', 'sh', 'exe'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, $dangerousExts, true)) {
        $name = pathinfo($name, PATHINFO_FILENAME) . '_' . $ext;
    }

    return $name !== '' ? $name : 'file';
}
```

**Toujours préfixer le nom de fichier stocké avec un token aléatoire** pour prévenir l'énumération :

```php
$stored = bin2hex(random_bytes(8)) . '_' . sanitizeFilename($original);
file_put_contents($storageDir . '/' . $stored, $bytes);
```

## Collision de nom de propriété d'exception PHP

Lors de l'extension d'une classe d'exception intégrée, ne pas déclarer de propriété readonly nommée `$code`, `$message`, `$file`, ou `$line` — ces propriétés sont non-readonly sur la classe de base et PHP lèvera une erreur fatale :

```php
// Erreur fatale : Cannot redeclare non-readonly property Exception::$code
final class UploadException extends \InvalidArgumentException {
    public function __construct(
        public readonly string $code,  // ← conflit avec Exception::$code
    ) {}
}

// Utiliser un nom différent :
final class UploadException extends \InvalidArgumentException {
    public function __construct(
        public readonly string $errorCode,  // ← sûr
    ) {}
}
```

## Exemple complet

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

Brancher dans le gestionnaire de route :

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
