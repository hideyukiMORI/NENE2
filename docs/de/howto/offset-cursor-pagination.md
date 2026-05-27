# How-to: Offset- und Cursor-Paginierung

> **FT-Referenz**: FT325 (`NENE2-FT/pagelog`) — Duale Paginierungsstrategie (offset-basiert und cursor-basiert) mit `next_offset`/`next_cursor`, `has_more`, Kategorie-Filter, 15 Tests / 47 Assertions PASS.

Diese Anleitung zeigt, wie beide Paginierungsstrategien — offset-basiert und cursor-basiert — für dieselbe Ressource implementiert werden, sodass Clients die für ihren Anwendungsfall passende Strategie wählen können.

## Schema

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    author     TEXT    NOT NULL,
    category   TEXT    NOT NULL DEFAULT 'general',
    created_at TEXT    NOT NULL
);
```

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `POST` | `/articles` | Artikel erstellen |
| `GET`  | `/articles/offset` | Offset-Paginierung |
| `GET`  | `/articles/cursor` | Cursor-Paginierung |
| `GET`  | `/articles/by-category` | Kategorie-Filter |

## Offset-Paginierung

```
GET /articles/offset?limit=10&offset=0
→ 200
{
  "items": [...],     // 10 Artikel
  "total": 25,
  "limit": 10,
  "offset": 0,
  "has_more": true,
  "next_offset": 10   // null auf letzter Seite
}

// Seite 2
GET /articles/offset?limit=10&offset=10
→ {"items": [...], "has_more": true, "next_offset": 20}

// Letzte Seite
GET /articles/offset?limit=10&offset=20
→ {"items": [...], "has_more": false, "next_offset": null}

// Jenseits des Endes
GET /articles/offset?limit=10&offset=100
→ {"items": [], "has_more": false}
```

`next_offset = offset + limit` wenn `has_more`, sonst `null`.

## Cursor-Paginierung

```
GET /articles/cursor?limit=10
→ 200
{
  "items": [...],        // neueste zuerst
  "has_more": true,
  "next_cursor": 15      // id des letzten zurückgegebenen Artikels
}

// Nächste Seite mit Cursor
GET /articles/cursor?limit=10&after=15
→ {"items": [...], "has_more": true, "next_cursor": 5}

// Letzte Seite
GET /articles/cursor?limit=10&after=5
→ {"items": [...], "has_more": false, "next_cursor": null}
```

Cursor ist die `id` des zuletzt zurückgegebenen Artikels: `WHERE id < $after ORDER BY id DESC LIMIT $limit + 1` (einen Extra-Eintrag vorausschauen, um `has_more` zu bestimmen).

## Kategorie-Filter

```
GET /articles/by-category?category=tech&limit=5
→ {"items": [...], "total": N}
```

## Offset vs. Cursor — Wann verwenden

| Kriterium | Offset | Cursor |
|-----------|--------|--------|
| Zufälliger Seitensprung | ✅ `?offset=50` | ❌ Muss traversieren |
| Gesamtanzahl benötigt | ✅ Immer enthalten | ❌ Teuer |
| Konsistente Ergebnisse bei Inserts | ❌ Neue Zeile verschiebt Seite | ✅ Stabil |
| Performance bei großen Datensätzen | ❌ `OFFSET N` scannt N Zeilen | ✅ `WHERE id < X` nutzt Index |
| Infinite Scroll / Feed | ❌ | ✅ |

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| `next_offset` auch auf letzter Seite zurückgeben | Client macht eine zusätzliche leere Anfrage |
| `OFFSET N` auf Tabellen mit Millionen Zeilen verwenden | DB scannt N Zeilen vor der Rückgabe; bei großen Daten Cursor verwenden |
| `has_more` aus Cursor-Antwort weglassen | Client kann nicht wissen, ob die nächste Seite abgerufen werden soll |
| Timestamp als Cursor verwenden | Doppelte Timestamps verursachen übersprungene oder wiederholte Zeilen; einzigartigen Integer-ID verwenden |
