# How-to : API de suivi de scores et leaderboard

Ce guide montre comment construire un système de leaderboard avec soumission de scores, classements top-N utilisant l'agrégation meilleur-par-utilisateur, et historique de scores personnel avec NENE2.
Pattern démontré par le field trial **leaderboardlog** (FT206).

## Fonctionnalités

- Soumission de score par utilisateur par jeu (en-tête `X-User-Id`)
- Leaderboard top-N : meilleur score par utilisateur classé décroissant (`MAX(score) GROUP BY user_id`)
- Historique de scores personnel pour toute combinaison utilisateur/jeu
- Requête de meilleur score personnel
- Limite configurable (plafonnée 1-100)

## Schéma

```sql
CREATE TABLE IF NOT EXISTS scores (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    game       TEXT    NOT NULL,
    score      INTEGER NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_scores_game ON scores (game, score DESC);
CREATE INDEX IF NOT EXISTS idx_scores_user ON scores (user_id, game);
```

## Endpoints

| Méthode | Chemin | Auth | Description |
|---------|--------|------|-------------|
| `POST` | `/scores` | Utilisateur | Soumettre un score |
| `GET` | `/leaderboard?game=<jeu>` | Public | Top-N leaderboard |
| `GET` | `/scores/{userId}?game=<jeu>` | Public | Historique de scores de l'utilisateur |

## Soumission de score

```php
$game  = trim((string) ($body['game'] ?? ''));
$score = $body['score'] ?? null;

if ($game === '' || strlen($game) > 64) {
    return $this->problem(422, 'validation-failed', 'game required (max 64 chars).');
}
if (!is_int($score) || $score < 0) {
    return $this->problem(422, 'validation-failed', 'score must be a non-negative integer.');
}
```

Points clés :
- `is_int($score)` — vérification stricte ; rejette les floats (`1.5`) et les chaînes du JSON
- Nom de jeu plafonné à 64 chars — prévient le DoS par nom de jeu surdimensionné
- Score non-négatif — prévient l'injection de score négatif

Retourne le meilleur personnel mis à jour sur 201 :

```json
{ "message": "Score recorded.", "best_score": 9800 }
```

## Requête de classement top-N

Classement meilleur-par-utilisateur avec assignation de rang dense en PHP :

```php
public function leaderboard(string $game, int $limit): array
{
    $stmt = $this->pdo->prepare(
        'SELECT user_id, MAX(score) AS best_score
         FROM scores
         WHERE game = :game
         GROUP BY user_id
         ORDER BY best_score DESC
         LIMIT :lim'
    );
    $stmt->bindValue(':game', $game, PDO::PARAM_STR);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ranked = [];
    foreach ($rows as $i => $row) {
        $ranked[] = array_merge($row, ['rank' => $i + 1]);
    }
    return $ranked;
}
```

- `MAX(score) GROUP BY user_id` — une ligne par utilisateur, leur meilleur personnel
- `ORDER BY best_score DESC` — le plus grand scorer en premier
- Liaison `PDO::PARAM_INT` pour LIMIT — protégé contre l'injection SQL

Exemple de réponse :

```json
{
  "leaderboard": [
    { "user_id": 42, "best_score": 9800, "rank": 1 },
    { "user_id": 7,  "best_score": 7200, "rank": 2 }
  ]
}
```

## Plafonnement de limite

```php
$limit = ctype_digit($limitRaw) ? (int) $limitRaw : 10;
if ($limit < 1 || $limit > 100) {
    $limit = 10;
}
```

Les limites invalides ou hors-plage se remettent silencieusement à 10 — ne jamais faire confiance aux entiers fournis par le client pour LIMIT.

## Patterns de validation

| Entrée | Vérification | Raison |
|--------|--------------|--------|
| `score` | `is_int($score) && $score >= 0` | Rejette les floats, chaînes, négatifs |
| `game` | `strlen($game) <= 64` | Prévient les entrées surdimensionnées |
| `limit` | `ctype_digit()` + plafonnement de plage | Sûr contre ReDoS, borné |
| `userId` (chemin) | `ctype_digit()` + `> 0` | Valide avant la requête DB |
