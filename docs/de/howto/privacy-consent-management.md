# How-to: Datenschutz-Einwilligungsverwaltung

> **Muster erprobt durch FT189 consentlog** — DSGVO-konforme Einwilligungsverfolgung mit unveränderlichem Verlauf, IDOR-Prävention und Schutz vor Benutzer-Enumeration. VULN-A bis L alle bestanden.

---

## Was behandelt wird

Ein Datenschutz-Einwilligungsmanagement-Ablauf:

1. **Einwilligung erteilen** — Benutzer erteilt Einwilligung für einen benannten Zweck
2. **Einwilligung widerrufen** — Benutzer widerruft Einwilligung
3. **Einwilligungen auflisten** — aktueller Einwilligungsstatus für alle Zwecke
4. **Verlauf** — unveränderliches, nur-anhängendes Protokoll pro Zweck

Sicherheitsgarantien:

| Problem | Technik |
|---|---|
| IDOR — Einwilligungen eines anderen Benutzers | Alle Abfragen begrenzen auf `WHERE user_id = :user_id` |
| Mass Assignment (granted-Feld) | `granted` wird vom Server gesteuert; Body kann nicht überschreiben |
| SQL-Injection in purpose | `ctype_alnum()` — nur alphanumerische Zeichen |
| ReDoS in purpose | `ctype_alnum()` O(n) — kein Regex |
| Typverwechslung | `is_string()` vor `ctype_alnum()` |
| Benutzer-Enumeration | Unbekannter Benutzer gibt leeres Array zurück, kein 404 |
| Race Condition bei Erteilung/Widerruf | UPSERT-Atomarität auf `UNIQUE(user_id, purpose)` |
| Einwilligungs-Replay | Verlauf ist nur-anhängend; jede Änderung ist ein neuer Eintrag |

---

## Schema

```sql
CREATE TABLE consents (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    purpose    TEXT    NOT NULL,  -- alphanumerischer Slug: 'marketing', 'analytics', etc.
    granted    INTEGER NOT NULL DEFAULT 1,  -- 1=erteilt, 0=widerrufen
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE(user_id, purpose)
);

CREATE TABLE consent_history (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    purpose    TEXT    NOT NULL,
    granted    INTEGER NOT NULL,   -- 1=erteilt, 0=widerrufen
    created_at TEXT    NOT NULL    -- Zeitpunkt dieser Änderung
);
```

`UNIQUE(user_id, purpose)` ermöglicht atomaren UPSERT. `consent_history` ist nur-anhängend — wird niemals aktualisiert.

---

## API

| Methode | Pfad | Header | Beschreibung |
|---|---|---|---|
| `POST` | `/consents` | `X-User-Id` | Einwilligung erteilen (201) |
| `DELETE` | `/consents/{purpose}` | `X-User-Id` | Einwilligung widerrufen (200) |
| `GET` | `/consents` | `X-User-Id` | Aktuelle Einwilligungen auflisten |
| `GET` | `/consents/{purpose}/history` | `X-User-Id` | Prüfungsverlauf (nur-anhängend) |

---

## Kernmuster: Idempotenter UPSERT

```php
// Erteilen — idempotent: eine bereits erteilte Einwilligung erneut zu erteilen ist sicher
INSERT INTO consents (user_id, purpose, granted, created_at, updated_at)
VALUES (:user_id, :purpose, 1, :now, :now)
ON CONFLICT(user_id, purpose) DO UPDATE
SET granted = 1, updated_at = :now

// Widerrufen — gleiches Muster
INSERT INTO consents (user_id, purpose, granted, created_at, updated_at)
VALUES (:user_id, :purpose, 0, :now, :now)
ON CONFLICT(user_id, purpose) DO UPDATE
SET granted = 0, updated_at = :now
```

UPSERT auf `UNIQUE(user_id, purpose)` ist atomar — verhindert Race Conditions, bei denen gleichzeitiges Erteilen+Widerrufen eine doppelte Zeile erstellen könnte.

---

## Kernmuster: Unveränderlicher Verlauf

```php
// Immer an den Verlauf anhängen — auch erneutes Erteilen wird aufgezeichnet
INSERT INTO consent_history (user_id, purpose, granted, created_at)
VALUES (:user_id, :purpose, 1, :now)
```

Der Verlauf wird **niemals aktualisiert** — er ist ein Prüfungsprotokoll jeder Einwilligungsänderung. Das ermöglicht Behörden zu überprüfen, wann die Einwilligung erteilt und wann sie widerrufen wurde.

---

## Kernmuster: Zweck-Validierung

