# How-to : API vote positif / vote négatif

> **Référence FT** : FT347 (`NENE2-FT/votelog`) — Vote positif/négatif par utilisateur avec désactivation par bascule (même direction deux fois supprime le vote), changement de direction (positif→négatif atomiquement), agrégation de score (votes positifs − votes négatifs), contrainte `UNIQUE(user_id, item_id)`, 15 tests PASS.

Ce guide montre comment implémenter un système de vote de style Reddit/Stack Overflow : chaque utilisateur peut voter une fois par élément, désactiver son vote en revotant dans la même direction, ou changer de direction.

## Schéma

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE votes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    item_id    INTEGER NOT NULL,
    direction  TEXT    NOT NULL CHECK (direction IN ('up', 'down')),
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, item_id),
    FOREIGN KEY (user_id)  REFERENCES users(id),
    FOREIGN KEY (item_id)  REFERENCES items(id)
);
```

`UNIQUE(user_id, item_id)` impose un vote par utilisateur par élément. `CHECK(direction IN ('up', 'down'))` rejette toute autre valeur au niveau DB.

## Endpoints

| Méthode | Chemin                        | Description                      |
|---------|-------------------------------|----------------------------------|
| `POST`  | `/items/{id}/vote`            | Voter, basculer, ou changer de vote |
| `GET`   | `/items/{id}/score`           | Obtenir le score de l'élément    |
| `GET`   | `/items/{id}/vote/{userId}`   | Obtenir le vote actuel de l'utilisateur |

## Voter

```php
POST /items/1/vote
{"user_id": 42, "direction": "up"}

→ 200
{
  "vote": "up",
  "score": {
    "upvotes": 1,
    "downvotes": 0,
    "score": 1
  }
}
```

```php
POST /items/1/vote
{"user_id": 43, "direction": "down"}
→ 200  {"vote": "down", "score": {"upvotes": 1, "downvotes": 1, "score": 0}}
```

## Désactivation par bascule (même direction deux fois)

Voter dans la **même direction** une deuxième fois supprime le vote :

```php
// Premier vote
POST /items/1/vote  {"user_id": 42, "direction": "up"}
→ 200  {"vote": "up", "score": {"score": 1}}

// Deuxième vote dans la même direction → désactivation par bascule
POST /items/1/vote  {"user_id": 42, "direction": "up"}
→ 200  {"vote": null, "score": {"score": 0}}
```

`vote: null` signifie que l'utilisateur n'a pas de vote actif sur cet élément.

## Changement de direction

Voter dans la **direction opposée** inverse le vote existant de façon atomique :

```php
// Commencer avec un vote positif
POST /items/1/vote  {"user_id": 42, "direction": "up"}
→ 200  {"vote": "up", "score": {"upvotes": 1, "downvotes": 0, "score": 1}}

// Changer en vote négatif
POST /items/1/vote  {"user_id": 42, "direction": "down"}
→ 200  {"vote": "down", "score": {"upvotes": 0, "downvotes": 1, "score": -1}}
```

## Obtenir le score

```php
GET /items/1/score

→ 200
{
  "upvotes": 2,
  "downvotes": 1,
  "score": 1         // votes positifs − votes négatifs
}
```

Score pour un élément sans votes :

```php
GET /items/1/score   // aucun vote encore
→ 200  {"upvotes": 0, "downvotes": 0, "score": 0}
```

## Obtenir l'état du vote d'un utilisateur

```php
// Aucun vote encore
GET /items/1/vote/42
→ 200  {"vote": null}

// Après un vote positif
GET /items/1/vote/42
→ 200  {"vote": "up"}

// Après désactivation par bascule
GET /items/1/vote/42
→ 200  {"vote": null}
```

## Implémentation

### Logique du handler de vote

```php
public function vote(int $itemId, int $userId, string $direction): array
{
    $item = $this->repo->findItem($itemId);
    if ($item === null) {
        throw new ItemNotFoundException($itemId);
    }

    $existing = $this->repo->findVote($userId, $itemId);

    if ($existing !== null && $existing['direction'] === $direction) {
        // Même direction → désactivation par bascule
        $this->repo->deleteVote($userId, $itemId);
        $activeDirection = null;
    } elseif ($existing !== null) {
        // Direction différente → mise à jour en place
        $this->repo->updateVote($userId, $itemId, $direction, $now);
        $activeDirection = $direction;
    } else {
        // Nouveau vote
        $this->repo->insertVote($userId, $itemId, $direction, $now);
        $activeDirection = $direction;
    }

    $score = $this->repo->getScore($itemId);

    return [
        'vote'  => $activeDirection,
        'score' => $score,
    ];
}
```

### SQL d'agrégation du score

```sql
SELECT
    COALESCE(SUM(CASE WHEN direction = 'up'   THEN 1 ELSE 0 END), 0) AS upvotes,
    COALESCE(SUM(CASE WHEN direction = 'down' THEN 1 ELSE 0 END), 0) AS downvotes,
    COALESCE(SUM(CASE WHEN direction = 'up'   THEN 1 ELSE -1 END), 0) AS score
FROM votes
WHERE item_id = ?
```

`COALESCE(..., 0)` garantit des valeurs zéro quand aucun vote n'existe (SUM d'un ensemble vide retourne NULL).

### Pattern UPSERT de vote

```sql
-- Insérer un nouveau vote
INSERT INTO votes (user_id, item_id, direction, created_at) VALUES (?, ?, ?, ?)

-- Mettre à jour la direction (la contrainte UNIQUE empêche les doublons)
UPDATE votes SET direction = ? WHERE user_id = ? AND item_id = ?

-- Supprimer (désactivation par bascule)
DELETE FROM votes WHERE user_id = ? AND item_id = ?
```

## Validation

```php
// Direction invalide
POST /items/1/vote  {"user_id": 42, "direction": "sideways"}
→ 422  // la direction doit être 'up' ou 'down'

// L'élément n'existe pas
POST /items/9999/vote  {"user_id": 42, "direction": "up"}
→ 404
```

## Plusieurs utilisateurs

```php
// Trois utilisateurs votent sur le même élément
POST /items/1/vote  {"user_id": 1, "direction": "up"}
POST /items/1/vote  {"user_id": 2, "direction": "up"}
POST /items/1/vote  {"user_id": 3, "direction": "down"}

GET /items/1/score
→ 200  {"upvotes": 2, "downvotes": 1, "score": 1}
```

---

## Ce qu'il ne faut PAS faire

| Anti-pattern | Risque |
|---|---|
| Pas de `UNIQUE(user_id, item_id)` | Les utilisateurs peuvent voter plusieurs fois, gonflant les scores |
| `INSERT OR REPLACE` pour le changement de direction | Génère un nouvel `id` et `created_at` ; perd l'historique des votes ; casse les pistes d'audit |
| Retourner 409 sur la désactivation par bascule | La désactivation est un comportement attendu, pas une erreur ; retourner le nouvel état de vote (null) |
| Calculer le score dans l'application en récupérant tous les votes | O(N) par requête ; utiliser l'agrégation SQL avec une seule requête |
| Autoriser `direction: null` pour supprimer un vote via le corps | Ambigu ; utiliser le pattern de bascule (même direction deux fois) ou un endpoint DELETE séparé |
| Omettre `COALESCE` dans l'agrégation du score | `SUM()` retourne `NULL` quand aucune ligne ne correspond ; `null − null` plante ou retourne un type incorrect |
