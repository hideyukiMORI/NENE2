# How-to : API de métadonnées de téléversement de fichiers (VULN-A~L)

Ce guide démontre la gestion sécurisée des métadonnées de téléversement de fichiers couvrant VULN-A à VULN-L.

## Vue d'ensemble du pattern

Les fichiers ne sont pas stockés par cette API — seules leurs métadonnées (nom de fichier, type MIME, taille) sont enregistrées. Le transfert réel du fichier est géré séparément (ex. direct-to-S3). C'est un pattern courant pour suivre l'historique des téléversements et appliquer des contraintes.

## Schéma

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

## VULN-A : Injection SQL

Toutes les requêtes utilisent des instructions préparées PDO. Les noms de fichier et types MIME soumis par les utilisateurs ne sont jamais interpolés dans des chaînes SQL.

## VULN-B : Affectation de masse + Liste blanche MIME

Seule une liste blanche explicite de types MIME est acceptée :

```php
private const array ALLOWED_MIMES = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf', 'text/plain', 'text/csv',
];
```

Les types MIME inconnus (ex. `application/x-msdownload`, `application/x-sh`) sont rejetés avec 422.

## VULN-C : IDOR

Les utilisateurs non-admin ne peuvent accéder qu'à leurs propres téléversements. Les téléversements d'autres utilisateurs retournent 404 (pas 403) :

```php
if (!$isAdmin && (int) $upload['user_id'] !== $uid) {
    return $this->problem(404, 'not-found', 'Upload not found.');
}
```

## VULN-D : Admin fermé par défaut

```php
private function isAdmin(ServerRequestInterface $req): bool
{
    if ($this->adminKey === '') {
        return false;
    }
    return hash_equals($this->adminKey, $req->getHeaderLine('X-Admin-Key'));
}
```

## VULN-F : Traversée de chemin

Les séparateurs de répertoire et `..` sont rejetés dans les noms de fichier :

```php
if (str_contains($filename, '/') || str_contains($filename, '\\') || str_contains($filename, '..')) {
    return $this->problem(422, 'validation-failed', 'filename must not contain path separators.');
}
```

Cela empêche des noms de fichier comme `../etc/passwd`, `C:\Windows\cmd.exe`, ou `subdir/evil.php`.

## VULN-G : ReDoS

Les IDs dans les paramètres de chemin sont validés avec `ctype_digit()`, jamais des regex.

## VULN-I : Valeurs négatives / zéro

```php
if (!is_int($sizeBytes) || $sizeBytes < 1 || $sizeBytes > self::MAX_SIZE) {
    return $this->problem(422, ...);
}
```

Les tailles zéro et négatives sont rejetées.

## VULN-J : Confusion de type

- `mime_type` doit être `is_string()` — l'entier `123` est rejeté.
- `size_bytes` doit être `is_int()` — la chaîne `"1024"` et le flottant `100.5` sont rejetés.
- `is_public` doit être `is_bool()` — la chaîne `"true"` et l'entier `1` sont rejetés.

## Résumé de validation

| Champ | Règle |
|---|---|
| `X-User-Id` | Requis pour POST/DELETE ; `ctype_digit`, > 0 |
| `filename` | Non vide, max 255 chars, pas de `/`, `\`, `..` |
| `mime_type` | Chaîne ; doit être dans la liste blanche |
| `size_bytes` | Entier 1–104 857 600 (100 MiB) |
| `is_public` | Booléen uniquement |

## Routes

```
POST   /uploads              Enregistrer les métadonnées de téléversement (X-User-Id requis)
GET    /uploads/{id}         Obtenir les métadonnées (propriétaire ou admin)
DELETE /uploads/{id}         Supprimer l'enregistrement (propriétaire ou admin)
GET    /users/{userId}/uploads  Lister les téléversements d'un utilisateur (propriétaire ou admin)
```

## Voir aussi

- Source FT210 : `../NENE2-FT/uploadlog/`
- Connexe : `docs/howto/wish-list-api.md` (FT207, aussi VULN)
