# How-to : API de profil utilisateur

> **Référence FT** : FT275 (`NENE2-FT/profilelog`) — Profil utilisateur : un seul profil par utilisateur (UNIQUE user_id), email validé avec FILTER_VALIDATE_EMAIL, limites de longueur de champ (display_name 100 / bio 500 / avatar_url 2048), URL d'avatar https uniquement, DatabaseConstraintException → 409, garde de propriété via X-User-Id, 32 tests PASS.

Démontre un système utilisateur 1:1 : créer un utilisateur (email unique), créer/obtenir/mettre à jour son profil. Les champs de profil ont des limites de longueur appliquées et une contrainte de sécurité sur l'URL.

---

## Schéma

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

`user_id UNIQUE` applique l'invariant un-profil-par-utilisateur au niveau DB.

---

## Routes

| Méthode | Chemin | Description |
|---------|--------|-------------|
| `POST` | `/users` | Créer un utilisateur (email requis, unique) |
| `POST` | `/users/{userId}/profile` | Créer un profil pour un utilisateur |
| `GET`  | `/users/{userId}/profile` | Obtenir le profil |
| `PUT`  | `/users/{userId}/profile` | Mettre à jour le profil (propriétaire uniquement) |

---

## Validation de l'email

```php
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    return $this->responseFactory->create(['error' => 'valid email is required'], 422);
}
```

Sur email dupliqué, `DatabaseConstraintException` est capturée et mappée à 409 :

```php
try {
    $userId = $this->repo->createUser($email, $now);
} catch (DatabaseConstraintException) {
    return $this->responseFactory->create(['error' => 'email already registered'], 409);
}
```

---

## Limites de champs (value object UserProfile)

```php
final readonly class UserProfile
{
    public const int MAX_BIO_LENGTH          = 500;
    public const int MAX_DISPLAY_NAME_LENGTH = 100;
    public const int MAX_AVATAR_URL_LENGTH   = 2048;
}
```

La longueur est vérifiée avec `mb_strlen()` (sûr pour les multi-octets) :

```php
if (mb_strlen($displayName) > UserProfile::MAX_DISPLAY_NAME_LENGTH) {
    return [$displayName, $bio, $avatarUrl, 'display_name must not exceed 100 characters'];
}
```

---

## URL d'avatar https uniquement

```php
private function isValidAvatarUrl(string $url): bool
{
    if (mb_strlen($url) > UserProfile::MAX_AVATAR_URL_LENGTH) {
        return false;
    }
    // Autoriser uniquement https pour empêcher les schémas javascript: et data: URI
    if (!str_starts_with($url, 'https://')) {
        return false;
    }
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}
```

`str_starts_with('https://')` bloque `javascript:`, `data:`, et `http://` avant que `filter_var` s'exécute.

---

## Garde de propriété

Les mises à jour de profil exigent que `X-User-Id` corresponde au propriétaire du profil :

```php
$actorId = $this->resolveActorId($request); // depuis l'en-tête X-User-Id

if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Pas de validation du format d'email | Emails invalides stockés ; les envois en aval échouent silencieusement |
| Pas de UNIQUE sur `user_id` dans les profils | Profils en double possibles ; GET retourne une ligne imprévisible |
| Utiliser `strlen()` pour la limite display_name | Caractères multi-octets (emoji, CJK) comptés incorrectement |
| Autoriser les URL d'avatar `http://` | Contenu mixte passif et surface potentielle de clickjacking |
| Autoriser les URI `javascript:` ou `data:` | XSS si l'URL d'avatar est rendue comme `<a href>` ou `<img src>` |
| Ne pas capturer `DatabaseConstraintException` | La violation UNIQUE devient un 500 au lieu d'un 409 |
| Permettre à n'importe quel utilisateur de mettre à jour n'importe quel profil | IDOR — toujours vérifier acteur = propriétaire avant l'écriture |
