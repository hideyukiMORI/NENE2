# So verhindern Sie SQL ORDER BY-Injection

SQL-`ORDER BY`-Klauseln können nicht mit Standard-Platzhaltern (`?`) parametrisiert werden. Das bedeutet,
dass vom Benutzer kontrollierte Sortierspalten und -richtungen niemals direkt in SQL interpoliert werden
dürfen. Diese Anleitung erklärt den einzigen sicheren Ansatz: eine explizite Allowlist.

---

## Das Problem

Prepared-Statement-Platzhalter schützen Spaltenwerte in `WHERE`-Klauseln, funktionieren aber **nicht**
für Spaltennamen oder Sortierrichtungen in `ORDER BY`:

```php
// ❌ FALSCH — schützt NICHT gegen Injection
$stmt = $pdo->prepare("SELECT * FROM articles ORDER BY ? ?");
$stmt->execute([$column, $direction]);
// Viele Datenbanktreiber behandeln ORDER BY-Argumente als Literale, nicht als Bezeichner.
```

Ein Angreifer, der `?sort=SLEEP(5)` oder `?sort=(SELECT password FROM users LIMIT 1)` sendet, kann
zeitbasierte Angriffe, Informationsleckagen oder Fehler verursachen, die Schema-Details enthüllen.

---

## Die einzige sichere Lösung: Explizite Allowlist

```php
// ✅ SICHER — Allowlist + in_array strict
public const array SORT_COLUMNS = ['id', 'title', 'status', 'created_at'];
public const array SORT_DIRS    = ['asc', 'desc'];

$sql = "SELECT * FROM articles ORDER BY {$sortCol} {$sortDir} LIMIT ?";
```

Die Allowlist-Werte sind **hardcodierte Zeichenketten**, die Sie kontrollieren. Nur diese Werte erreichen
jemals SQL.

---

## Vollständiges Route-Handler-Muster

```php
// ── Sortierspalte — MUSS gegen Allowlist validiert werden ─────────────────────────
//
// SICHERHEIT: ORDER BY unterstützt keine ?-Platzhalter in Standard-SQL.
// Der EINZIGE sichere Ansatz ist eine explizite Allowlist, geprüft mit in_array strict.
//
$rawSort = $params['sort'] ?? null;

if ($rawSort !== null) {
    // Array-Injection: PSR-7 könnte Array für ?sort[]=id liefern
    if (!is_string($rawSort)) {
        return $this->responseFactory->create(['error' => 'sort must be a string.'], 422);
    }

    // Null-Byte-Prüfung — PSR-7 dekodiert %00 zum tatsächlichen Null-Byte
    if (str_contains($rawSort, "\0")) {
        return $this->responseFactory->create(['error' => 'sort contains invalid characters.'], 422);
    }

    // Allowlist-Prüfung — strict, case-sensitiv.
    // PSR-7 URL-dekodiert Query-Strings bereits einmal (%65 → e), daher werden
    // einfach kodierte gültige Spaltennamen akzeptiert. Doppelt kodierte Werte
    // (%2565 → %65 in $rawSort) werden NICHT ein zweites Mal dekodiert, sie scheitern
    // daher an der Allowlist und werden abgelehnt.
    if (!in_array($rawSort, MyRepository::SORT_COLUMNS, true)) {
        return $this->responseFactory->create(
            ['error' => sprintf('sort must be one of: %s.', implode(', ', MyRepository::SORT_COLUMNS))],
            422,
        );
    }

    $sortCol = $rawSort;
} else {
    $sortCol = 'created_at';  // sicherer Standardwert
}

// ── Sortierrichtung — nur Allowlist ───────────────────────────────────────────
$rawOrder = $params['order'] ?? null;

if ($rawOrder !== null) {
    if (!is_string($rawOrder)) {
        return $this->responseFactory->create(['error' => 'order must be a string.'], 422);
    }

    $dir = strtolower(trim($rawOrder));

    if (!in_array($dir, MyRepository::SORT_DIRS, true)) {
        return $this->responseFactory->create(
            ['error' => sprintf('order must be one of: %s.', implode(', ', MyRepository::SORT_DIRS))],
            422,
        );
    }

    $sortDir = $dir;
} else {
    $sortDir = 'desc';  // sicherer Standardwert
}
```

---

## Repository-Schicht

