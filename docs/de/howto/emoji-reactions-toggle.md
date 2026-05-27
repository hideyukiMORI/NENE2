# How-to: Emoji-Reaktionen mit Toggle und gruppierten Zählungen

> **FT-Referenz**: FT263 (`NENE2-FT/reactionlog`) — Emoji-Reaktionen: Toggle (Hinzufügen/Entfernen), gruppierte Zählungen, Reaktionsliste pro Benutzer

Demonstriert eine Reaktions-API, bei der jeder Benutzer auf ein beliebiges Ziel (Beitrag, Kommentar usw.) mit beliebigen Emojis oder Reaktionstypen reagieren kann. Ein einziger `PUT`-Endpunkt schaltet die Reaktion um: fügt sie hinzu, wenn nicht vorhanden, entfernt sie, wenn bereits vorhanden. Gruppierte Zählungen pro Reaktionstyp werden in einer Zusammenfassungsabfrage zurückgegeben. Eine zusammengesetzte `UNIQUE`-Constraint erzwingt eine-Reaktion-pro-Benutzer-pro-Typ, und `DatabaseConstraintException` behandelt gleichzeitige Toggle-Race-Conditions.

---

## Routen

| Methode | Pfad | Beschreibung |
|----------|------|-------------|
| `PUT`    | `/reactions/{targetType}/{targetId}` | Eine Reaktion umschalten (hinzufügen oder entfernen) |
| `DELETE` | `/reactions/{targetType}/{targetId}/{reactionType}` | Eine bestimmte Reaktion explizit entfernen |
| `GET`    | `/reactions/{targetType}/{targetId}` | Reaktionszusammenfassung abrufen (gruppierte Zählungen) |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS reactions (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    target_id     TEXT    NOT NULL,
    target_type   TEXT    NOT NULL DEFAULT 'post',
    reaction_type TEXT    NOT NULL,
    user_id       TEXT    NOT NULL,
    created_at    TEXT    NOT NULL,
    UNIQUE(target_id, target_type, reaction_type, user_id)
);
CREATE INDEX IF NOT EXISTS idx_reactions_target ON reactions (target_id, target_type);
CREATE INDEX IF NOT EXISTS idx_reactions_user   ON reactions (user_id);
```

`UNIQUE(target_id, target_type, reaction_type, user_id)` erzwingt einen Eintrag pro eindeutiger (Ziel, Benutzer, Reaktion)-Kombination. Ein Versuch, ein Duplikat einzufügen, löst eine Constraint-Verletzung aus, die die Anwendung als `DatabaseConstraintException` abfängt.

`target_type` ermöglicht es demselben Reaktionssystem, mehrere Entitätstypen (`post`, `comment`, `message`) ohne separate Tabellen zu bedienen.

---

## Toggle-Muster

```php
public function toggle(string $targetId, string $targetType, string $reactionType, string $userId): bool
{
    $existing = $this->db->fetchOne(
        'SELECT id FROM reactions WHERE target_id = ? AND target_type = ? AND reaction_type = ? AND user_id = ?',
        [$targetId, $targetType, $reactionType, $userId],
    );

    if ($existing !== null) {
        $this->db->execute('DELETE FROM reactions WHERE id = ?', [(int) $existing['id']]);
        return false;   // Reaktion wurde entfernt
    }

    $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    try {
        $this->db->execute(
            'INSERT INTO reactions (target_id, target_type, reaction_type, user_id, created_at) VALUES (?, ?, ?, ?, ?)',
            [$targetId, $targetType, $reactionType, $userId, $now],
        );
    } catch (DatabaseConstraintException) {
        // Race Condition: gleichzeitiger Toggle vom gleichen Benutzer — als entfernt behandeln
        return false;
    }

    return true;   // Reaktion wurde hinzugefügt
}
```

**Ablauf**:
1. `SELECT`, um zu prüfen, ob die Reaktion existiert.
2. Wenn gefunden: `DELETE` → `false` zurückgeben (entfernt).
3. Wenn nicht gefunden: `INSERT` → `true` zurückgeben (hinzugefügt).
4. Wenn `INSERT` mit UNIQUE-Verletzung (`DatabaseConstraintException`) fehlschlägt: Eine gleichzeitige Anfrage hat dieselbe Zeile zwischen unserem `SELECT` und `INSERT` eingefügt. Als "entfernt" behandeln (der gleichzeitige Toggle hat gewonnen) → `false` zurückgeben.

**Warum `SELECT` dann `INSERT`?** Eine Alternative ist `INSERT OR IGNORE` und Prüfen von `changes() == 0`, um den Fall zu erkennen, dass die Zeile bereits existierte. Der explizite `SELECT`-Ansatz macht die Absicht klarer und liefert einen saubereren Rückgabewert (hinzugefügt vs. entfernt) ohne eine nachfolgende Abfrage.

---

## Controller: 201 beim Hinzufügen, 200 beim Entfernen

```php
$added = $this->repo->toggle($targetId, $targetType, $reactionType, $userId);

