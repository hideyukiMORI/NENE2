# Comment valider les entrées Unicode

NENE2 stocke et retourne les chaînes en UTF-8. Ce guide couvre les pièges de la validation Unicode et comment les gérer.

## Utiliser `mb_strlen` pour les limites de comptage de caractères

`strlen` compte les octets, pas les caractères. Le japonais, l'arabe et les emojis utilisent plusieurs octets par caractère.

```php
strlen('あ')              // 3 (octets)
mb_strlen('あ', 'UTF-8') // 1 (caractère)

strlen('🎉')              // 4 (octets)
mb_strlen('🎉', 'UTF-8') // 1 (caractère — un codepoint)
```

Toujours utiliser `mb_strlen($value, 'UTF-8')` pour appliquer une limite de caractères :

```php
private const int NAME_MAX_CHARS = 50;

if (mb_strlen($name, 'UTF-8') > self::NAME_MAX_CHARS) {
    $errors[] = ['field' => 'name', 'code' => 'too_long',
                 'message' => 'name must be at most ' . self::NAME_MAX_CHARS . ' characters.'];
}
```

**Pourquoi `strlen` est problématique :** Un nom japonais de 50 caractères fait 150 octets. `strlen(...) > 50` le rejetterait.

## Rejeter les octets null explicitement

Les colonnes SQLite TEXT acceptent les octets null (`\x00`). Les opérations de chaîne PHP les gèrent aussi — mais les octets null dans les entrées utilisateur sont presque toujours des tentatives d'injection ou des bugs d'encodage. Les rejeter tôt :

```php
if (str_contains($name, "\x00")) {
    $errors[] = ['field' => 'name', 'code' => 'invalid', 'message' => 'name must not contain null bytes.'];
}
```

Appliquer cette vérification à chaque champ de chaîne avant les autres validations (longueur, format, etc.).

## Graphèmes vs codepoints

`mb_strlen` compte les _codepoints_ Unicode. Un glyphe visible (graphème) peut être composé de plusieurs codepoints :

| Entrée | Codepoints | `mb_strlen` | Glyphes |
|--------|-----------|-------------|---------|
| `é` (précomposé) | 1 | 1 | 1 |
| `é` (e + accent combiné) | 2 | 2 | 1 |
| 👨‍👩‍👧 (famille ZWJ) | 5 | 5 | 1 |

Pour la plupart des cas d'usage (noms d'utilisateur, biographies), le comptage par codepoints est suffisant. Si vous avez besoin de compter les caractères visibles, utiliser `grapheme_strlen()` de l'extension `intl` :

```php
grapheme_strlen('👨‍👩‍👧') // 1
mb_strlen('👨‍👩‍👧', 'UTF-8') // 5
```

Choisir la méthode de comptage qui correspond aux attentes de l'utilisateur pour votre champ.

## Réponses JSON et caractères non-ASCII

`JsonResponseFactory` encode les réponses avec `JSON_UNESCAPED_UNICODE`, donc les caractères non-ASCII apparaissent comme UTF-8 littéral dans le corps de la réponse :

```json
{ "name": "田中太郎" }
```

Si vous construisez un appel `json_encode` personnalisé ailleurs (ex. stocker des tags en JSON dans une colonne TEXT), ajouter le même flag :

```php
$tagsJson = json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
```

Sans `JSON_UNESCAPED_UNICODE`, la valeur stockée serait `["タグ"]` au lieu de `["タグ"]`.

## Exemple de validation complet

```php
private const int NAME_MAX_CHARS = 50;

private function validateName(string $raw): ?string
{
    if ($raw === '') {
        return 'name is required.';
    }
    if (str_contains($raw, "\x00")) {
        return 'name must not contain null bytes.';
    }
    if (mb_strlen($raw, 'UTF-8') > self::NAME_MAX_CHARS) {
        return 'name must be at most ' . self::NAME_MAX_CHARS . ' characters.';
    }
    return null; // valide
}
```

## Tester les valeurs limites

Toujours écrire des tests pour :

- Exactement `MAX` caractères (devrait passer) — utiliser un caractère Unicode pour vérifier la différence octet/char :

  ```php
  $name50 = str_repeat('あ', 50); // 150 octets, 50 chars — devrait passer
  ```

- `MAX + 1` caractères (devrait échouer) :

  ```php
  $name51 = str_repeat('あ', 51); // devrait retourner 422 avec too_long
  ```

- Rejet d'octet null :

  ```php
  "Valid\x00Name" // devrait retourner 422 avec invalid
  ```
