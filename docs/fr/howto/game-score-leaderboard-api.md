# How-to : API de score de jeu et classement

> **Référence FT** : FT259 (`NENE2-FT/scorelog`) — Soumission de score de jeu avec insertion en masse (max 100, tout-ou-rien), classement par joueur avec agrégation `best_score` et `play_count`, prévention des scores négatifs, pagination, 20 tests PASS.

Ce guide montre comment construire un système de notation de jeu : enregistrer des scores individuels, importer des résultats en masse et calculer des classements par jeu.

## Schéma

```sql
CREATE TABLE scores (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    player     TEXT    NOT NULL,
    game       TEXT    NOT NULL,
    score      INTEGER NOT NULL CHECK(score >= 0),
    played_at  TEXT    NOT NULL,      -- date ISO 8601 : YYYY-MM-DD
    created_at TEXT    NOT NULL
);

CREATE INDEX idx_scores_game      ON scores (game);
CREATE INDEX idx_scores_player    ON scores (player);
CREATE INDEX idx_scores_played_at ON scores (played_at);
```

`CHECK(score >= 0)` empêche les scores négatifs au niveau DB. Les index sur `game` et `player` permettent des requêtes de liste filtrée et de classement.

## Endpoints

| Méthode    | Chemin                      | Description                               |
|------------|-----------------------------|-------------------------------------------|
| `POST`     | `/scores`                   | Soumettre un score unique                 |
| `POST`     | `/scores/bulk`              | Soumission en masse de jusqu'à 100 scores |
| `GET`      | `/scores`                   | Lister les scores (filtrer + paginer)     |
| `GET`      | `/scores/leaderboard`       | Classement par joueur pour un jeu         |
| `GET`      | `/scores/{id}`              | Obtenir un seul score                     |
| `DELETE`   | `/scores/{id}`              | Supprimer un score                        |

## Soumettre un score

```php
POST /scores
{
  "player":    "Alice",
  "game":      "tetris",
  "score":     1500,
  "played_at": "2026-01-15"
}

→ 201
{
  "id": 1,
  "player": "Alice",
  "game": "tetris",
  "score": 1500,
  "played_at": "2026-01-15",
  "created_at": "..."
}
```

Plusieurs scores par joueur par jeu sont autorisés — chaque partie est un enregistrement séparé.

### Validation

```php
POST /scores  {"game": "tetris", "score": 100, "played_at": "2026-01-15"}
→ 422  // player est requis

POST /scores  {"player": "Alice", "game": "tetris", "score": -1, "played_at": "2026-01-15"}
→ 422  // score must be >= 0

POST /scores  {"player": "Alice", "game": "tetris", "score": 100, "played_at": "15/01/2026"}
→ 422  // played_at must be YYYY-MM-DD format

POST /scores  {"player": "Alice", "game": "tetris", "score": 0, "played_at": "2026-01-15"}
→ 201  // score = 0 est valide
```

## Lister les scores

```php
// Tous les scores
GET /scores
→ 200  {"items": [...], "total": 10}

// Filtrer par jeu
GET /scores?game=tetris
→ 200  {"items": [/* scores tetris uniquement */], "total": 3}

// Filtrer par joueur
GET /scores?player=Alice
→ 200  {"items": [/* scores d'Alice uniquement */], "total": 2}

// Pagination
GET /scores?limit=2&offset=1
→ 200  {"items": [/* 2 éléments, à partir de l'index 1 */], "total": 5}
```

## Soumission en masse

```php
POST /scores/bulk
{
  "scores": [
    {"player": "Alice", "game": "tetris", "score": 1000, "played_at": "2026-01-15"},
    {"player": "Bob",   "game": "tetris", "score": 2000, "played_at": "2026-01-16"},
    {"player": "Carol", "game": "snake",  "score": 500,  "played_at": "2026-01-15"}
  ]
}

→ 201
{
  "created": 3,
  "scores": [
    {"id": 1, "player": "Alice", ...},
    {"id": 2, "player": "Bob",   ...},
    {"id": 3, "player": "Carol", ...}
  ]
}
```

