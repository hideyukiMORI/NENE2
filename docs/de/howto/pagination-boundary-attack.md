# How-to: Paginierungsgrenze & Limit-Injection

**FT177 — limitlog**

Kugelsichere Integer-Parametervalidierung für Offset- und Cursor-basierte Paginierung —
verhindert DB-Dumps, Überläufe, Typverwechslung und ReDoS.

---

## Die Angriffsfläche

Jeder Paginierungsendpunkt exponiert mindestens zwei Integer-Parameter (`limit`, `page` / `after`).
Angreifer sondieren diese routinemäßig mit:

| Angriff | Beispiel | Risiko |
|--------|---------|------|
| Überdimensionierter Limit | `limit=999999` | Vollständiger Tabellen-Dump |
| Null/Negativ | `limit=0`, `limit=-1` | Negativer OFFSET → DB-Fehler oder Überlauf |
| Float-Injection | `limit=10.5`, `limit=1e2` | Stiller Cast: `(int)"10.5" === 10` |
| Gepolstert / vorzeichenbehaftet | `limit=+10`, `limit= 10` | Stilles Trimmen: `(int)" 10" === 10` |
| Integer-Überlauf | `limit=99999999999999999999` | 64-Bit-Überlauf zu negativem Wert |
| Nicht-numerisch | `limit=abc`, `limit=1;DROP TABLE` | Typfehler oder Injection |
| Hex / Oktal | `limit=0x10`, `limit=010` | `0x` → schlägt ctype fehl; `010` besteht! |
| Doppelter Parameter | `?limit=5&limit=1000` | Letzter Wert überschattet validierten |
| ReDoS-Payload | `limit=111...1x` | Exponentielles Regex-Backtracking |

---

## Das `clampInt()`-Muster

```php
/**
 * @param array<string, mixed> $params
 */
private function clampInt(array $params, string $key, ?int $default, int $min, int $max): ?int
{
    if (!array_key_exists($key, $params)) {
        return $default;  // abwesend → Standard verwenden (nicht null = ungültig)
    }

    $raw = $params[$key];

    // ctype_digit: O(n), ReDoS-immun, lehnt '' / '-' / '.' / '+' / ' ' / 'e' ab
    // ctype_digit('') === false  →  leerer String bereits abgelehnt
    if (!is_string($raw) || !ctype_digit($raw)) {
        return null;  // Signal: Aufrufer muss 422 zurückgeben
    }

    // PHP-stillen Überlauf verhindern: (int)"99999999999999999999" läuft über
    if (strlen($raw) > 18) {
        return null;
    }

    $value = (int) $raw;

    if ($value < $min || $value > $max) {
        return null;
    }

    return $value;
}
```

### Warum `ctype_digit`, nicht Regex

| Validator | ReDoS-sicher? | Lehnt `010` ab? | Lehnt `+10` ab? |
|-----------|------------|----------------|----------------|
| `/^\d+$/` | ❌ exponentiell bei `111...1x` | ✅ | ❌ |
| `ctype_digit()` | ✅ O(n) | ✅ (`0`-Präfix: besteht — aber durch Bereich begrenzt) | ✅ |
| `is_numeric()` | ✅ | ❌ | ❌ |
| `filter_var(FILTER_VALIDATE_INT)` | ✅ | ✅ | ❌ (`+10` besteht!) |

**`ctype_digit()` verwenden** — es ist das strengste und schnellste.

### Die `010`-Tücke

`ctype_digit('010')` → `true` (besteht Ziffernprüfung), `(int)'010'` → `10` (dezimal, nicht oktal).
Das ist sicher, weil PHP bei String-gecasteten Integern keine Oktal-Interpretation durchführt
(anders als `010` als PHP-Literal). In Tests bestätigen, wenn das Team unsicher ist.

---

## Cursor-basierte Paginierung

