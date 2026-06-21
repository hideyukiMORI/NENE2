# How-to: Bulk-Reorder-API (Drag-and-Drop-Sortierung)

Eine Drag-and-Drop-Oberfläche sendet die *gesamte* neue Reihenfolge einer Liste in einer Anfrage: `[itemC, itemA, itemD, itemB]`. Der naive Server führt ein `UPDATE` pro Element aus — N Roundtrips und eine halb angewendete Reihenfolge, wenn eines fehlschlägt.

Die richtige Form ist **eine Transaktion**, die jede Position mit servergesetzten Werten neu schreibt, eingeschränkt auf das Board des Eigentümers. Wie sie geschrieben wird, hängt von einer Sache ab: **ob `position` eine `UNIQUE (board_id, position)`-Beschränkung trägt.**

> **Verifizierte Tücke (FT352).** SQLite prüft `UNIQUE` **pro Zeile**, während ein `UPDATE` angewendet wird. Daher setzt *jede* Anweisung, die Positionen vertauscht — selbst ein einzelnes `CASE WHEN` über alle Zeilen — vorübergehend zwei Zeilen auf dieselbe Position und schlägt mit `UNIQUE constraint failed: items.board_id, items.position` fehl. Eine einzelne Anweisung reicht **nur aus, wenn `position` keine `UNIQUE`-Beschränkung hat** (§1). Mit der Beschränkung benötigt man einen zweiphasigen Schreibvorgang innerhalb einer Transaktion (§1.1). Der lauffähige Beweis befindet sich in [`NENE2-examples/reorderlog`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/reorderlog).

**Voraussetzung**: Eine Tabelle mit einer Integer-Spalte `position`, die auf ein übergeordnetes Element (`board_id`, `list_id`, …) eingeschränkt ist. Siehe [Content-Pinning](content-pinning.md) für den Einzelelement-Fall.

---

## 1. Eine Anweisung (keine `UNIQUE`-Beschränkung auf `position`)

Der Client sendet nur die *geordnete Liste der IDs*. Der Server leitet die Positionen aus dem Array-Index ab — er vertraut niemals client-gelieferten Positionsnummern. Wenn `position` nur eine indexierte Spalte ist (kein `UNIQUE`), reicht eine einzelne Anweisung aus:

```php
/**
 * @param list<int> $orderedIds  ids in their new display order
 * @return int  number of rows actually updated
 */
public function reorder(int $boardId, array $orderedIds): int
{
    $cases  = '';
    $params = [];
    foreach (array_values($orderedIds) as $position => $id) {
        $cases   .= ' WHEN id = ? THEN ?';
        $params[] = $id;
        $params[] = $position;          // position = array index, not client input
    }

    $placeholders = implode(',', array_fill(0, count($orderedIds), '?'));
    $sql = "UPDATE items
            SET position = CASE{$cases} END
            WHERE board_id = ? AND id IN ({$placeholders})";

    return $this->executor->execute(
        $sql,
        [...$params, $boardId, ...$orderedIds],
    );
}
```

Gegen SQLite verifiziert — Neusortierung von `[1,2,3,4]` zu den IDs `[3,1,4,2]` in einer Anweisung:

```
affected = 4
position 0 -> item 3
position 1 -> item 1
position 2 -> item 4
position 3 -> item 2
```

Die Positionen werden aus dem Array-Index als `0..n-1` neu zugewiesen, sodass das Ergebnis stets zusammenhängend ist, unabhängig davon, was der Client gesendet hat.

---

## 1.1. Zweiphasiger Schreibvorgang, wenn `position` `UNIQUE` ist

Wenn `UNIQUE (board_id, position)` Ihre Sortierung absichert (empfohlen — es stoppt doppelte Positionen auf Datenbankebene), schlägt die obige einzelne Anweisung in dem Moment fehl, in dem sie zwei Zeilen vertauscht. Verschieben Sie zuerst jede Position in einen kollisionsfreien Bereich und weisen Sie dann die endgültigen Werte zu — beide Schritte in **einer Transaktion**, sodass der Zwischenzustand nie beobachtbar ist:

```php
public function reorder(int $boardId, array $orderedIds): void
{
    $this->tx->transactional(function ($executor) use ($boardId, $orderedIds): void {
        // Phase 1: move every position to a unique negative value (no collisions).
        $executor->execute(
            'UPDATE items SET position = -1 - position WHERE board_id = ?',
            [$boardId],
        );

        // Phase 2: assign final positions from the array index.
        $cases = '';
        $params = [];
        foreach ($orderedIds as $position => $id) {
            $cases   .= ' WHEN id = ? THEN ?';
            $params[] = $id;
            $params[] = $position;
        }
        $placeholders = implode(',', array_fill(0, count($orderedIds), '?'));
        $executor->execute(
            "UPDATE items SET position = CASE{$cases} END WHERE board_id = ? AND id IN ({$placeholders})",
            [...$params, $boardId, ...$orderedIds],
        );
    });
}
```

