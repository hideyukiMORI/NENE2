# How-to: Paginierungsgrenze & Limit-Injection-Prävention

> **FT-Referenz**: FT319 (`NENE2-FT/limitlog`) — Offset- und Cursor-Paginierung mit strikter Limit/Seite-Validierung, MAX_LIMIT-Begrenzung, ReDoS-sichere ctype_digit-Validierung, 20 Tests / 384 Assertions PASS.

Diese Anleitung zeigt, wie sichere Paginierung mit Offset- und Cursor-Strategien implementiert wird und dabei Integer-Grenzangriffe und Limit-Injection verhindert werden.

## Konstanten

```php
const DEFAULT_LIMIT = 20;
const MAX_LIMIT     = 100;
```

## Offset-Paginierung

```php
GET /articles?page=1&limit=10
→ 200
{
  "data": [...],      // 10 Einträge
  "total": 25,
  "limit": 10,
  "page": 1,
  "has_more": true
}
```

```php
// Seite 3 von 25 Einträgen bei limit=10 → letzte Seite
GET /articles?page=3&limit=10
→ 200  {"data": [...], "has_more": false}  // 5 Einträge
```

**OFFSET-Berechnung**: `(page - 1) * limit` — page muss ≥ 1 sein, um negativen OFFSET zu verhindern.

## Cursor-Paginierung

```php
GET /articles/cursor?limit=5
→ 200  {"data": [...], "next_cursor": 42, "has_more": true}

GET /articles/cursor?after=42&limit=5
→ 200  {"data": [...], "next_cursor": 37, "has_more": true}

GET /articles/cursor?after=37&limit=5
→ 200  {"data": [...], "next_cursor": null, "has_more": false}
```

Cursor ist die `id` des letzten Elements: `WHERE id < $after ORDER BY id DESC LIMIT $limit`.

## Autorenfilter

```php
GET /articles/by-author?author_id=2&limit=10
→ 200  {"data": [...]}  // nur author_id = 2 Einträge
```

`author_id` muss ein positiver Integer sein (gleiche Validierung wie `limit`).

## Limit-Validierung — `ctype_digit`-Muster

`ctype_digit()` für O(n)-Validierung verwenden — immun gegen ReDoS, anders als Regex `^\d+$`:

```php
/**
 * Einen Query-String-Integer-Parameter parsen.
 * Lehnt ab: null, negativ, float, Überlauf, nicht-numerisch, Leerzeichen.
 */
function parseQueryInt(string $raw, int $min, int $max): int
{
    // Leere, Floats, Vorzeichen, Leerzeichen, Nicht-Ziffern-Zeichen ablehnen
    if ($raw === '' || !ctype_digit($raw)) {
        throw new ValidationException(/* 422 */);
    }
    // Schutz gegen 64-Bit-Überlauf vor Cast
    if (strlen($raw) > 18) {
        throw new ValidationException(/* 422 */);
    }
    $val = (int) $raw;
    if ($val < $min || $val > $max) {
        throw new ValidationException(/* 422 */);
    }
    return $val;
}
```

### Was `ctype_digit` blockiert

| Eingabe | `ctype_digit` | Warum |
|-------|--------------|-----|
| `"10"` | ✅ Besteht | Gültige Ziffern |
| `"0"` | ✅ Besteht (ctype) | Durch min=1-Prüfung abgelehnt |
| `"-1"` | ❌ Abgelehnt | `-` ist keine Ziffer |
| `"10.5"` | ❌ Abgelehnt | `.` ist keine Ziffer |
| `"1e2"` | ❌ Abgelehnt | `e` ist keine Ziffer |
| `"+10"` | ❌ Abgelehnt | `+` ist keine Ziffer |
| `" 10"` | ❌ Abgelehnt | Leerzeichen ist keine Ziffer |
| `"0x10"` | ❌ Abgelehnt | `x` ist keine Ziffer |
| `"10\x00"` | ❌ Abgelehnt | Null-Byte ist keine Ziffer |
| 20-stelliger String | ❌ Abgelehnt | strlen > 18 Schutz |
| ReDoS-Payload `"1...1x"` | ❌ Abgelehnt (schnell) | O(n)-Scan, kein Backtracking |

### Fehlerfälle

```php
GET /articles?limit=999999  → 422  // überschreitet MAX_LIMIT
GET /articles?limit=0       → 422  // min=1
GET /articles?limit=-1      → 422  // nicht ctype_digit
GET /articles?limit=10.5    → 422  // float
GET /articles?limit=abc     → 422  // nicht-numerisch
GET /articles?page=0        → 422  // negativer OFFSET
GET /articles/cursor?after=99999999999999999999  → 422  // Überlauf
```

## Doppelter-Parameter-Angriff

```php
GET /articles?limit=5&limit=1000
// PHP nimmt letzten Wert: 1000 → überschreitet MAX_LIMIT → 422
```

Die meisten PSR-7-Implementierungen nehmen das letzte Vorkommen. Entweder 422 (letzter Wert über MAX) oder 200 mit dem gültigen Wert ist akzeptabel — niemals 1000 still verwenden.

## Große Seitennummer

```php
GET /articles?page=999999&limit=10
→ 200  {"data": [], "has_more": false}  // leer, kein Absturz
```

Eine enorme Seite, die die Gesamtanzahl überschreitet, ist gültig — gibt leere Daten zurück, keinen Fehler.

---

## Was man NICHT tun sollte

| Anti-Muster | Risiko |
|---|---|
| `(int) $raw` ohne `ctype_digit` | `-1`, `1.5`, `" 10"` werden alle still zu Integern gecastet |
| Regex `/^\d+$/` für Integer-Validierung | Katastrophales Backtracking (ReDoS) bei langen gemischten Eingaben |
| Kein MAX_LIMIT-Limit | `limit=999999` dumpt die gesamte Tabelle in einer Anfrage |
| `page=0` erlauben | `OFFSET = (0-1)*limit = -limit` korrumpiert oder macht die SQL-Abfrage fehlerhaft |
| Nur strlen-Überlauf-Schutz | `"1.5"` ist 3 Zeichen — kurz genug zum Bestehen, aber kein gültiger Integer |
| Keine Mindestprüfung für `author_id` | `author_id=0` gibt leere Ergebnisse still zurück; semantisch ungültig |
