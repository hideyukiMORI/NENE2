# Anleitung: Upvote-/Downvote-API

> **FT-Referenz**: FT347 (`NENE2-FT/votelog`) — Benutzer-spezifisches Upvote/Downvote mit Toggle-Off (gleiche Richtung zweimal entfernt Stimme), Richtungswechsel (auf→ab atomar), Score-Aggregation (Upvotes − Downvotes), UNIQUE(user_id, item_id)-Bedingung, 15 Tests BESTANDEN.

Diese Anleitung zeigt, wie ein Reddit/Stack Overflow-ähnliches Abstimmungssystem implementiert wird: Jeder Benutzer kann eine Stimme pro Element abgeben, sie durch erneute Abstimmung in derselben Richtung ausschalten oder die Richtung ändern.

## Schema

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

`UNIQUE(user_id, item_id)` erzwingt eine Stimme pro Benutzer pro Element. `CHECK(direction IN ('up', 'down'))` lehnt jeden anderen Wert auf DB-Ebene ab.

## Endpunkte

| Methode | Pfad                          | Beschreibung                       |
|---------|-------------------------------|------------------------------------|
| `POST` | `/items/{id}/vote`            | Stimme abgeben, umschalten oder ändern |
| `GET`  | `/items/{id}/score`           | Element-Score abrufen              |
| `GET`  | `/items/{id}/vote/{userId}`   | Aktuelle Stimme des Benutzers abrufen |

## Stimme abgeben

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

## Toggle-Off (Gleiche Richtung zweimal)

In **derselben Richtung** ein zweites Mal abstimmen entfernt die Stimme:

```php
// Erste Stimme
POST /items/1/vote  {"user_id": 42, "direction": "up"}
→ 200  {"vote": "up", "score": {"score": 1}}

// Zweite Stimme in gleicher Richtung → Toggle-Off
POST /items/1/vote  {"user_id": 42, "direction": "up"}
→ 200  {"vote": null, "score": {"score": 0}}
```

`vote: null` bedeutet, der Benutzer hat keine aktive Stimme für dieses Element.

## Richtungswechsel

In die **entgegengesetzte Richtung** abstimmen kippt die bestehende Stimme atomar:

```php
// Mit Upvote beginnen
POST /items/1/vote  {"user_id": 42, "direction": "up"}
→ 200  {"vote": "up", "score": {"upvotes": 1, "downvotes": 0, "score": 1}}

// Zu Downvote wechseln
POST /items/1/vote  {"user_id": 42, "direction": "down"}
→ 200  {"vote": "down", "score": {"upvotes": 0, "downvotes": 1, "score": -1}}
```

## Score abrufen

```php
GET /items/1/score

→ 200
{
  "upvotes": 2,
  "downvotes": 1,
  "score": 1         // Upvotes − Downvotes
}
```

Score für ein Element ohne Stimmen:

```php
GET /items/1/score   // noch keine Stimmen
→ 200  {"upvotes": 0, "downvotes": 0, "score": 0}
```

## Benutzer-Stimm-Status abrufen

```php
// Noch keine Stimme abgegeben
GET /items/1/vote/42
→ 200  {"vote": null}

// Nach Upvote
GET /items/1/vote/42
→ 200  {"vote": "up"}

// Nach Toggle-Off
GET /items/1/vote/42
→ 200  {"vote": null}
```

## Implementierung

### Stimm-Handler-Logik

```php
public function vote(int $itemId, int $userId, string $direction): array
{
    $item = $this->repo->findItem($itemId);
    if ($item === null) {
        throw new ItemNotFoundException($itemId);
    }

    $existing = $this->repo->findVote($userId, $itemId);

    if ($existing !== null && $existing['direction'] === $direction) {
        // Gleiche Richtung → Toggle-Off
        $this->repo->deleteVote($userId, $itemId);
        $activeDirection = null;
    } elseif ($existing !== null) {
        // Andere Richtung → an Ort und Stelle aktualisieren
        $this->repo->updateVote($userId, $itemId, $direction, $now);
        $activeDirection = $direction;
    } else {
        // Neue Stimme
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

### Score-Aggregations-SQL

```sql
SELECT
    COALESCE(SUM(CASE WHEN direction = 'up'   THEN 1 ELSE 0 END), 0) AS upvotes,
    COALESCE(SUM(CASE WHEN direction = 'down' THEN 1 ELSE 0 END), 0) AS downvotes,
    COALESCE(SUM(CASE WHEN direction = 'up'   THEN 1 ELSE -1 END), 0) AS score
FROM votes
WHERE item_id = ?
```

`COALESCE(..., 0)` stellt Null-Werte sicher, wenn keine Stimmen existieren (SUM einer leeren Menge gibt NULL zurück).

### Stimm-UPSERT-Muster

```sql
-- Neue Stimme einfügen
INSERT INTO votes (user_id, item_id, direction, created_at) VALUES (?, ?, ?, ?)

-- Richtung aktualisieren (UNIQUE-Bedingung verhindert Duplikat)
UPDATE votes SET direction = ? WHERE user_id = ? AND item_id = ?

-- Löschen (Toggle-Off)
DELETE FROM votes WHERE user_id = ? AND item_id = ?
```

## Validierung

```php
// Ungültige Richtung
POST /items/1/vote  {"user_id": 42, "direction": "sideways"}
→ 422  // Richtung muss 'up' oder 'down' sein

// Element existiert nicht
POST /items/9999/vote  {"user_id": 42, "direction": "up"}
→ 404
```

## Mehrere Benutzer

```php
// Drei Benutzer stimmen für dasselbe Element
POST /items/1/vote  {"user_id": 1, "direction": "up"}
POST /items/1/vote  {"user_id": 2, "direction": "up"}
POST /items/1/vote  {"user_id": 3, "direction": "down"}

GET /items/1/score
→ 200  {"upvotes": 2, "downvotes": 1, "score": 1}
```

---

## Was NICHT zu tun ist

| Anti-Muster | Risiko |
|---|---|
| Kein `UNIQUE(user_id, item_id)` | Benutzer können mehrmals abstimmen und Scores aufblähen |
| `INSERT OR REPLACE` für Richtungswechsel | Generiert neue `id` und `created_at`; verliert Abstimmungshistorie; bricht Prüfpfade |
| 409 bei Toggle-Off zurückgeben | Toggle-Off ist erwartetes Verhalten, kein Fehler; neuen (null) Stimm-Status zurückgeben |
| Score in Anwendung durch Laden aller Stimmen berechnen | O(N) pro Anfrage; SQL-Aggregation mit einzelner Abfrage verwenden |
| `direction: null` im Body zum Entfernen der Stimme erlauben | Mehrdeutig; Toggle-Muster (gleiche Richtung zweimal) oder separaten DELETE-Endpunkt verwenden |
| `COALESCE` in Score-Aggregation weglassen | `SUM()` gibt `NULL` zurück, wenn keine Zeilen übereinstimmen; `null − null` verursacht Abstürze oder falschen Typ |
