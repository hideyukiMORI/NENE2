# Anleitung: Numerischen Verifizierungscode aufbauen

> **Muster bewiesen durch FT188 verifylog** — 6-stelliger SMS/E-Mail-Verifizierungscode mit Brute-Force-Schutz, zeitkonstantem Vergleich und Replay-Prävention. ATK-01~12 alle Pass.

---

## Was behandelt wird

Ein Kontaktverifizierungsablauf (E-Mail oder Telefon):

1. **Code anfordern** — Server generiert einen zufälligen 6-stelligen Code, liefert ihn außerhalb des Bandes
2. **Code einreichen** — Benutzer reicht den Code ein; max. 3 Versuche vor der Sperrung
3. **Statusprüfung** — Überprüfen, ob eine Verifizierung abgeschlossen wurde

Sicherheitsgarantien:

| Bedenken | Technik |
|---|---|
| Brute Force | 3 max. Versuche → 429 Gesperrt |
| Timing-Angriff | `hash_equals()` zeitkonstanter Vergleich |
| Code-Replay | Verifizierter Code gibt 410 Gone zurück |
| Benutzer-Enumeration | `POST /verifications` gibt immer 202 zurück |
| Mass Assignment | `code_hash/verified_at` nur serverseitig gesetzt |
| SQL-Injection | Nur-Integer-Pfadparameter (ctype_digit + strlen > 18 Guard) |
| Typverwechslung | `is_string()`-Prüfung vor `ctype_digit()` |
| ReDoS | `ctype_digit()` O(n) — kein Regex |

---

## Schema

```sql
CREATE TABLE verifications (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    contact        TEXT    NOT NULL,
    code_hash      TEXT    NOT NULL,   -- SHA-256 des 6-stelligen Codes
    attempts_count INTEGER NOT NULL DEFAULT 0,
    max_attempts   INTEGER NOT NULL DEFAULT 3,
    verified_at    TEXT,               -- NULL = ausstehend
    expires_at     TEXT    NOT NULL,
    created_at     TEXT    NOT NULL
);
```

`code_hash` speichert `hash('sha256', $code)` — niemals den Klartext-Code.

---

## API

| Methode | Pfad | Beschreibung |
|---|---|---|
| `POST` | `/verifications` | Code anfordern (immer 202) |
| `POST` | `/verifications/{id}/check` | Code einreichen (max. 3 Versuche) |
| `GET` | `/verifications/{id}` | Statusprüfung (kein Code offenbart) |

---

## Kernmuster: Code-Generierung und Hash-Speicherung

```php
// Kryptografisch zufälligen 6-stelligen Code generieren
$plainCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$codeHash  = hash('sha256', $plainCode);

// Hash speichern — NIEMALS den Klartext
INSERT INTO verifications (contact, code_hash, expires_at, created_at)
VALUES (:contact, :code_hash, :expires_at, :now)

// plainCode an Aufrufer zurückgeben (zur Zustellung) — niemals speichern oder protokollieren
return ['verification' => $v, 'plainCode' => $plainCode];
```

`random_int(0, 999999)` verwendet CSPRNG. `str_pad(..., 6, '0', STR_PAD_LEFT)` stellt führende Nullen sicher (z.B. `000042`).

---

## Kernmuster: Zeitkonstanter Vergleich

```php
// ATK-10: hash_equals verhindert Timing-Angriff
// $v->codeHash = gespeicherter SHA-256 aus DB
// $submittedCode = Benutzereingabe (6-stelliger String)
$valid = hash_equals($v->codeHash, hash('sha256', $submittedCode));
```

**Warum nicht `===`:** `===` schließt kurz beim ersten Mismatch — ein Angreifer kann Timing-Unterschiede zwischen "erster Byte falsch" und "alle Bytes falsch" messen, um den korrekten Code zeichenweise einzugrenzen. `hash_equals()` ist zeitkonstant unabhängig davon, wo der Mismatch auftritt.

---

## Kernmuster: Fail-First-Versuche-Zählung

```php
public function check(int $id, string $submittedCode): string
{
    $v = $this->fetchById($id);

    if ($v === null)        return 'not_found';
    if ($v->isVerified())   return 'already';   // ATK-11: Replay-Guard
    if ($v->isLocked())     return 'locked';    // ATK-05: Brute-Force-Guard
    if ($v->isExpired())    return 'expired';

    // VOR der Prüfung inkrementieren — verhindert Race-Exploitation
    UPDATE verifications SET attempts_count = attempts_count + 1 WHERE id = :id

    // ATK-10: zeitkonstanter Vergleich
    $valid = hash_equals($v->codeHash, hash('sha256', $submittedCode));

    if ($valid) {
        UPDATE verifications SET verified_at = :now WHERE id = :id
        return 'verified';
    }

    return 'wrong';
}
```