```php
// Eine zusätzliche Zeile abrufen, um has_more zu bestimmen — keine COUNT-Abfrage nötig
$rows = $this->db->fetchAll(
    'SELECT * FROM articles WHERE id < ? ORDER BY id DESC LIMIT ?',
    [$afterId, $limit + 1],
);

$hasMore = count($rows) > $limit;
if ($hasMore) {
    array_pop($rows);  // Sentinel verwerfen
}

$nextCursor = $hasMore && count($rows) > 0 ? end($rows)->id : null;
```

### Cursor-Sentinel für „erste Seite"

```php
private const int NO_CURSOR = PHP_INT_MAX;

// GET /articles/cursor (kein ?after-Parameter) → afterId auf PHP_INT_MAX voreingestellt
// WHERE id < PHP_INT_MAX  ==>  effektiv alle Zeilen
```

---

## Offset-Paginierung — Seite-Null-Schutz

`page=0` erzeugt `OFFSET = (0-1) * limit = -limit` — negativer OFFSET ist ein SQL-Fehler
in einigen Datenbanken (MySQL lehnt ihn ab) oder läuft in anderen still über.

```php
$page  = $this->clampInt($params, 'page', 1, 1, PHP_INT_MAX);
// min=1 → page=0 gibt null zurück → 422
```

---

## Integer-Überlauf-Schutz

PHPs `(int)`-Cast auf einem 20-stelligen String läuft still über:

```php
(int)'99999999999999999999'  // === -1 auf 64-Bit-PHP
```

Der `strlen($raw) > 18`-Schutz verhindert dies vor dem Cast. 18 Stellen decken sicher
`PHP_INT_MAX` (19 Stellen) mit einem Puffer ab, damit der Cast immer sicher ist.

---

## VULN-A bis VULN-L Checkliste

| # | Test | Erwartung |
|---|------|-------------|
| VULN-A | `limit` über MAX (100) | 422 — explizite Ablehnung, keine stille Abschneidung |
| VULN-B | `limit=0`, `limit=-1` | 422 — `0` schlägt min=1 fehl; `-` schlägt ctype_digit fehl |
| VULN-C | Float-String `10.5`, `1e2`, `1.0` | 422 — `.` und `e` schlagen ctype_digit fehl |
| VULN-D | Gepolstert `%2010`, `10%20`, `%2B10` | 422 — Leerzeichen/`+` schlagen ctype_digit fehl |
| VULN-E | Überlauf `9999...` (20 Stellen) | 422 — strlen > 18 Schutz |
| VULN-F | Nicht-numerisch, hex `0x10`, SQL-Injection | 422 — ctype_digit lehnt alle ab |
| VULN-G | `page=0` (Offset-Paginierung) | 422 — min=1 Schutz |
| VULN-H | Cursor-Grenze: `after=0` gültig, Überlauf-Cursor 422 | Gemischt |
| VULN-I | `author_id=0`, `-1`, `abc`, `1.5` | 422 |
| VULN-J | Sehr große Seitennummer (page=999999) | 200 leer — darf nicht abstürzen |
| VULN-K | Doppelter Parameter `?limit=5&limit=1000` | 200 (sicher) oder 422 — niemals > MAX |
| VULN-L | ReDoS-Payload `111...1x` (50 Stellen + x) | 422 in < 100ms |

---

## Test-Hinweis: VULN-J vs. VULN-A

Diese sehen widersprüchlich aus, dienen aber unterschiedlichen Zielen:

- **VULN-A**: `limit=999999` → **422** — unangemessen große Zeilenanzahl ablehnen
- **VULN-J**: `page=999999&limit=10` → **200 leer** — eine gültige Seite, die zufällig keine Daten hat

Der Server darf bei einer semantisch gültigen, aber praktisch leeren Seite nicht abstürzen oder Fehler zurückgeben.
`OFFSET = (999999-1) * 10 = 9999980` ist ein legaler SQL-OFFSET; das Ergebnis ist einfach leer.