### Règles de validation en masse

```php
// Tableau vide
POST /scores/bulk  {"scores": []}
→ 422  // au moins 1 entrée requise

// Toute entrée invalide fait échouer l'ensemble du lot
POST /scores/bulk
{"scores": [
  {"player": "Alice", "game": "tetris", "score": 1000, "played_at": "2026-01-15"},
  {"player": "",      "game": "tetris", "score": 500,  "played_at": "2026-01-15"}
]}
→ 422  // "player" ne peut pas être vide — aucun enregistrement n'est inséré

// Plus de 100 entrées
POST /scores/bulk  {"scores": [...101 entrées...]}
→ 422  // max 100 entrées par requête en masse
```

**Tout-ou-rien** : valider toutes les entrées avant d'en insérer une seule. Utiliser une transaction DB pour garantir l'atomicité.

### Implémentation en masse

```php
public function bulkSubmit(array $entries): array
{
    // Valider toutes les entrées d'abord
    foreach ($entries as $i => $entry) {
        $this->validate($entry, "scores[{$i}]");
    }

    // Insérer tout dans une transaction
    $this->db->beginTransaction();
    try {
        $ids = [];
        foreach ($entries as $entry) {
            $ids[] = $this->repo->insert($entry['player'], $entry['game'], $entry['score'], $entry['played_at'], $now);
        }
        $this->db->commit();
        return $this->repo->findByIds($ids);
    } catch (\Throwable $e) {
        $this->db->rollback();
        throw $e;
    }
}
```

## Classement

```php
GET /scores/leaderboard?game=tetris

→ 200
{
  "game":    "tetris",
  "top":     10,
  "entries": [
    {"rank": 1, "player": "Alice", "best_score": 3000, "play_count": 2},
    {"rank": 2, "player": "Bob",   "best_score": 2000, "play_count": 1},
    {"rank": 3, "player": "Carol", "best_score": 500,  "play_count": 1}
  ]
}
```

Chaque joueur apparaît **une fois** — avec son **meilleur** (plus haut) score sur toutes les parties, plus un `play_count`.

### Limite Top-N

```php
GET /scores/leaderboard?game=tetris&top=3
→ 200  {"entries": [...3 joueurs...], "top": 3}

GET /scores/leaderboard?game=tetris&top=0
→ 422  // top doit être >= 1

GET /scores/leaderboard          // jeu manquant
→ 422
```

### SQL du classement

```sql
SELECT
    player,
    MAX(score)   AS best_score,
    COUNT(*)     AS play_count,
    RANK() OVER (ORDER BY MAX(score) DESC) AS rank
FROM scores
WHERE game = ?
GROUP BY player
ORDER BY best_score DESC
LIMIT ?
```

`RANK() OVER (ORDER BY MAX(score) DESC)` attribue le même rang aux joueurs à égalité (avec des lacunes dans les rangs suivants). Pour des rangs sans lacune, utiliser `DENSE_RANK()`.

---

## À ne pas faire

| Anti-pattern | Risque |
|---|---|
| Autoriser les scores négatifs uniquement au niveau applicatif | La contrainte DB `CHECK(score >= 0)` est la garde finale ; la validation applicative peut être contournée |
| Insérer les entrées en masse une par une sans transaction | Un échec partiel laisse la moitié du lot en DB ; impossible de distinguer commis de non commis |
| Valider les entrées en masse dans la boucle d'insertion | Les premières N entrées sont insérées avant que la validation échoue ; données partielles en DB |
| Utiliser `score = MAX(score)` sans GROUP BY | Agrège toute la table sans groupement par joueur ; mauvais résultats de classement |
| Retourner tous les joueurs dans le classement sans LIMIT | Scan et tri complet de table sans plafond ; risque DoS pour les grandes tables de scores |
| Calculer `best_score` en récupérant tous les scores en PHP | O(N) par joueur ; utiliser l'agrégation SQL `MAX()` |
