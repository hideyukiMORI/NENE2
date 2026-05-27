# How-to: Cursor-basierte Paginierung

> **FT-Referenz**: FT242 (`NENE2-FT/cursorlog`) — Cursor-basierte Paginierungs-API

Demonstriert cursor-basierte (Keyset-)Paginierung als Alternative zur Offset-Paginierung.
Elemente werden über einen ID-basierten Cursor (`WHERE id < ?`) abgerufen, ein `limit+1`-Trick erkennt `has_more` ohne COUNT-Abfrage, und die Antwort trägt einen `next_cursor`-Wert, den der Aufrufer in der nächsten Anfrage übergibt.

---

## Routen

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/posts` | Post erstellen |
| `GET` | `/posts` | Posts mit Cursor-Paginierung auflisten |
| `GET` | `/posts/{id}` | Einzelnen Post abrufen |

---

## Offset vs. Cursor-Paginierung

| Belang | Offset (`LIMIT ? OFFSET ?`) | Cursor (`WHERE id < ? ORDER BY id`) |
|--------|----------------------------|--------------------------------------|
| Performance bei großen Mengen | Verschlechtert sich — DB muss N Zeilen überspringen | Konstant — Index-Seek zur Cursor-Position |
| Stabile Ergebnisse | Neue Zeilen verschieben nachfolgende Seiten | Stabil — verankert an einer bestimmten Zeile |
| Zufälliger Zugriff | Unterstützt (`?page=5`) | Nicht unterstützt (nur vorwärts) |
| Gesamtanzahl | Benötigt separate `COUNT(*)`-Abfrage | Keine Gesamtzahl erforderlich (Flag `has_more` verwenden) |
| Cursor-Typ | Integer-Offset (positionsbasiert) | Zeilen-Identitätswert (ID-basiert) |

Cursor-Paginierung wird für hochvolumige Echtzeit-Feeds bevorzugt, bei denen Offset-Verzerrung (neue Elemente zwischen Seiten eingefügt) zu duplizierten oder fehlenden Zeilen führt.

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    author     TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_posts_id ON posts(id DESC);
```

Ein absteigender Index auf `id` unterstützt `ORDER BY id DESC` effizient. SQLites `INTEGER PRIMARY KEY` ist bereits ein Alias für `rowid`, daher beschleunigt der explizite Index Bereichsabfragen über das hinaus, was der Primärschlüssel allein bietet.

---

## Cursor-Logik: `WHERE id < ? ORDER BY id DESC LIMIT ?`

Das Repository ruft eine extra Zeile (`limit + 1`) ab, um zu erkennen, ob weitere Seiten existieren:

```php
/**
 * Eine Seite Posts in absteigender ID-Reihenfolge abrufen.
 *
 * @param int|null $afterCursor  ID des zuletzt gesehenen Posts; Posts mit id < afterCursor zurückgeben
 * @param int      $limit        Maximale zurückzugebende Elemente (begrenzt auf 100)
 */
public function paginate(?int $afterCursor, int $limit): CursorPage
{
    $limit = max(1, min(100, $limit));
    // Eine extra Zeile abrufen, um zu erkennen, ob eine nächste Seite existiert
    $fetch = $limit + 1;

    if ($afterCursor !== null) {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM posts WHERE id < ? ORDER BY id DESC LIMIT ?',
            [$afterCursor, $fetch],
        );
    } else {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM posts ORDER BY id DESC LIMIT ?',
            [$fetch],
        );
    }

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);   // Extra-Zeile verwerfen
    }

    $items      = array_map(fn (array $row): Post => $this->hydrate($row), $rows);
    $nextCursor = $hasMore && $items !== [] ? $items[array_key_last($items)]->id : null;

    return new CursorPage($items, $nextCursor, $hasMore);
}
```

Wichtige Schritte:
1. **Limit begrenzen**: `max(1, min(100, $limit))` — verhindert 0-Zeilen- oder unkontrollierte Abfragen.
2. **`limit + 1` abrufen**: Wenn mehr als `$limit` Zeilen zurückkommen, existiert eine nächste Seite.
3. **Extra verwerfen**: `array_pop($rows)` verwirft die (limit+1)-te Zeile, die nur zur Erkennung verwendet wird.
4. **`nextCursor` berechnen**: Die ID des letzten Elements wird zum Cursor, den der Aufrufer als nächstes sendet.
5. **`$hasMore = false`** wenn `$nextCursor === null` — keine weiteren Seiten.

Die erste Seite hat keinen Cursor (`$afterCursor === null`), gibt die neuesten Posts zurück.
Jede nachfolgende Anfrage sendet `?cursor=<nextCursor>`, um dort weiterzumachen, wo sie aufgehört hat.