Das Repository empfängt bereits validierte Werte und interpoliert sie direkt:

```php
/**
 * $sortCol und $sortDir MÜSSEN vom Aufrufer Allowlist-verifiziert sein.
 * Diese Methode vertraut ihnen und interpoliert sie direkt in SQL.
 *
 * @return array{data: list<Article>, total: int, sort: string, order: string, limit: int}
 */
public function list(string $sortCol, string $sortDir, ?ArticleStatus $status, int $limit): array
{
    $where  = $status !== null ? 'WHERE status = ?' : '';
    $params = $status !== null ? [$status->value] : [];

    // $sortCol und $sortDir sind vorvalidiert — sicher zum Interpolieren.
    // Hier niemals rohe Benutzereingaben einfügen.
    $sql  = "SELECT * FROM articles {$where} ORDER BY {$sortCol} {$sortDir} LIMIT ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([...$params, $limit]);
    ...
}
```

---

## Von diesem Ansatz blockierte Angriffsmuster

| Angriff | Eingabe | Ergebnis |
|---|---|---|
| DROP TABLE-Injection | `?sort='; DROP TABLE articles--` | 422 — nicht in Allowlist |
| UNION SELECT-Exfiltration | `?sort=1; SELECT password` | 422 — nicht in Allowlist |
| Unterabfrage-Extraktion | `?sort=(SELECT name FROM sqlite_master)` | 422 — nicht in Allowlist |
| Zeitbasierter Blind-Angriff | `?sort=SLEEP(5)` | 422 — nicht in Allowlist |
| Spaltenindex-Injection | `?sort=1` | 422 — nicht in Allowlist |
| Unbekannte Spalte | `?sort=password` | 422 — nicht in Allowlist |
| Case/Kommentar-Umgehung | `?sort=CREATED_AT--` | 422 — case-sensitiv |
| Null-Byte-Umgehung | `?sort=created_at%00` | 422 — Null-Byte-Prüfung |
| Array-Injection | `?sort[]=created_at` | 422 — Typprüfung |
| Doppelte URL-Kodierung | `?sort=cr%2565ated_at` | 422 — PSR-7 dekodiert einmal; `cr%65ated_at` nicht in Allowlist |
| Einfache URL-Kodierung (gültig) | `?sort=cr%65ated_at` | 200 — PSR-7 dekodiert zu `created_at` ✓ |
| Richtungs-Injection | `?order=asc; UNION SELECT 1--` | 422 — nicht in Allowlist |

---

## Schlüsselpunkte

1. **Kein `rawurldecode()` nach PSR-7**: `getQueryParams()` von PSR-7 dekodiert den Query-String
   bereits einmal. Erneutes Aufrufen von `rawurldecode()` würde doppelt kodierte Werte durch die
   Allowlist-Prüfung schlüpfen lassen.

2. **`in_array($value, $allowlist, true)`**: Das dritte Argument `true` aktiviert strikten
   (typsicheren) Vergleich. Ohne es gibt `in_array(0, ['id', 'created_at'])` `true` zurück,
   weil PHP Zeichenketten zu Integers koerziert.

3. **Case-sensitiver Check**: Spaltennamen sollten kleingeschrieben und exakt abgeglichen werden.
   `strcasecmp` oder `strtolower` niemals vor dem Allowlist-Check verwenden — `CREATED_AT` ist
   aus Vertrauensperspektive nicht dasselbe Token wie `created_at`.

4. **Richtung: `strtolower(trim())` ist sicher**: Im Gegensatz zu Spaltennamen hat die Richtung
   (`asc`/`desc`) nur zwei gültige Werte. Case vor dem Allowlist-Check zu normalisieren ist
   akzeptabel, da die Allowlist selbst erschöpfend und kleingeschrieben ist.

5. **Vertrag dokumentieren**: Die Repository-Methode muss dokumentieren, dass sie ihrer Eingabe
   vertraut. Aufrufer dürfen niemals rohe Benutzereingaben übergeben.

---

## Verwandt

- FT180 — sortlog: SQL ORDER BY-Injection & Dynamische Sort/Filter-Prävention
- [RFC 3986](https://www.rfc-editor.org/rfc/rfc3986) — URI-Kodierung
- [PSR-7](https://www.php-fig.org/psr/psr-7/) — `ServerRequestInterface::getQueryParams()`
