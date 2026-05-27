# How-to: Bestenliste & Punktzahl-Tracking-API

Diese Anleitung zeigt, wie ein Bestenlisten-System mit Punktzahl-Einreichung, Top-N-Rankings mittels Benutzer-Bestes-Aggregation und persönlicher Punktzahlhistorie mit NENE2 aufgebaut wird.
Muster demonstriert durch den **leaderboardlog** Field Trial (FT206).

## Funktionen

- Punktzahl-Einreichung pro Benutzer pro Spiel (`X-User-Id`-Header)
- Top-N-Bestenliste: beste Punktzahl pro Benutzer absteigend gerankt (`MAX(score) GROUP BY user_id`)
- Persönliche Punktzahlhistorie für jede Benutzer-/Spielkombination
- Abfrage der persönlichen Bestleistung
- Konfigurierbares Limit (begrenzt 1–100)

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
CREATE INDEX IF NOT EXISTS idx_scores_user ON scores (user_id, game);
```

## Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|--------|------|------|-------------|
| `POST` | `/scores` | Benutzer | Punktzahl einreichen |
| `GET` | `/leaderboard?game=<game>` | Öffentlich | Top-N-Bestenliste |
| `GET` | `/scores/{userId}?game=<game>` | Öffentlich | Punktzahlhistorie eines Benutzers |

## Punktzahl-Einreichung

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

Wichtige Punkte:
- `is_int($score)` — strenge Prüfung; lehnt Floats (`1.5`) und Strings aus JSON ab
- Spielname auf 64 Zeichen begrenzt — verhindert DoS durch übermäßig große Spielnamen
- Punktzahl nicht negativ — verhindert Injektion negativer Punktzahlen

Gibt den aktualisierten Bestwert bei 201 zurück:

```json
{ "message": "Score recorded.", "best_score": 9800 }
```

## Top-N-Ranking-Abfrage

Beste-pro-Benutzer-Ranking mit dichter Rangzuweisung in PHP:

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

- `MAX(score) GROUP BY user_id` — eine Zeile pro Benutzer, ihre persönliche Bestleistung
- `ORDER BY best_score DESC` — höchste Punktzahl zuerst
- `PDO::PARAM_INT`-Bindung für LIMIT — SQL-Injection-sicher

Beispielantwort:

```json
{
  "leaderboard": [
    { "user_id": 42, "best_score": 9800, "rank": 1 },
    { "user_id": 7,  "best_score": 7200, "rank": 2 }
  ]
}
```

## Limit-Begrenzung

```php
$limit = ctype_digit($limitRaw) ? (int) $limitRaw : 10;
if ($limit < 1 || $limit > 100) {
    $limit = 10;
}
```

Ungültige oder außerhalb des Bereichs liegende Limits werden stillschweigend auf 10 zurückgesetzt — clientseitig angegebenen Integers für LIMIT niemals vertrauen.

## Validierungsmuster

| Eingabe | Prüfung | Grund |
|-------|-------|--------|
| `score` | `is_int($score) && $score >= 0` | Lehnt Floats, Strings, negative Werte ab |
| `game` | `strlen($game) <= 64` | Verhindert übermäßig große Eingaben |
| `limit` | `ctype_digit()` + Bereichsbegrenzung | ReDoS-sicher, begrenzt |
| `userId` (Pfad) | `ctype_digit()` + `> 0` | Validiert vor DB-Abfrage |
