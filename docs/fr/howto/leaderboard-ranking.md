# How-to : API de classement de jeu

Ce guide démontre la construction d'une API de leaderboard où les joueurs soumettent des scores par jeu, et le système suit les meilleurs personnels et produit des classements.

## Vue d'ensemble du pattern

- Les joueurs soumettent des scores via `POST /scores` (identifiés par l'en-tête `X-User-Id`).
- Chaque soumission est stockée ; le meilleur personnel (score le plus élevé jamais) est retourné.
- `GET /leaderboard?game=<nom>` retourne les top-N joueurs classés par leur meilleur personnel.
- `GET /scores/{userId}?game=<nom>` liste tous les scores bruts pour un joueur spécifique.
- Les jeux sont entièrement isolés — les scores dans "tetris" n'affectent jamais "snake".

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
```

Toutes les tentatives sont stockées (pas d'upsert), ce qui permet l'historique de scores par jeu. Le meilleur personnel est dérivé avec `MAX(score)` au moment de la requête.

## Soumission de score

```php
public function submit(int $userId, string $game, int $score): void
{
    $this->pdo->prepare(
        'INSERT INTO scores (user_id, game, score, created_at) VALUES (:uid, :game, :score, :now)'
    )->execute([':uid' => $userId, ':game' => $game, ':score' => $score, ':now' => $this->now()]);
}

public function bestScore(int $userId, string $game): ?int
{
    $stmt = $this->pdo->prepare(
        'SELECT MAX(score) FROM scores WHERE user_id = :uid AND game = :game'
    );
    $stmt->execute([':uid' => $userId, ':game' => $game]);
    $val = $stmt->fetchColumn();
    return $val !== false && $val !== null ? (int) $val : null;
}
```

Le gestionnaire retourne le meilleur personnel avec la confirmation :

```json
{ "message": "Score recorded.", "best_score": 2000 }
```

## Requête de leaderboard

Meilleur score par utilisateur, trié descendant, avec rang ajouté en PHP :

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

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ranked = [];
    foreach ($rows as $i => $row) {
        $ranked[] = array_merge($row, ['rank' => $i + 1]);
    }
    return $ranked;
}
```

Réponse :

```json
{
  "leaderboard": [
    { "user_id": 2, "best_score": 1000, "rank": 1 },
    { "user_id": 1, "best_score": 500,  "rank": 2 }
  ]
}
```

## Règles de validation

| Champ | Règle |
|-------|-------|
| En-tête `X-User-Id` | Requis pour POST ; `ctype_digit`, 1-18 chars, valeur > 0 |
| `game` corps/requête | Requis, non vide, max 64 chars |
| `score` corps | Entier ≥ 0 seulement (`is_int($score) && $score >= 0`) |
| Paramètre de chemin `userId` | `ctype_digit`, max 18 chars, valeur > 0 ; sinon 404 |
| `limit` requête | 1-100, défaut 10 ; valeurs invalides silencieusement plafonnées |

Les scores chaînes (`"100"`) sont rejetés avec 422 car `is_int()` retourne false pour les chaînes même quand la valeur est numérique.

Zéro est un score valide — utile pour les jeux où un joueur peut ne pas scorer du tout.

## Routes

```
POST   /scores              Soumettre un score (X-User-Id requis)
GET    /leaderboard         Top-N joueurs classés (?game= requis, ?limit= optionnel)
GET    /scores/{userId}     Tous les scores pour un joueur (?game= requis)
```

## Isolation des jeux

La colonne `game` agit comme espace de noms. Toujours inclure `WHERE game = :game` dans chaque requête. Un joueur qui score dans "tetris" n'apparaîtra jamais sur le leaderboard "snake".

## Voir aussi

- Source FT206 : `../NENE2-FT/leaderboardlog/`
- Connexe : `docs/howto/rate-limiting.md` (FT200), `docs/howto/coupon-redemption.md` (FT204)
