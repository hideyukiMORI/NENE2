# Anleitung: SQL ORDER BY-Injection verhindern

SQL-`ORDER BY`-Klauseln können nicht mit Standard-Platzhaltern (`?`) parametrisiert werden. Das bedeutet,
benutzergesteuerte Sortierspalten und -richtungen dürfen niemals direkt in SQL interpoliert werden.
Diese Anleitung erklärt den einzig sicheren Ansatz: eine explizite Allowlist.

---

## Das Problem

Prepared-Statement-Platzhalter schützen Spaltenwerte in `WHERE`-Klauseln, funktionieren aber **nicht**
für Spaltennamen oder Sortierrichtungen in `ORDER BY`:

```php
// ❌ FALSCH — schützt NICHT vor Injection
$stmt = $pdo->prepare("SELECT * FROM articles ORDER BY ? ?");
$stmt->execute([$column, $direction]);
// Viele Datenbanktreiber behandeln ORDER BY-Argumente als Literale, nicht als Bezeichner.
```

Ein Angreifer, der `?sort=SLEEP(5)` oder `?sort=(SELECT password FROM users LIMIT 1)` sendet, kann
zeitbasierte Angriffe, Informationspreisgabe oder Fehler verursachen, die Schemadetails enthüllen.

---

## Die einzige sichere Lösung: Explizite Allowlist

```php
// ✅ SICHER — Allowlist + in_array strict
public const array SORT_COLUMNS = ['id', 'title', 'status', 'created_at'];
public const array SORT_DIRS    = ['asc', 'desc'];

$sql = "SELECT * FROM articles ORDER BY {$sortCol} {$sortDir} LIMIT ?";
```

Die Allowlist-Werte sind **hartcodierte Strings**, die man selbst kontrolliert. Nur diese Werte erreichen jemals SQL.

---

## Vollständiges Routen-Handler-Muster

```php
// ── Sortierspalte — MUSS gegen Allowlist validiert werden ─────────────────────
//
// SICHERHEIT: ORDER BY unterstützt keine ?-Platzhalter in Standard-SQL.
// Der EINZIGE sichere Ansatz ist eine explizite Allowlist, geprüft mit in_array strict.
//
$rawSort = $params['sort'] ?? null;

if ($rawSort !== null) {
    // Array-Injektion: PSR-7 kann Array für ?sort[]=id liefern
    if (!is_string($rawSort)) {
        return $this->responseFactory->create(['error' => 'sort must be a string.'], 422);
    }

    // Null-Byte-Prüfung — PSR-7 decodiert %00 zum tatsächlichen Null-Byte
    if (str_contains($rawSort, "\0")) {
        return $this->responseFactory->create(['error' => 'sort contains invalid characters.'], 422);
    }

    // Allowlist-Prüfung — strikt, case-sensitive.
    // PSR-7 URL-decodiert Query-Strings bereits einmal (%65 → e), sodass einfach-kodierte gültige
    // Spaltennamen akzeptiert werden. Doppelt-kodierte Werte (%2565 → %65 in $rawSort) werden NICHT
    // ein zweites Mal decodiert, scheitern also an der Allowlist und werden abgelehnt.
    if (!in_array($rawSort, MyRepository::SORT_COLUMNS, true)) {
        return $this->responseFactory->create(
            ['error' => sprintf('sort must be one of: %s.', implode(', ', MyRepository::SORT_COLUMNS))],
            422,
        );
    }

    $sortCol = $rawSort;
} else {
    $sortCol = 'created_at';  // sicherer Standard
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
    $sortDir = 'desc';  // sicherer Standard
}
```

---

## Repository-Layer

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

    // $sortCol und $sortDir sind vorvalidiert — sicher zu interpolieren.
    // Niemals rohe Benutzereingaben hier einfügen.
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
| DROP TABLE-Injektion | `?sort='; DROP TABLE articles--` | 422 — nicht in Allowlist |
| UNION SELECT-Exfiltration | `?sort=1; SELECT password` | 422 — nicht in Allowlist |
| Subquery-Extraktion | `?sort=(SELECT name FROM sqlite_master)` | 422 — nicht in Allowlist |
| Zeitbasierter Blind | `?sort=SLEEP(5)` | 422 — nicht in Allowlist |
| Spaltenindex-Injektion | `?sort=1` | 422 — nicht in Allowlist |
| Unbekannte Spalte | `?sort=password` | 422 — nicht in Allowlist |
| Case/Kommentar-Bypass | `?sort=CREATED_AT--` | 422 — case-sensitive |
| Null-Byte-Bypass | `?sort=created_at%00` | 422 — Null-Byte-Prüfung |
| Array-Injektion | `?sort[]=created_at` | 422 — Typprüfung |
| Doppelte URL-Kodierung | `?sort=cr%2565ated_at` | 422 — PSR-7 decodiert einmal; `cr%65ated_at` nicht in Allowlist |
| Einfache URL-Kodierung (gültig) | `?sort=cr%65ated_at` | 200 — PSR-7 decodiert zu `created_at` ✓ |
| Richtungs-Injektion | `?order=asc; UNION SELECT 1--` | 422 — nicht in Allowlist |

---

## Schlüsselpunkte

1. **Kein `rawurldecode()` nach PSR-7**: PSR-7s `getQueryParams()` decodiert den Query-String bereits einmal. Das erneute Aufrufen von `rawurldecode()` würde es doppelt-kodierten Werten ermöglichen, die Allowlist-Prüfung zu umgehen.

2. **`in_array($value, $allowlist, true)`**: Das dritte Argument `true` aktiviert strikt (typ-sicheren) Vergleich. Ohne es gibt `in_array(0, ['id', 'created_at'])` `true` zurück, weil PHP Strings zu Integers konvertiert.

3. **Case-sensitive Prüfung**: Spaltennamen sollten Kleinbuchstaben sein und exakt gematcht werden. Niemals `strcasecmp` oder `strtolower` vor der Allowlist-Prüfung verwenden — `CREATED_AT` ist aus Vertrauensperspektive nicht dasselbe Token wie `created_at`.

4. **Richtung: `strtolower(trim())` ist sicher**: Im Gegensatz zu Spaltennamen hat die Richtung (`asc`/`desc`) nur zwei gültige Werte. Die Normalisierung der Groß-/Kleinschreibung vor der Allowlist-Prüfung ist akzeptabel, da die Allowlist selbst erschöpfend und kleinbuchstabig ist.

5. **Vertrag dokumentieren**: Die Repository-Methode muss dokumentieren, dass sie ihren Eingaben vertraut. Aufrufer dürfen niemals rohe Benutzereingaben übergeben.

---

## Verwandt

- FT180 — sortlog: SQL ORDER BY Injection & Dynamic Sort/Filter Prevention
- [RFC 3986](https://www.rfc-editor.org/rfc/rfc3986) — URI-Kodierung
- [PSR-7](https://www.php-fig.org/psr/psr-7/) — `ServerRequestInterface::getQueryParams()`
