# How-to: Spiel-Bestenliste & Ranking-API

Diese Anleitung demonstriert den Aufbau einer Bestenlisten-API, bei der Spieler Punktzahlen pro Spiel einreichen und das System persönliche Bestleistungen verfolgt und gerankte Bestenlisten erstellt.

## Übersicht des Musters

- Spieler reichen Punktzahlen über `POST /scores` ein (identifiziert durch den `X-User-Id`-Header).
- Jede Einreichung wird gespeichert; die persönliche Bestleistung (höchste je erzielte Punktzahl) wird zurückgegeben.
- `GET /leaderboard?game=<name>` gibt die Top-N-Spieler nach ihrer persönlichen Bestleistung gerankt zurück.
- `GET /scores/{userId}?game=<name>` listet alle rohen Punktzahlen für einen bestimmten Spieler auf.
- Spiele sind vollständig isoliert — Punktzahlen in "tetris" beeinflussen niemals "snake".

## Schema

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

Alle Versuche werden gespeichert (kein Upsert), was eine Punktzahlhistorie pro Spiel ermöglicht. Die persönliche Bestleistung wird zur Abfragezeit mit `MAX(score)` ermittelt.

## Punktzahl-Einreichung

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

Der Handler gibt die persönliche Bestleistung zusammen mit der Bestätigung zurück:

```json
{ "message": "Score recorded.", "best_score": 2000 }
```

## Bestenlisten-Abfrage

Beste Punktzahl pro Benutzer, absteigend sortiert, mit in PHP hinzugefügtem Rang:

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

Antwort:

```json
{
  "leaderboard": [
    { "user_id": 2, "best_score": 1000, "rank": 1 },
    { "user_id": 1, "best_score": 500,  "rank": 2 }
  ]
}
```

## Validierungsregeln

| Feld | Regel |
|---|---|
| `X-User-Id`-Header | Erforderlich für POST; `ctype_digit`, 1–18 Zeichen, Wert > 0 |
| `game` Body/Query | Erforderlich, nicht leer, max. 64 Zeichen |
| `score` Body | Nur Integer ≥ 0 (`is_int($score) && $score >= 0`) |
| `userId` Pfadparameter | `ctype_digit`, max. 18 Zeichen, Wert > 0; sonst 404 |
| `limit` Query | 1–100, Standard 10; ungültige Werte werden stillschweigend begrenzt |

String-Punktzahlen (`"100"`) werden mit 422 abgelehnt, da `is_int()` für Strings false zurückgibt, auch wenn der Wert numerisch ist.

Null ist eine gültige Punktzahl — nützlich für Spiele, bei denen ein Spieler möglicherweise gar keine Punkte erzielt.

## Routen

```
POST   /scores              Punktzahl einreichen (X-User-Id erforderlich)
GET    /leaderboard         Top-N gerankte Spieler (?game= erforderlich, ?limit= optional)
GET    /scores/{userId}     Alle Punktzahlen eines Spielers (?game= erforderlich)
```

## Spielisolation

Die `game`-Spalte fungiert als Namespace. Immer `WHERE game = :game` in jede Abfrage aufnehmen. Ein Spieler, der in "tetris" Punkte erzielt, erscheint niemals auf der "snake"-Bestenliste.

## Weitere Informationen

- FT206-Quelle: `../NENE2-FT/leaderboardlog/`
- Verwandt: `docs/howto/rate-limiting.md` (FT200), `docs/howto/coupon-redemption.md` (FT204)