```php
private function resolvePurpose(mixed $raw): ?string
{
    // VULN-G: Typverwechslung — muss ein String sein
    if (!is_string($raw)) {
        return null;
    }

    $len = strlen($raw);

    if ($len === 0 || $len > self::MAX_PURPOSE_LEN) {
        return null;
    }

    // VULN-I: ctype_alnum ist O(n) — kein Regex, kein ReDoS
    // VULN-D: nur alphanumerisch — kein HTML, keine SQL-Sonderzeichen
    if (!ctype_alnum($raw)) {
        return null;
    }

    return $raw;
}
```

`ctype_alnum()` akzeptiert nur `[a-zA-Z0-9]` — lehnt Leerzeichen, Bindestriche, SQL-Metazeichen und HTML-Tags in einem einzigen O(n)-Durchlauf ab.

---

## Kernmuster: Schutz vor Benutzer-Enumeration

```php
// VULN-F: leeres Array für unbekannten Benutzer zurückgeben — kein 404
public function listForUser(int $userId): array
{
    $stmt = $this->pdo->prepare(
        'SELECT ... FROM consents WHERE user_id = :user_id ORDER BY purpose ASC',
    );
    $stmt->execute(['user_id' => $userId]);

    return array_map(fn(array $r) => $this->hydrateConsent($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
}
```

Das Zurückgeben von 404 für einen unbekannten Benutzer gibt preis: "Diese user_id existiert nicht." Immer 200 mit leeren Daten zurückgeben.

---

## Kernmuster: IDOR-Prävention

```php
// VULN-B: alle Lese- und Schreibvorgänge werden auf den authentifizierten Benutzer begrenzt
// Auch wenn ein Angreifer X-User-Id: 999 sendet, sieht er nur Daten von Benutzer 999
WHERE user_id = :user_id AND purpose = :purpose
```

Keine benutzerübergreifende Abfrage berührt jemals den Datensatz eines anderen Benutzers.

---

## Kernmuster: Server-gesteuertes granted-Feld

```php
// VULN-C/E: granted wird vom Endpunkt gesteuert — niemals aus dem Body
// POST /consents → erteilt immer (granted = 1)
// DELETE /consents/{purpose} → widerruft immer (granted = 0)
// Body { "granted": false } bei POST wird stillschweigend ignoriert
```

Der Endpunkt selbst bestimmt den `granted`-Wert. Ein Body-Feld kann ihn niemals überschreiben.

---

## Antwort-Design

| Szenario | Status | Body |
|---|---|---|
| Erteilen erfolgreich | 201 | `{consent: {id, purpose, granted: true, updated_at}}` |
| Widerrufen erfolgreich | 200 | `{consent: {id, purpose, granted: false, updated_at}}` |
| Einwilligungen auflisten | 200 | `{data: [...], total: N}` |
| Verlauf | 200 | `{data: [{id, purpose, granted, created_at}, ...], total: N}` |
| Unbekannter Benutzer | 200 | `{data: [], total: 0}` — kein 404 |

`user_id` wird **niemals** in einer Antwort eingeschlossen — es ist implizit aus `X-User-Id`.

---

## VULN-A bis L — alle bestanden

| VULN | Angriff | Abwehr |
|---|---|---|
| A | SQL-Injection in X-User-Id | `ctype_digit()` + strlen > 18 Guard |
| B | IDOR — Einwilligungen eines anderen Benutzers manipulieren | Alle Abfragen mit `WHERE user_id = :user_id` |
| C | Mass Assignment (granted-Feld manipulieren) | granted wird vom Endpunkt bestimmt — Body nicht verwendet |
| D | XSS in purpose | `ctype_alnum()` — nur alphanumerisch |
| E | Direktes Überschreiben des Einwilligungsstatus | Erteilen/Widerrufen sind separate Endpunkte |
| F | Benutzer-Enumeration | Unbekannte user_id gibt leeres Array 200 zurück |
| G | Typverwechslung (purpose als int/array/null) | `is_string()` + `ctype_alnum()` |
| H | Einwilligungs-Replay | Verlauf ist nur-anhängend, erneutes Erteilen ist neuer Eintrag |
| I | ReDoS in purpose | `ctype_alnum()` O(n) |
| J | Integer-Überlauf in X-User-Id | strlen > 18 Guard |
| K | Gleichzeitige grant+withdraw Race Condition | `UNIQUE(user_id, purpose)` UPSERT-Atomarität |
| L | CRLF-Injection in Headern | PSR-7 lehnt auf HTTP-Ebene ab |

---

## Testergebnisse (FT189)

```
51 Tests / 142 Assertions — alle bestanden
PHPStan Level 8 — keine Fehler
PHP CS Fixer — sauber
VULN-A bis L — alle bestanden
```

Quelle: [`../NENE2-FT/consentlog/`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/consentlog)
