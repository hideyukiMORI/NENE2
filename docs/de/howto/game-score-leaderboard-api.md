# How-to: Game Score & Leaderboard API

> **FT-Referenz**: FT259 (`NENE2-FT/scorelog`) — Game-Score-Einreichung mit Bulk-Insert (max 100, alles-oder-nichts), Pro-Spieler-Leaderboard mit `best_score`-Aggregation und `play_count`, Prävention negativer Punktzahlen, Paginierung, 20 Tests bestanden.

Diese Anleitung zeigt, wie ein Spielstand-System aufgebaut wird: individuelle Spielstände aufzeichnen, Ergebnisse bulk-importieren und sortierte Leaderboards pro Spiel berechnen.

## Schema

```sql
CREATE TABLE scores (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    player     TEXT    NOT NULL,
    game       TEXT    NOT NULL,
    score      INTEGER NOT NULL CHECK(score >= 0),
    played_at  TEXT    NOT NULL,      -- ISO-8601-Datum: YYYY-MM-DD
    created_at TEXT    NOT NULL
);

CREATE INDEX idx_scores_game      ON scores (game);
CREATE INDEX idx_scores_player    ON scores (player);
CREATE INDEX idx_scores_played_at ON scores (played_at);
```

`CHECK(score >= 0)` verhindert negative Punktzahlen auf DB-Ebene. Indizes auf `game` und `player` unterstützen gefilterte Listen- und Leaderboard-Abfragen.

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/scores` | Einen Spielstand einreichen |
| `POST` | `/scores/bulk` | Bis zu 100 Spielstände bulk-einreichen |
| `GET` | `/scores` | Spielstände auflisten (Filter + Paginierung) |
| `GET` | `/scores/leaderboard` | Pro-Spieler-Leaderboard für ein Spiel |
| `GET` | `/scores/{id}` | Einzelnen Spielstand abrufen |
| `DELETE` | `/scores/{id}` | Spielstand löschen |

## Spielstand einreichen

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

Mehrere Spielstände pro Spieler pro Spiel sind erlaubt — jedes Spiel ist ein separater Eintrag.

### Validierung

```php
POST /scores  {"game": "tetris", "score": 100, "played_at": "2026-01-15"}
→ 422  // player ist erforderlich

POST /scores  {"player": "Alice", "game": "tetris", "score": -1, "played_at": "2026-01-15"}
→ 422  // score muss >= 0 sein

POST /scores  {"player": "Alice", "game": "tetris", "score": 100, "played_at": "15/01/2026"}
→ 422  // played_at muss im Format YYYY-MM-DD sein

POST /scores  {"player": "Alice", "game": "tetris", "score": 0, "played_at": "2026-01-15"}
→ 201  // score = 0 ist gültig
```

## Spielstände auflisten

```php
// Alle Spielstände
GET /scores
→ 200  {"items": [...], "total": 10}

// Nach Spiel filtern
GET /scores?game=tetris
→ 200  {"items": [/* nur Tetris-Spielstände */], "total": 3}

// Nach Spieler filtern
GET /scores?player=Alice
→ 200  {"items": [/* nur Alices Spielstände */], "total": 2}

// Paginierung
GET /scores?limit=2&offset=1
→ 200  {"items": [/* 2 Einträge, ab Index 1 */], "total": 5}
```

## Bulk-Einreichung

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

### Bulk-Validierungsregeln

```php
// Leeres Array
POST /scores/bulk  {"scores": []}
→ 422  // mindestens 1 Eintrag erforderlich

// Jeder ungültige Eintrag lässt den gesamten Batch fehlschlagen
POST /scores/bulk
{"scores": [
  {"player": "Alice", "game": "tetris", "score": 1000, "played_at": "2026-01-15"},
  {"player": "",      "game": "tetris", "score": 500,  "played_at": "2026-01-15"}
]}
→ 422  // "player" darf nicht leer sein — keine Einträge werden eingefügt

// Über 100 Einträge
POST /scores/bulk  {"scores": [...101 Einträge...]}
→ 422  // max 100 Einträge pro Bulk-Request
```

**Alles-oder-nichts**: Alle Einträge validieren, bevor irgendwelche eingefügt werden. Eine DB-Transaktion für Atomarität verwenden.

### Bulk-Implementierung

```php
public function bulkSubmit(array $entries): array
{
    // Alle Einträge zuerst validieren
    foreach ($entries as $i => $entry) {
        $this->validate($entry, "scores[{$i}]");
    }

    // Alle in einer Transaktion einfügen
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

## Leaderboard

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

Jeder Spieler erscheint **einmal** — mit seinem **besten** (höchsten) Spielstand über alle Spiele, plus `play_count`.

### Top-N-Limit

```php
GET /scores/leaderboard?game=tetris&top=3
→ 200  {"entries": [...3 Spieler...], "top": 3}

GET /scores/leaderboard?game=tetris&top=0
→ 422  // top muss >= 1 sein

GET /scores/leaderboard          // fehlendes game
→ 422
```

### Leaderboard-SQL

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

`RANK() OVER (ORDER BY MAX(score) DESC)` weist gleichen Spielern denselben Rang zu (Lücken bei nachfolgenden Rängen). Bei gewünschter lückenloser Rangfolge `DENSE_RANK()` verwenden.

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| Negative Punktzahlen nur auf Anwendungsebene verhindern | `CHECK(score >= 0)`-DB-Constraint ist die letzte Absicherung; Anwendungsvalidierung kann umgangen werden |
| Bulk-Einträge einzeln ohne Transaktion einfügen | Teilfehler lässt die Hälfte des Batches in der DB; unmöglich zu unterscheiden was committed wurde |
| Bulk-Einträge innerhalb der Insert-Schleife validieren | Erste N Einträge werden eingefügt, bevor die Validierung fehlschlägt; Teildaten in DB |
| `score = MAX(score)` ohne GROUP BY verwenden | Aggregiert die gesamte Tabelle ohne Spieler-Gruppierung; falsches Leaderboard-Ergebnis |
| Alle Spieler im Leaderboard ohne LIMIT zurückgeben | Full-Table-Scan und Sortierung ohne Begrenzung; DoS-Risiko bei großen Score-Tabellen |
| `best_score` durch Abrufen aller Scores in PHP berechnen | O(N) pro Spieler; SQL `MAX()`-Aggregation verwenden |