`-1 - position` bildet `0,1,2,…` auf `-1,-2,-3,…` ab — verschiedene Werte, die nicht mit den endgültigen `0..n-1` kollidieren können. Siehe [Transaktionen verwenden](use-transactions.md) für die `transactional()`-Regel (Repositories *innerhalb* des Callbacks instanziieren). Das `testReorderAdjacentSwapDoesNotCollide` von `reorderlog` übt genau den Tausch aus, der eine einzelne Anweisung zum Scheitern bringt.

---

## 2. Die Anzahl betroffener Zeilen ist Ihre Integritätsprüfung

`execute()` gibt die Anzahl der Zeilen zurück, die von `WHERE board_id = ? AND id IN (...)` getroffen werden. Vergleichen Sie sie mit der Anfragegröße:

```php
$updated = $this->reorder($boardId, $orderedIds);
if ($updated !== count($orderedIds)) {
    // The client referenced ids that are not in this board (or do not exist).
    throw new ValidationException(/* 'ids' => 'contains items not in this board' */);
}
```

Diese einzelne Prüfung schlägt den größten Teil der nachfolgenden Angriffsfläche: Jede ID, die zu einem anderen Board gehört oder nicht existiert, passt einfach nicht auf `WHERE`, sodass die Anzahl zu niedrig ausfällt und die gesamte Neusortierung abgelehnt wird.

> Wickeln Sie die Anzahlprüfung und das `UPDATE` in `transactional()` ein, wenn Sie auch verwandte Zeilen mutieren; das einzelne `UPDATE` selbst ist bereits atomar. Siehe [Transaktionen verwenden](use-transactions.md).

---

## ATK-Bewertung — Cracker-Mindset-Angriffstest

Ziel: `PUT /boards/{boardId}/order` mit dem Body `{ "ids": [...] }`, authentifiziert, `board_id` auf den Aufrufer eingeschränkt.

### ATK-01 — Ein Board neu sortieren, das Ihnen nicht gehört (IDOR) 🚫 BLOCKIERT

**Angriff**: Ein gültiges `ids`-Array senden, aber eine `boardId`, die einem anderen Benutzer gehört.
**Ergebnis**: BLOCKIERT — der Eigentum wird vor der Abfrage geprüft (`board.owner_id === caller`) und gibt `404` zurück; selbst wenn übersprungen, trifft `WHERE board_id = ?` keine Zeilen, zu denen die IDs des Aufrufers gehören, sodass die betroffene Anzahl 0 ist und die Anfrage abgelehnt wird.

---

### ATK-02 — Ein fremdes Element in die Reihenfolge einschmuggeln 🚫 BLOCKIERT

**Angriff**: Eine `id` aus einem anderen Board einschließen, um es zu verschieben/zu leaken.
**Ergebnis**: BLOCKIERT — `WHERE board_id = ? AND id IN (...)` schließt die fremde ID aus; betroffene Anzahl < Anfragegröße → `422`, kein partielles Schreiben.

---

### ATK-03 — Teilreihenfolge (IDs weglassen, um Lücken zu erzeugen) 🚫 BLOCKIERT

**Angriff**: Nur die Hälfte der Board-IDs senden, um den Rest auf veralteten Positionen zu belassen.
**Ergebnis**: BLOCKIERT — der Handler verlangt, dass die übermittelte Menge der aktuellen ID-Menge des Boards entspricht (Anzahl + Zugehörigkeit), und lehnt unvollständige Payloads ab.

---

### ATK-04 — Explizite Positionsnummern einschleusen 🚫 BLOCKIERT

**Angriff**: `{ "ids": [...], "positions": [99, -1, ...] }` senden, in der Hoffnung, dass der Server sie berücksichtigt.
**Ergebnis**: BLOCKIERT — der Server ignoriert jede Client-Position; `position` ist der Array-Index. Zusätzliche Body-Felder werden vom readonly DTO verworfen.

---

### ATK-05 — SQL-Injection via id / position 🚫 BLOCKIERT

**Angriff**: `ids: ["1); DROP TABLE items;--", ...]`.
**Ergebnis**: BLOCKIERT — jede ID und Position ist ein gebundener Parameter; die `CASE`/`IN`-Platzhalter werden anhand der Anzahl generiert, nie durch Zeichenkettenkonkatenation.

---

### ATK-06 — Doppelte IDs, um Positionen zu beschädigen 🚫 BLOCKIERT

**Angriff**: `ids: [5, 5, 5]`, sodass eine Zeile mehrere `CASE`-Zweige erhält.
**Ergebnis**: BLOCKIERT — das DTO validiert die ID-Eindeutigkeit; SQLite würde ohnehin das letzte passende `WHEN` anwenden, und die Anzahlprüfung (`distinct ids` vs. Board-Größe) schlägt zuerst fehl.

---

### ATK-07 — Überdimensionierte Payload (DoS) 🚫 BLOCKIERT