---

## `CursorPage`-Value-Objekt

```php
final readonly class CursorPage
{
    /** @param list<Post> $items */
    public function __construct(
        public array $items,
        public ?int  $nextCursor,
        public bool  $hasMore,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'items'       => array_map(static fn (Post $p): array => $p->toArray(), $this->items),
            'next_cursor' => $this->nextCursor,
            'has_more'    => $this->hasMore,
        ];
    }
}
```

`next_cursor` ist auf der letzten Seite `null` (keine weiteren Elemente). `has_more` spiegelt dies wider: `true` wenn `next_cursor` gesetzt ist, `false` auf der letzten Seite. Aufrufer hören auf, wenn `has_more === false` oder `next_cursor === null`.

Antwortstruktur:
```json
{
    "items": [...],
    "next_cursor": 42,
    "has_more": true
}
```

---

## Controller: Cursor lesen und validieren

```php
private function list(ServerRequestInterface $request): ResponseInterface
{
    $query       = $request->getQueryParams();
    $limit       = isset($query['limit']) ? (int) $query['limit'] : 10;
    $cursorRaw   = isset($query['cursor']) && is_string($query['cursor']) ? $query['cursor'] : null;
    $afterCursor = $cursorRaw !== null && ctype_digit($cursorRaw) ? (int) $cursorRaw : null;

    $page = $this->repo->paginate($afterCursor, $limit);

    return $this->json->create($page->toArray());
}
```

`ctype_digit($cursorRaw)` validiert den Cursor-String vor dem Casten zu `int`:
- `ctype_digit()` gibt `false` für leere Strings, negative Vorzeichen, Floats und nicht-numerische Strings zurück — alle werden als "kein Cursor" (erste Seite) behandelt.
- Ein ungültiger Cursor fällt auf die erste Seite zurück, anstatt einen Fehler zurückzugeben — Aufrufer, die einen veralteten oder garbage-Cursor übergeben, sehen die erste Seite, kein `400`.

Dies ist eine pragmatische Entscheidung: Ungültige Cursor werden stillschweigend als abwesend behandelt. Für strengere APIs `422 Unprocessable Entity` zurückgeben, wenn `$cursorRaw` nicht null ist, aber `ctype_digit()` fehlschlägt.

---

## Limit-Begrenzung

```php
$limit = max(1, min(100, $limit));
```

- Minimum `1`: verhindert Null-Zeilen-Abfragen.
- Maximum `100`: begrenzt die Seitengröße zur Vermeidung unkontrollierter Abrufe.

Die Begrenzung erfolgt im Repository, nicht im Controller, um sicherzustellen, dass kein Aufrufer von `paginate()` die Grenzen umgehen kann. Der Controller liest `$query['limit']` mit einem Standard von `10`, wenn abwesend.

---

## Paginierungs-Vertrags-Zusammenfassung

| Query-Parameter | Typ | Standard | Verhalten |
|-----------------|-----|---------|-----------|
| `?limit=N` | Integer | 10 | Elemente pro Seite (begrenzt 1–100) |
| `?cursor=ID` | Integer-String | abwesend | Elemente mit `id < ID` abrufen; abwesend = erste Seite |

| Antwortfeld | Typ | Bedeutung |
|-------------|-----|-----------|
| `items` | Array | Serialisierte Elemente für diese Seite |
| `next_cursor` | int \| null | Als `?cursor=` in der nächsten Anfrage übergeben; `null` = letzte Seite |
| `has_more` | bool | `true` wenn weitere Seiten existieren |

---

## Vergleich mit Offset-Paginierung

NEE2s eingebaute `PaginationQueryParser` / `PaginationResponse` verwenden `LIMIT ? OFFSET ?`.
Diese verwenden, wenn:
- Zufälliger Seitenzugriff erforderlich ist (`?page=5`).
- Die Gesamtanzahl der Elemente dem Benutzer angezeigt wird.
- Der Datensatz klein ist und während der Traversierung selten wächst.

Cursor-Paginierung verwenden, wenn:
- Feed-Daten kontinuierlich wachsen (Chat, Aktivitätsstreams, Logs).
- Stabile Traversierung unter Einfügelast erforderlich ist.
- Der Datensatz groß genug ist, dass `OFFSET N` langsam wird.

---

## Verwandte Anleitungen

- [`pagination.md`](pagination.md) — Offset-basierte Paginierung mit `PaginationQueryParser` und `PaginationResponse`
- [`activity-feed.md`](activity-feed.md) — Echtzeit-Feed-Muster
- [`add-pagination.md`](add-pagination.md) — Paginierung zu einem bestehenden Endpunkt hinzufügen