return $this->json->create([
    'target_id'     => $targetId,
    'target_type'   => $targetType,
    'reaction_type' => $reactionType,
    'user_id'       => $userId,
    'added'         => $added,
], $added ? 201 : 200);
```

`201 Created` wenn die Reaktion hinzugefügt wird; `200 OK` wenn sie entfernt wird. Das `added`-Feld im Response-Body ermöglicht es Clients, die beiden Fälle ohne Prüfung des Statuscodes zu unterscheiden.

**Warum `PUT` für Toggle?** `PUT` ist laut HTTP-Semantik idempotent. Ein Einzelbenutzer-Toggle ist in seiner Wirkung idempotent (zwei identische `PUT`s kehren zum Ursprungszustand zurück). Alternativ ist `POST` für einen nicht-idempotenten Toggle akzeptabel; die Wahl hängt von der Team-Konvention ab.

---

## Gruppierte Zählungszusammenfassung

```php
public function summary(string $targetId, string $targetType, ?string $userId): ReactionSummary
{
    $rows = $this->db->fetchAll(
        'SELECT reaction_type, COUNT(*) AS cnt
           FROM reactions
          WHERE target_id = ? AND target_type = ?
          GROUP BY reaction_type
          ORDER BY cnt DESC',
        [$targetId, $targetType],
    );

    $counts = [];
    $total  = 0;
    foreach ($rows as $row) {
        $counts[(string) $row['reaction_type']] = (int) $row['cnt'];
        $total += (int) $row['cnt'];
    }

    $userReactions = [];
    if ($userId !== null) {
        $userRows = $this->db->fetchAll(
            'SELECT reaction_type FROM reactions WHERE target_id = ? AND target_type = ? AND user_id = ? ORDER BY created_at ASC',
            [$targetId, $targetType, $userId],
        );
        $userReactions = array_map(fn (array $r) => (string) $r['reaction_type'], $userRows);
    }

    return new ReactionSummary($targetId, $targetType, $counts, $total, $userReactions);
}
```

Zwei Abfragen:
1. Gruppierte Zählungen: `GROUP BY reaction_type ORDER BY cnt DESC` — beliebteste zuerst.
2. Pro-Benutzer-Reaktionen (wenn `$userId` angegeben): welche Reaktionstypen dieser Benutzer angewendet hat.

`ORDER BY cnt DESC` stellt die am häufigsten verwendeten Reaktionen an erste Stelle, was typischer Anzeigepriorität entspricht.

---

## Beispiel-Zusammenfassungsantwort

**Anfrage**: `GET /reactions/post/42?user_id=alice`

```json
{
  "target_id": "42",
  "target_type": "post",
  "counts": {
    "👍": 15,
    "❤️": 8,
    "😂": 3
  },
  "total": 26,
  "user_reactions": ["👍"]
}
```

`counts` ist eine Abbildung von Reaktionstyp auf Zählung. `user_reactions` ist die Liste der Reaktionen, die `alice` angewendet hat. Der Client kann `👍` hervorheben, um Alices aktive Reaktion anzuzeigen.

---

## Expliziter Entfernen-Endpunkt

```php
public function remove(string $targetId, string $targetType, string $reactionType, string $userId): bool
{
    $count = $this->db->execute(
        'DELETE FROM reactions WHERE target_id = ? AND target_type = ? AND reaction_type = ? AND user_id = ?',
        [$targetId, $targetType, $reactionType, $userId],
    );
    return $count > 0;
}
```

`DELETE /reactions/{targetType}/{targetId}/{reactionType}` mit `user_id` im Body entfernt eine bestimmte Reaktion ohne Toggle-Semantik. Nützlich, wenn der Client einen bestimmten Reaktionstyp entfernen möchte, unabhängig vom aktuellen Zustand.

Gibt 404 zurück, wenn keine passende Reaktion gefunden wurde (`$count == 0`).

---

## Zusammengesetzte UNIQUE-Constraint als Sicherheitsnetz

Die `UNIQUE(target_id, target_type, reaction_type, user_id)`-Constraint:
- **Primäre Durchsetzung**: verhindert doppelte Reaktionen auf DB-Ebene.
- **Sekundärer Vorteil**: fängt Race Conditions ab, die an der `SELECT`-Prüfung vorbeischlüpfen.
- **Anwendungslogik**: `toggle()` fängt `DatabaseConstraintException` ab und behandelt sie als Entfernung.

Ohne die Constraint würde ein Race zwischen zwei gleichzeitigen `PUT`-Anfragen vom gleichen Benutzer zwei identische Zeilen einfügen. Die Constraint + Exception-Handler bewahren die Invariante (eine Zeile pro Benutzer pro Reaktionstyp) auch unter Nebenläufigkeit.

---

## Designentscheidungen

| Entscheidung | Wahl | Begründung |
|---|---|---|
| Toggle-Endpunkt | `PUT` | Semantisch angemessen; idempotent |
| Reaktionsidentität | 4-Spalten-Composite-Key | Keine separate Reaktionstyp-Tabelle erforderlich |
| `target_type` | PATH-Parameter | Ermöglicht einem Endpunkt, mehrere Entitätstypen zu bedienen |
| `user_id` im Request-Body | Pflichtfeld | Vermeidet Auth-Middleware für dieses FT |
| `user_id` in der Zusammenfassung | Query-Parameter | Optional — Zusammenfassung ist öffentlich; Pro-Benutzer-Detail ist opt-in |

---

## Verwandte Anleitungen

- [`multi-value-tag-filter.md`](multi-value-tag-filter.md) — M:N-Join-Tabelle mit INSERT OR IGNORE für Tag-Deduplizierung
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — zusammengesetzte Unique-Keys als DB-Level-Sicherheitsnetze
- [`transaction-scope-pattern.md`](transaction-scope-pattern.md) — atomare Operationen, wenn mehrere Schreibvorgänge zusammen gelingen oder scheitern müssen
