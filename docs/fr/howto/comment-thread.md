# How-to : API de fil de commentaires

Ce guide montre comment créer des fils de commentaires scopés à une ressource avec pagination, suppression par l'auteur uniquement et accès administrateur.

## Vue d'ensemble du pattern

- Les commentaires appartiennent à une ressource (identifiée par un ID entier).
- Tout utilisateur authentifié peut poster un commentaire sur n'importe quelle ressource.
- Les commentaires sont lisibles publiquement (aucune auth requise pour lister).
- Les auteurs peuvent supprimer leurs propres commentaires ; les admins peuvent supprimer n'importe quel commentaire.
- Pagination via les paramètres de requête `limit` et `offset`.

## Schéma

```sql
CREATE TABLE IF NOT EXISTS comments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    body        TEXT    NOT NULL,
    created_at  TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_comments_resource ON comments (resource_id, id ASC);
```

## Pattern de pagination

```php
$stmt = $this->pdo->prepare(
    'SELECT * FROM comments WHERE resource_id = :rid ORDER BY id ASC LIMIT :lim OFFSET :off'
);
$stmt->bindValue(':rid', $resourceId, PDO::PARAM_INT);
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
```

La réponse inclut `total` (nombre de tous les commentaires pour la ressource), `limit` et `offset` afin que les clients puissent construire des contrôles de pagination :

```json
{
  "comments": [...],
  "total": 42,
  "limit": 20,
  "offset": 0
}
```

## Limitation de la plage

Les valeurs de limit/offset invalides ou hors plage sont silencieusement ramenées aux valeurs par défaut sûres :

```php
private function clampInt(string $raw, int $default, int $min, int $max): int
{
    if (!ctype_digit($raw) && $raw !== '') {
        return $default;
    }
    $val = $raw !== '' ? (int) $raw : $default;
    return min(max($val, $min), $max);
}
```

`ctype_digit()` est utilisé pour éviter les ReDoS sur la chaîne de requête.

## IDOR : Suppression par l'auteur uniquement

Les utilisateurs non-admins peuvent uniquement supprimer leurs propres commentaires. Tenter de supprimer le commentaire d'un autre utilisateur retourne 404 (pas 403) :

```php
if (!$isAdmin && (int) $comment['user_id'] !== $userId) {
    return false;  // → 404
}
```

## Isolation de la ressource

Toutes les requêtes incluent `WHERE resource_id = :rid`, s'assurant que les commentaires de la ressource 1 ne sont jamais mélangés avec ceux de la ressource 2.

## Règles de validation

| Champ | Règle |
|---|---|
| `X-User-Id` | Requis pour POST/DELETE ; `ctype_digit`, >0 |
| `body` | Non vide, max 2000 caractères |
| Chemin `{resourceId}` | `ctype_digit`, max 18 caractères, >0 ; sinon 404 |
| `limit` (query) | Entier 1–100 ; défaut 20 |
| `offset` (query) | Entier non négatif ; défaut 0 |

## Routes

```
POST   /resources/{resourceId}/comments  Poster un commentaire (X-User-Id requis)
GET    /resources/{resourceId}/comments  Lister les commentaires (paginés, publics)
DELETE /comments/{id}                   Supprimer un commentaire (auteur ou admin)
```

## Voir aussi

- Source FT211 : `../NENE2-FT/commentlog/`
- Connexe : `docs/howto/note-taking.md` (FT202, CRUD de notes)
- Connexe : `docs/howto/leaderboard-ranking.md` (FT206, données scopées à une ressource)
