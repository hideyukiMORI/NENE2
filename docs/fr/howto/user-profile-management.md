# Gestion du profil utilisateur

Stocker et mettre à jour les données de profil visibles par l'utilisateur : nom d'affichage, biographie et URL d'avatar. La création de profil est séparée de la création d'utilisateur — les utilisateurs existent d'abord, puis un profil est créé une fois et mis à jour sur place.

## Vue d'ensemble

Une API de gestion de profil implique :
- **Créer un utilisateur** — inscription d'utilisateur basée sur l'email (un profil par utilisateur)
- **Créer un profil** — configuration initiale du profil (résistant à l'idempotence : 409 si déjà existant)
- **Obtenir un profil** — récupérer les données de profil actuelles
- **Mettre à jour un profil** — remplacer les champs du profil (propriété appliquée)

## Schéma de base de données

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    email      TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL
);

CREATE TABLE profiles (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL UNIQUE,
    display_name TEXT    NOT NULL DEFAULT '',
    bio          TEXT    NOT NULL DEFAULT '',
    avatar_url   TEXT    NOT NULL DEFAULT '',
    updated_at   TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

`UNIQUE` sur `user_id` applique un profil par utilisateur au niveau DB.

## Gérer les emails dupliqués

Capturer `DatabaseConstraintException` pour retourner 409 au lieu de laisser fuiter un 500 :

```php
try {
    $userId = $this->repo->createUser($email, $now);
} catch (DatabaseConstraintException) {
    return $this->responseFactory->create(['error' => 'email already registered'], 409);
}
```

Sans ce catch, un email dupliqué cause une exception non gérée qui expose les détails d'erreur internes au client.

## Validation de l'URL d'avatar

N'autoriser que les URLs `https://` pour empêcher les schémas `javascript:`, `data:`, `file://`, et `http://` :

```php
private function isValidAvatarUrl(string $url): bool
{
    if (mb_strlen($url) > UserProfile::MAX_AVATAR_URL_LENGTH) {
        return false;
    }

    // Uniquement https — bloque javascript:, data:, file://, ftp://, http://
    if (!str_starts_with($url, 'https://')) {
        return false;
    }

    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}
```

La chaîne vide est autorisée (pas d'avatar défini). La limite `MAX_AVATAR_URL_LENGTH = 2048` prévient les abus de stockage.

## Limites de longueur de champ

Définir les limites comme constantes sur le value object pour une source de vérité unique :

```php
final readonly class UserProfile
{
    public const int MAX_BIO_LENGTH          = 500;
    public const int MAX_DISPLAY_NAME_LENGTH = 100;
    public const int MAX_AVATAR_URL_LENGTH   = 2048;
    ...
}
```

Utiliser `mb_strlen()` et non `strlen()` pour la correction multi-octets (UTF-8).

## Vérification de propriété

L'endpoint `PUT /users/{userId}/profile` utilise un en-tête `X-User-Id` pour identifier l'acteur demandant. En production, remplacer par un claim JWT :

```php
private function resolveActorId(ServerRequestInterface $request): int
{
    $header = $request->getHeaderLine('X-User-Id');
    return is_numeric($header) ? (int) $header : 0;
}

// Dans le handler :
if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

Un en-tête non-numérique ou manquant se résout à `0`, qui ne correspond jamais à un vrai ID d'utilisateur → 403.

## Prévention des profils dupliqués

Vérifier l'existence d'un profil avant d'insérer et retourner 409 :

```php
if ($this->repo->findByUserId($userId) !== null) {
    return $this->responseFactory->create(['error' => 'profile already exists'], 409);
}
```

Cela empêche un deuxième `POST /users/{userId}/profile` d'écraser silencieusement un profil existant.

## Propriétés de sécurité

| Propriété | Implémentation |
|-----------|----------------|
| Email dupliqué | `DatabaseConstraintException` capturée → 409 (pas de stack trace fuitée) |
| Schéma avatar_url | `str_starts_with('https://')` bloque tous les schémas non-https |
| Longueur avatar_url | `MAX_AVATAR_URL_LENGTH = 2048` |
| Longueur bio | `MAX_BIO_LENGTH = 500` avec `mb_strlen()` |
| Propriété | En-tête `X-User-Id` (remplacer par claim JWT en production) |
| Un profil par utilisateur | Contrainte DB `UNIQUE (user_id)` + vérification 409 dans le handler |

## Résumé des routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/users` | Enregistrer un utilisateur (email, 409 sur doublon) |
| `POST` | `/users/{userId}/profile` | Créer un profil (409 si déjà existant) |
| `GET` | `/users/{userId}/profile` | Obtenir un profil |
| `PUT` | `/users/{userId}/profile` | Mettre à jour un profil (nécessite l'en-tête `X-User-Id`) |
