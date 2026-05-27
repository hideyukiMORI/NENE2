# Paginierung

Für die Paginierung von Listen-Endpunkten stehen zwei Muster zur Verfügung: **OFFSET** und **Cursor** (Keyset). Die Wahl hängt von Datenmenge und UI-Anforderungen ab.

## Kurzvergleich

| | OFFSET | Cursor |
|---|---|---|
| Implementierung | Einfach | Moderat (fetch+1-Muster) |
| Gesamtanzahl | Erfordert `COUNT(*)` | Nicht benötigt |
| Tiefe-Seiten-Geschwindigkeit | Sinkt linear | Konstant (Index-Seek) |
| Seitennummer-UI | Einfach | Schwierig |
| Infinite Scroll / Feed | Fragil (Zeilen-Drift) | Stabil |
| Datenänderungen beim Durchsuchen | Kann Zeilen-Drift verursachen | Stabil |

**Faustregel:** OFFSET für Admin-Tabellen mit Seitenzahlen und kleinen Datensätzen verwenden. Cursor für Feeds, Infinite Scroll und jede Tabelle mit mehr als ~10.000 Zeilen.

## OFFSET-Paginierung

```php
private function listByOffset(ServerRequestInterface $request): ResponseInterface
{
    $limit  = max(1, min(100, QueryStringParser::int($request, 'limit', 20) ?? 20));
    $offset = max(0, QueryStringParser::int($request, 'offset', 0) ?? 0);

    $items = $repo->fetchAll(
        'SELECT * FROM articles ORDER BY id DESC LIMIT ? OFFSET ?',
        [$limit, $offset],
    );
    $total = $repo->fetchOne('SELECT COUNT(*) AS cnt FROM articles', [])['cnt'] ?? 0;

    return $json->create([
        'items'       => $items,
        'limit'       => $limit,
        'offset'      => $offset,
        'total'       => (int) $total,
        'has_more'    => ($offset + $limit) < (int) $total,
        'next_offset' => ($offset + $limit) < (int) $total ? $offset + $limit : null,
    ]);
}
```

**Warum OFFSET langsamer wird**: Die Datenbank muss alle Zeilen vor dem Offset scannen und verwerfen. Bei `OFFSET 5000` liest die Engine 5001 Zeilen und verwirft die ersten 5000. Mit SQLite überprüfen:

```sql
EXPLAIN QUERY PLAN
SELECT * FROM articles ORDER BY id DESC LIMIT 20 OFFSET 5000;
-- SCAN articles USING INDEX idx_articles_id_desc
-- Der Scan berührt immer noch 5020 Zeilen.
```

## Cursor-Paginierung

Der Cursor ist die `id` der zuletzt gesehenen Zeile. Jede Seite holt Zeilen "vor" dem Cursor (für absteigende Reihenfolge) mit `WHERE id < cursor`, was der Index mit einem Seek bedient — keine Zeilen vor dem Cursor werden berührt.

```php
private function listByCursor(ServerRequestInterface $request): ResponseInterface
{
    $limit   = max(1, min(100, QueryStringParser::int($request, 'limit', 20) ?? 20));
    $afterId = QueryStringParser::int($request, 'after'); // null = erste Seite

    // fetch+1-Muster: has_more erkennen ohne COUNT-Abfrage
    $fetch = $limit + 1;

    if ($afterId === null) {
        $rows = $repo->fetchAll(
            'SELECT * FROM articles ORDER BY id DESC LIMIT ?',
            [$fetch],
        );
    } else {
        $rows = $repo->fetchAll(
            'SELECT * FROM articles WHERE id < ? ORDER BY id DESC LIMIT ?',
            [$afterId, $fetch],
        );
    }

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows); // die extra Sentinel-Zeile verwerfen
    }

    $nextCursor = $hasMore && $rows !== [] ? (int) end($rows)['id'] : null;

    return $json->create([
        'items'       => $rows,
        'limit'       => $limit,
        'has_more'    => $hasMore,
        'next_cursor' => $nextCursor,
    ]);
}
```

### Das fetch+1-Muster

Um zu wissen, ob es eine nächste Seite gibt, ohne `COUNT(*)` auszugeben:

1. `limit + 1` Zeilen anfordern.
2. Wenn das Ergebnis mehr als `limit` Zeilen hat, gibt es eine nächste Seite.
3. Die letzte Zeile verwerfen (`array_pop`) vor der Rückgabe.
4. Die `id` der letzten verbleibenden Zeile als `next_cursor` verwenden.

Dies vermeidet eine extra Abfrage auf Kosten des Abrufens einer extra Zeile.

### Client-Verwendung

```
GET /articles/cursor?limit=20
→ { items: [...20 Artikel], has_more: true, next_cursor: 42 }

GET /articles/cursor?limit=20&after=42
→ { items: [...20 Artikel], has_more: true, next_cursor: 22 }

GET /articles/cursor?limit=20&after=22
→ { items: [...2 Artikel], has_more: false, next_cursor: null }
```

## Limit-Klemmung

Das Limit immer auf einen vernünftigen Bereich klemmen, um unbegrenzte Abfragen zu verhindern:

```php
$limit = max(1, min(100, QueryStringParser::int($request, 'limit', 20) ?? 20));
```

Dies akzeptiert `1–100` und setzt Standard auf `20`, wenn der Parameter fehlt.

## Wann von OFFSET zu Cursor wechseln

Eine grobe Richtlinie basierend auf Tabellengröße und typischer Seitenbenutzte:

| Zeilen | Typische Tiefe | Empfehlung |
|--------|----------------|-----------|
| < 10.000 | Beliebig | Beide funktionieren; OFFSET ist einfacher |
| 10.000–100.000 | Flach (Seite 1–5) | Beide; Index auf Sortierspalte hinzufügen |
| 10.000–100.000 | Tief (Seite 10+) | Cursor bevorzugt |
| > 100.000 | Beliebig | Cursor stark empfohlen |

Unabhängig vom verwendeten Ansatz einen Index auf die Sortierspalte hinzufügen:

```sql
CREATE INDEX idx_articles_id_desc ON articles (id DESC);
```

## Ergebnisse an derselben Position vergleichen

Bei der Migration von OFFSET zu Cursor die Korrektheit durch Abrufen desselben "Fensters" von Zeilen auf beiden Wegen überprüfen:

```php
// OFFSET: Zeilen 11–20 (0-indizierter offset=10)
$offsetPage = $get('/articles/offset?limit=10&offset=10');

// Cursor: die id an Position 10 abrufen (offset=9), als Anker verwenden
$anchor     = $get('/articles/offset?limit=1&offset=9');
$anchorId   = $anchor['items'][0]['id'];
$cursorPage = $get("/articles/cursor?limit=10&after={$anchorId}");

// Diese sollten identisch sein
assert(array_column($offsetPage['items'], 'id') === array_column($cursorPage['items'], 'id'));
```