**Angriff**: 1.000.000 IDs posten, um ein riesiges `CASE` aufzubauen.
**Ergebnis**: BLOCKIERT — `RequestSizeLimitMiddleware` begrenzt den Body, und der Handler lehnt Arrays ab, die größer sind als die Zeilenanzahl des Boards.

---

### ATK-08 — Nicht-ganzzahlige / negative IDs 🚫 BLOCKIERT

**Angriff**: `ids: ["abc", -1, 1.5]`.
**Ergebnis**: BLOCKIERT — die DTO-Validierung erzwingt/validiert jeden Eintrag als positive Ganzzahl (`422` bei Fehlschlag), bevor irgendein SQL läuft.

---

### ATK-09 — Race Condition bei gleichzeitiger Neusortierung 🚫 BLOCKIERT

**Angriff**: Zwei Neusortierungen gleichzeitig auslösen, um Positionen zu verschachteln.
**Ergebnis**: BLOCKIERT — jede Neusortierung läuft in einer Transaktion; der letzte Schreiber gewinnt mit einer vollständig konsistenten `0..n-1`-Reihenfolge, niemals einer verschachtelten Mischung. Der zweiphasige Schreibvorgang (§1.1) hält den Zwischenzustand innerhalb der Transaktion, sodass ein gleichzeitiger Leser nie eine partielle oder kollidierende Reihenfolge sieht.

---

### ATK-10 — Positionsüberlauf / nicht zusammenhängendes Ergebnis 🚫 BLOCKIERT

**Angriff**: Hoffen, dass wiederholte Neusortierungen die Positionen zu riesigen oder spärlichen Werten driften lassen.
**Ergebnis**: BLOCKIERT — jede Neusortierung schreibt Positionen ab `0` neu, sodass die Spalte stets dicht und durch die Zeilenanzahl begrenzt ist.

---

### ATK-11 — Leere Reihenfolge, um Positionen zu löschen 🚫 BLOCKIERT

**Angriff**: `ids: []`.
**Ergebnis**: BLOCKIERT — leere Arrays scheitern an der Validierung (`min 1`), und ein leeres `IN ()` wäre ein Syntaxfehler, der nie ausgeführt wird.

---

### ATK-12 — Mandantenübergreifende Board-ID-Enumeration 🚫 BLOCKIERT

**Angriff**: `boardId` iterieren, um über unterschiedliche Antworten zu entdecken, welche existieren.
**Ergebnis**: BLOCKIERT — unbekannte und nicht eigene Boards geben beide ein identisches `404` zurück; kein Anzahl- oder Timing-Orakel unterscheidet sie.

---

### ATK-Zusammenfassung

| ID | Angriff | Ergebnis |
|----|--------|--------|
| ATK-01 | Nicht eigenes Board neu sortieren (IDOR) | 🚫 BLOCKIERT |
| ATK-02 | Fremdes Element einschmuggeln | 🚫 BLOCKIERT |
| ATK-03 | Teilreihenfolge / Lücken | 🚫 BLOCKIERT |
| ATK-04 | Explizite Positionen einschleusen | 🚫 BLOCKIERT |
| ATK-05 | SQL-Injection | 🚫 BLOCKIERT |
| ATK-06 | Doppelte IDs | 🚫 BLOCKIERT |
| ATK-07 | Überdimensionierte Payload | 🚫 BLOCKIERT |
| ATK-08 | Nicht-ganzzahlige / negative IDs | 🚫 BLOCKIERT |
| ATK-09 | Race Condition bei gleichzeitiger Neusortierung | 🚫 BLOCKIERT |
| ATK-10 | Positionsüberlauf / Spärlichkeit | 🚫 BLOCKIERT |
| ATK-11 | Leere Reihenfolge | 🚫 BLOCKIERT |
| ATK-12 | Board-ID-Enumeration | 🚫 BLOCKIERT |

**12 BLOCKIERT, 0 EXPONIERT.** Keine kritischen Befunde. Die Kombination aus *servergesetzten Positionen* (Array-Index, niemals Client-Eingabe) und der *Integritätsprüfung von betroffener Anzahl / ID-Menge* gegen ein board-eingeschränktes `WHERE` schließt die Neusortierungs-Angriffsfläche. Die eine *Korrektheits*-Falle (kein Sicherheitsbefund) ist die `UNIQUE (board_id, position)`-Beschränkung: Sie lässt eine einzelne `CASE`-Anweisung bei jedem Tausch fehlschlagen, also verwenden Sie den zweiphasigen transaktionalen Schreibvorgang von §1.1 — verifiziert in [`NENE2-examples/reorderlog`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/reorderlog).

---

## Verwandte Anleitungen

- [Content-Pinning](content-pinning.md) — Positionsverwaltung für Einzelelemente
- [Pin- / Bookmark-Sortierung](pin-bookmark-ordering.md) — Sortierung pro Benutzer
- [Transaktionen verwenden](use-transactions.md) — Mehrtabellen-Neusortierungen atomar einwickeln
