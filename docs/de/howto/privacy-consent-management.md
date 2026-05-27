# Datenschutz-Einwilligungsverwaltung implementieren

> **Muster erprobt in FT189 consentlog** — DSGVO-konformes Einwilligungs-Tracking mit unveränderlicher Historie, IDOR-Prävention und Benutzer-Enumerationsschutz. VULN-A〜L alle bestanden.

---

## Was diese Anleitung abdeckt

Ein Datenschutz-Einwilligungsverwaltungsablauf:

1. **Einwilligung erteilen** — Benutzer erteilt Einwilligung für einen benannten Zweck
2. **Einwilligung widerrufen** — Benutzer widerruft die Einwilligung
3. **Einwilligungen auflisten** — aktueller Einwilligungsstatus für alle Zwecke
4. **Historie** — unveränderliches, append-only-Prüfprotokoll pro Zweck

Sicherheitsgarantien:

| Bedrohung | Technik |
|---|---|
| IDOR — Einwilligungen anderer Benutzer | Alle Abfragen mit `WHERE user_id = :user_id` |
| Mass Assignment (granted-Feld) | `granted` wird serverseitig bestimmt; Body kann nicht überschreiben |
| SQL-Injection im Zweck | `ctype_alnum()` — nur alphanumerisch |
| ReDoS im Zweck | `ctype_alnum()` O(n) — kein Regex |
| Typverwechslung | `is_string()` vor `ctype_alnum()` |
| Benutzer-Enumeration | Unbekannter Benutzer gibt leeres Array zurück, kein 404 |
| Race Condition bei grant/withdraw | UPSERT-Atomarität auf `UNIQUE(user_id, purpose)` |
| Einwilligungs-Replay | Historie ist append-only; jede Änderung ist ein neuer Eintrag |

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

`UNIQUE(user_id, purpose)` ermöglicht atomares Upsert. `consent_history` ist append-only — wird nie aktualisiert.

---

## API

| Methode | Pfad | Header | Beschreibung |
|---|---|---|---|
| `POST` | `/consents` | `X-User-Id` | Einwilligung erteilen (201) |
| `DELETE` | `/consents/{purpose}` | `X-User-Id` | Einwilligung widerrufen (200) |
| `GET` | `/consents` | `X-User-Id` | Aktuelle Einwilligungen auflisten |
| `GET` | `/consents/{purpose}/history` | `X-User-Id` | Prüfhistorie (append-only) |

---

## Kernmuster: Idempotentes UPSERT

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

UPSERT auf `UNIQUE(user_id, purpose)` ist atomar — verhindert Race Conditions, bei denen gleichzeitige grant+withdraw-Aufrufe eine doppelte Zeile erstellen könnten.

---

## Kernmuster: Unveränderliche Historie

```php
// Immer an die Historie anhängen — auch erneutes Erteilen wird aufgezeichnet
INSERT INTO consent_history (user_id, purpose, granted, created_at)
VALUES (:user_id, :purpose, 1, :now)
```

Die Historie wird **nie aktualisiert** — sie ist ein Prüfprotokoll jeder Einwilligungsänderung. Dies ermöglicht Regulierungsbehörden zu überprüfen, wann die Einwilligung erteilt und wann sie widerrufen wurde.

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

## Kernmuster: Benutzer-Enumerationsschutz

```php
// VULN-F: für unbekannte Benutzer leeres Array zurückgeben — kein 404
public function listForUser(int $userId): array
{
    $stmt = $this->pdo->prepare(
        'SELECT ... FROM consents WHERE user_id = :user_id ORDER BY purpose ASC',
    );
    $stmt->execute(['user_id' => $userId]);

    return array_map(fn(array $r) => $this->hydrateConsent($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
}
```

Eine 404-Antwort für unbekannte Benutzer gibt preis: „Diese user_id existiert nicht." Geben Sie immer 200 mit leeren Daten zurück.

---

## Kernmuster: IDOR-Prävention

```php
// VULN-B: alle Lese- und Schreibvorgänge auf den authentifizierten Benutzer beschränken
// Selbst wenn ein Angreifer X-User-Id: 999 sendet, sieht er nur die Daten von Benutzer 999
WHERE user_id = :user_id AND purpose = :purpose
```

Keine benutzerübergreifende Abfrage greift jemals auf den Datensatz eines anderen Benutzers zu.

---

## Kernmuster: Serverseitig gesteuertes granted-Feld

```php
// VULN-C/E: granted wird vom Endpunkt gesteuert — niemals aus dem Body
// POST /consents → erteilt immer (granted = 1)
// DELETE /consents/{purpose} → widerruft immer (granted = 0)
// Body { "granted": false } bei POST wird stillschweigend ignoriert
```

Der Endpunkt selbst bestimmt den `granted`-Wert. Ein Body-Feld kann ihn nie überschreiben.

---

## Antwort-Design

| Szenario | Status | Body |
|---|---|---|
| Erteilen erfolgreich | 201 | `{consent: {id, purpose, granted: true, updated_at}}` |
| Widerrufen erfolgreich | 200 | `{consent: {id, purpose, granted: false, updated_at}}` |
| Einwilligungen auflisten | 200 | `{data: [...], total: N}` |
| Historie | 200 | `{data: [{id, purpose, granted, created_at}, ...], total: N}` |
| Unbekannter Benutzer | 200 | `{data: [], total: 0}` — kein 404 |

`user_id` wird **nie** in einer Antwort eingeschlossen — sie ist implizit aus `X-User-Id`.

---

## VULN-A〜L alle bestanden

| VULN | Angriff | Abwehr |
|---|---|---|
| A | SQL-Injection in X-User-Id | `ctype_digit()` + strlen > 18 Prüfung |
| B | IDOR — Einwilligung anderer Benutzer manipulieren | Alle Abfragen mit `WHERE user_id = :user_id` |
| C | Mass Assignment (granted-Feld manipulieren) | granted wird vom Endpunkt bestimmt — Body nicht verwendet |
| D | XSS im Zweck | `ctype_alnum()` — nur alphanumerisch |
| E | Direkte Überschreibung des Einwilligungsstatus | grant/withdraw sind getrennte Endpunkte |
| F | Benutzer-Enumeration | Unbekannte user_id gibt leeres Array 200 zurück |
| G | Typverwechslung (purpose als int/array/null) | `is_string()` + `ctype_alnum()` |
| H | Einwilligungs-Replay | Historie ist append-only, erneutes Erteilen ist neuer Eintrag |
| I | ReDoS im Zweck | `ctype_alnum()` O(n) |
| J | Integer-Überlauf in X-User-Id | strlen > 18 Prüfung |
| K | Gleichzeitige grant+withdraw Race Condition | `UNIQUE(user_id, purpose)` UPSERT-Atomarität |
| L | CRLF-Injection in Headers | PSR-7 lehnt auf HTTP-Ebene ab |

---

## Testergebnisse (FT189)

```
51 Tests / 142 Assertions — alle BESTANDEN
PHPStan Level 8 — keine Fehler
PHP CS Fixer — sauber
VULN-A〜L alle bestanden
```

Quelle: [`../NENE2-FT/consentlog/`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/consentlog)