Das Inkrementieren der Versuche **vor** dem Vergleich stellt sicher, dass ein gleichzeitiger Race-Angriff zur Prüfung desselben Codes das Limit nicht umgehen kann.

---

## Kernmuster: Benutzer-Enumerations-Prävention

```php
// POST /verifications — gibt IMMER 202 zurück
// Auch wenn der Kontakt ungültig ist oder die Zustellung fehlschlägt
private function handleRequest(ServerRequestInterface $request): ResponseInterface
{
    $contact = V::str($body['contact'] ?? null, self::MAX_CONTACT_LEN);

    if ($contact === null || $contact === '') {
        return $this->responseFactory->create(['error' => '...'], 422); // nur für leer/null
    }

    // Zustellungserfolg oder -fehler ist für den Aufrufer unsichtbar
    $this->repository->create($contact);

    return $this->responseFactory->create(['id' => $v->id, 'expires_in' => 600], 202);
}
```

Ein 404 oder 422 für einen unbekannten Kontakt leckt "dieser Kontakt ist nicht registriert." Immer 202.

---

## Kernmuster: Code-Typ- und Formatvalidierung

```php
$raw = $body['code'] ?? null;

// ATK-07: Typverwechslung — Code muss ein String sein
if (!is_string($raw)) {
    return $this->responseFactory->create(['error' => 'code must be a 6-digit string.'], 422);
}

// ATK-09: ReDoS — ctype_digit ist O(n), kein Regex
// ATK-09: exakte Längenprüfung — nicht "mindestens 6"
if (!ctype_digit($raw) || strlen($raw) !== 6) {
    return $this->responseFactory->create(['error' => 'code must be exactly 6 digits.'], 422);
}
```

`is_string()` vor `ctype_digit()` lehnt JSON-Integer, Booleans und Arrays ab. `ctype_digit()` ist sicher vor ReDoS (lineare Zeit).

---

## Antwort-Design

| Szenario | Status | Body |
|---|---|---|
| Code korrekt | 200 | `{verified: true}` |
| Code falsch, Versuche verbleiben | 422 | `{error: "Incorrect code.", attempts_left: N}` |
| Maximale Versuche erreicht | 429 | `{error: "Too many failed attempts. Request a new code."}` |
| Bereits verifiziert (Replay) | 410 | `{error: "This verification has already been completed."}` |
| Abgelaufen | 410 | `{error: "Verification has expired. Request a new code."}` |
| Nicht gefunden | 404 | `{error: "Verification not found."}` |

---

## ATK-01~12 alle Pass

| ATK | Angriff | Verteidigung |
|---|---|---|
| 01 | SQL-Injection in `{id}` | `ctype_digit()` + strlen > 18 Guard |
| 02 | IDOR — Check mit fremder Verification-ID | Gleiche 404 — kein Eigentumsindikator |
| 03 | Mass Assignment (code_hash/verified_at aus Body) | Nur serverseitig gesetzt |
| 04 | XSS im Kontakt | Nur JSON-Ausgabe — kein HTML-Rendering; Kontakt nicht in Antwort |
| 05 | Brute Force 6-stelliger Code | 3 Fehlschläge → 429 Gesperrt |
| 06 | Auth-Bypass | verified_at nur serverseitig gesetzt |
| 07 | Typverwechslung (Code als int/bool/array) | `is_string()` + `ctype_digit()` |
| 08 | Integer-Overflow in `{id}` | strlen > 18 Guard |
| 09 | ReDoS-artige Code-Eingabe | `ctype_digit()` O(n) |
| 10 | Timing-Angriff auf Code-Vergleich | `hash_equals()` konstante Zeit |
| 11 | Code-Replay nach Erfolg | 410 Gone |
| 12 | CRLF-Injection in Headern | PSR-7 lehnt auf HTTP-Ebene ab |

---

## Testergebnisse (FT188)

```
48 Tests / 103 Assertions — alle PASS
PHPStan Level 8 — keine Fehler
PHP CS Fixer — sauber
ATK-01~12 alle Pass
```

Quelle: [`../NENE2-FT/verifylog/`](https://github.com/hideyukiMORI/NENE2-examples/tree/main/verifylog)
