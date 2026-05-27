# How-to : API Suivre / Ne plus suivre

> **Référence FT** : FT314 (`NENE2-FT/followlog`) — Graphe social de suivi : suivi idempotent (POST 201 la première fois, 200 à la répétition), prévention de l'auto-suivi (422), ne plus suivre (DELETE 204), compteurs de followers/following via stats, listes paginées ordonnées par plus récent d'abord, vérification de suivi, support du suivi mutuel, 20 tests / 72 assertions PASS.

Ce guide montre comment construire un système de suivi social où les utilisateurs peuvent se suivre et se désabonner, avec des compteurs et des endpoints de liste de followers/following.

## Schéma

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE follows (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    follower_id INTEGER NOT NULL REFERENCES users(id),
    followee_id INTEGER NOT NULL REFERENCES users(id),
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (follower_id, followee_id)
);
```

La contrainte `UNIQUE (follower_id, followee_id)` applique l'idempotence des relations de suivi au niveau DB.

## Endpoints

| Méthode    | Chemin                                    | Description                              |
|------------|-------------------------------------------|------------------------------------------|
| `POST`     | `/users`                                  | Créer un utilisateur                     |
| `POST`     | `/users/{id}/follow`                      | Suivre un autre utilisateur              |
| `DELETE`   | `/users/{id}/follow/{followeeId}`         | Ne plus suivre                           |
| `GET`      | `/users/{id}/stats`                       | Obtenir les compteurs followers/following |
| `GET`      | `/users/{id}/followers`                   | Lister les followers (plus récents d'abord) |
| `GET`      | `/users/{id}/following`                   | Lister les suivis (plus récents d'abord) |
| `GET`      | `/users/{id}/is-following/{targetId}`     | Vérifier si on suit                      |

## Suivi idempotent

```php
// Premier suivi → 201 Created
POST /users/1/follow  {"followee_id": 2}
→ 201  {"following": true, "follower_id": 1, "followee_id": 2}

// Suivi répété avec la même paire → 200 OK (pas 201, pas 409)
POST /users/1/follow  {"followee_id": 2}
→ 200  {"following": true, "follower_id": 1, "followee_id": 2}
```

```php
// Logique du gestionnaire
try {
    $this->repo->follow($followerId, $followeeId);
    return $json->ok($response, ['following' => true, ...], 201);
} catch (DuplicateFollowException $e) {
    return $json->ok($response, ['following' => true, ...], 200); // déjà en train de suivre
}
```

## Prévention de l'auto-suivi

```php
POST /users/1/follow  {"followee_id": 1}
→ 422 Unprocessable Entity
```

```php
if ($followerId === $followeeId) {
    throw new ValidationException([
        ['field' => 'followee_id', 'message' => 'Cannot follow yourself.', 'code' => 'self-follow'],
    ]);
}
```

## Ne plus suivre

```php
DELETE /users/1/follow/2
→ 204 No Content   // désabonnement réussi

DELETE /users/1/follow/2  // quand on ne suit pas
→ 404 Not Found
```

Le cycle désabonnement-puis-réabonnement fonctionne correctement : DELETE → POST retourne à nouveau 201.

## Statistiques

```php
GET /users/1/stats
→ 200
{
    "user_id": 1,
    "followers_count": 2,
    "following_count": 3
}
```

`followers_count` = combien d'utilisateurs suivent cet utilisateur.  
`following_count` = combien d'utilisateurs cet utilisateur suit.

Utilisateur inconnu → 404.

## Listes de followers / following

```php
GET /users/1/followers
→ 200
{
    "items": [
        {"id": 3, "name": "Carol", "created_at": "..."},
        {"id": 2, "name": "Bob",   "created_at": "..."}
    ],
    "count": 2
}
```

- Ordonné par `follows.id DESC` (follower le plus récent d'abord).
- Même structure pour `GET /users/{id}/following`.
- Utilisateur inconnu → 404.

## Vérification de suivi

```php
GET /users/1/is-following/2
→ 200  {"following": true}   // 1 suit 2

GET /users/1/is-following/2  // après désabonnement
→ 200  {"following": false}
```

Retourne `false` (pas 404) quand on ne suit pas — la vérification elle-même est toujours valide.

## Suivi mutuel

```php
POST /users/1/follow  {"followee_id": 2}
POST /users/2/follow  {"followee_id": 1}

GET /users/1/is-following/2  → {"following": true}
GET /users/2/is-following/1  → {"following": true}
```

Les suivis mutuels sont juste deux lignes de suivi séparées — pas de table ni de logique spéciale nécessaire.

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Retourner 409 pour un suivi en double | La logique de réessai client se casse ; les opérations idempotentes doivent retourner 200, pas une erreur |
| Autoriser l'auto-suivi | Corrompt les stats (`followers_count` gonflé par l'auto-suivi) ; les fils semblent incorrects |
| Pas de contrainte UNIQUE sur (follower_id, followee_id) | La condition de course sur des clics de suivi concurrents crée des lignes dupliquées |
| DELETE d'un suivi inexistant retourne 204 | Le client ne peut pas distinguer "désabonné" de "jamais suivi" ; utiliser 404 |
| Ordonner par nom ou ID au lieu de la récence | Les followers/followings les plus récents se perdent dans une longue liste ; l'attente UX est "qui m'a suivi récemment" |
| Compteurs de suivi partagés entre utilisateurs | Les compteurs de followers se répercutent entre utilisateurs non liés ; toujours limiter par user_id |
