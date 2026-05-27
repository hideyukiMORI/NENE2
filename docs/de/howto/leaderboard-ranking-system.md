# Anleitung: Bestenliste (Ranking-System) mit NENE2 aufbauen

Diese Anleitung zeigt Schritt für Schritt, wie eine Bestenliste aufgebaut wird, bei der Benutzer Punkte einreichen, Rankings einsehen und ihren eigenen Rang überprüfen können. Nur der Bestwert pro Benutzer pro Bestenliste wird gespeichert.

**Field Trial**: FT141  
**NENE2-Version**: ^1.5  
**Behandelte Themen**: Bestwert-UPDATE-Muster, Rangberechnung mit COUNT(*), Score-Eigentumscheck, Query-Parameter-Begrenzung, Schwachstellenbewertung

---

## Was gebaut wird

- `POST /leaderboards` — Bestenliste erstellen
- `POST /leaderboards/{id}/scores` — Punktzahl einreichen (nur behalten, wenn neuer Bestwert)
- `GET /leaderboards/{id}/rankings` — Top-N-Rankings (absteigende Punktzahl, `?limit=N`)
- `GET /leaderboards/{id}/rankings/me` — Eigener Rang und Punktzahl des Aufrufers
- `DELETE /leaderboards/{id}/scores/{userId}` — Eigene Punktzahl löschen (nur Eigentümer)

---

## Datenbankschema

```sql
CREATE TABLE leaderboards (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE scores (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    leaderboard_id INTEGER NOT NULL,
    user_id        INTEGER NOT NULL,
    score          INTEGER NOT NULL,
    submitted_at   TEXT    NOT NULL,
    UNIQUE (leaderboard_id, user_id),
    FOREIGN KEY (leaderboard_id) REFERENCES leaderboards(id),
    FOREIGN KEY (user_id)        REFERENCES users(id)
);
```

`UNIQUE (leaderboard_id, user_id)` — eine Punktzahlzeile pro Benutzer pro Bestenliste; Updates ersetzen diese.

---

## Bestwert-UPDATE-Muster

```php
public function submitScore(int $leaderboardId, int $userId, int $score, string $now): bool
{
    $existing = $this->findScore($leaderboardId, $userId);

    if ($existing === null) {
        $this->executor->execute(
            'INSERT INTO scores (leaderboard_id, user_id, score, submitted_at) VALUES (?, ?, ?, ?)',
            [$leaderboardId, $userId, $score, $now],
        );
        return true;
    }

    if ($score > $existing['score']) {
        $this->executor->execute(
            'UPDATE scores SET score = ?, submitted_at = ? WHERE leaderboard_id = ? AND user_id = ?',
            [$score, $now, $leaderboardId, $userId],
        );
        return true;
    }

    return false;  // Kein neuer persönlicher Bestwert
}
```

Gibt `true` zurück, wenn die Punktzahl ein neuer Bestwert ist (nützlich für UI-Feedback), `false` wenn ignoriert.

---

## Rangberechnung mit COUNT(*)

Anstelle einer Fensterfunktion (`RANK()` ist nicht in allen SQLite-Versionen verfügbar) wird gezählt, wie viele Punktzahlen höher sind:

```php
public function getUserRank(int $leaderboardId, int $userId): ?int
{
    $score = $this->findScore($leaderboardId, $userId);

    if ($score === null) {
        return null;
    }

    $row   = $this->executor->fetchOne(
        'SELECT COUNT(*) as cnt FROM scores WHERE leaderboard_id = ? AND score > ?',
        [$leaderboardId, $score['score']],
    );
    $ahead = isset($row['cnt']) ? (int) $row['cnt'] : 0;

    return $ahead + 1;
}
```

Wenn 0 Benutzer eine höhere Punktzahl haben, ist der Rang 1. Wenn 5 Benutzer höher sind, ist der Rang 6. Gleichstände erhalten denselben Rang.

---

## Score-Eigentumscheck (IDOR-Prävention)

```php
if ($actorId !== $userId) {
    return $this->responseFactory->create(['error' => 'cannot delete another user\'s score'], 403);
}
```

Immer die Identität des Aufrufers gegen den Zielbenutzer prüfen, bevor DELETE ausgeführt wird. Ohne diese Prüfung könnte jeder authentifizierte Benutzer jede Punktzahl löschen.

---

## Query-Parameter-Begrenzung

```php
$limit = isset($query['limit']) && is_numeric($query['limit']) ? (int) $query['limit'] : 10;

if ($limit <= 0 || $limit > 100) {
    $limit = 10;
}
```

Das Limit begrenzen, um zu verhindern, dass `?limit=99999` die gesamte Tabelle scannt.

---

## Schwachstellenbewertung (FT141)

| ID | Angriff | Erwartet | Ergebnis |
|----|---------|----------|----------|
| VULN-A | IDOR: Punktzahl eines anderen Benutzers löschen | 403 | Pass |
| VULN-B | Punktzahl für einen anderen Benutzer einreichen | 200 (erlaubt) | Pass |
| VULN-C | SQL-Injection im Bestenlisten-Namen | 201 (wörtlich) | Pass |
| VULN-D | Fehlendes X-User-Id bei /rankings/me | 400 | Pass |
| VULN-E | Nicht-numerisches X-User-Id | nicht 200 | Pass |
| VULN-F | Negative Bestenlisten-ID | nicht 200 | Pass |
| VULN-G | PHP_INT_MAX als Punktzahl | 200 (gültige int) | Pass |
| VULN-H | Float-Punktzahl (Typverwechslung) | 422 | Pass |
| VULN-I | String-Punktzahl (Typverwechslung) | 422 | Pass |
| VULN-J | Fehlendes X-User-Id bei DELETE | 400 | Pass |
| VULN-K | user_id=0 bei Punktzahl-Einreichung | 422 | Pass |
| VULN-L | `?limit=99999` (großes Limit) | 200 + begrenzt | Pass |

Alle 12 Schwachstellentests bestehen. Keine Schwachstellen gefunden.

---

## Häufige Fallstricke

| Fallstrick | Lösung |
|---------|-----|
| Alle eingereichten Punktzahlen speichern statt nur den Bestwert | `findScore()`-Prüfung vor INSERT; UPDATE wenn höher |
| Verwendung von RANK(), das in SQLite möglicherweise nicht existiert | `COUNT(*) WHERE score > ?` liefert äquivalenten Rang |
| IDOR bei Score-DELETE | `$actorId !== $userId` prüfen → 403 |
| Unbegrenzter Limit-Parameter verursacht Tabellenscan | `limit` auf Bereich 1–100 begrenzen |
| Float/String-Punktzahl umgeht `is_int()` | `!is_int($score)` lehnt Floats und Strings im PHP 8-JSON-Decode ab |
