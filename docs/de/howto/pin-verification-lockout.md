# PIN-Verifizierung und Sperrung

> **FT-Referenz**: FT252 (`NENE2-FT/pinverifylog`) — PIN-Verifizierung mit Sperrung
> **ATK**: FT252 — Cracker-Mindset-Angriffstest (ATK-01 bis ATK-12)

Implementierungsanleitung für Brute-Force-Prävention bei 6-stelligen PINs, Timing-Angriffs-Gegenmaßnahmen und Administrator-Entsperrung.
Erklärt HMAC-SHA256-Hash-Speicherung, zeitkonstanten Vergleich und Sperrung nach Versuchen.

**FT192 Sicherheit validiert**: VULN-A~L alle PASS / ATK-01~12 alle PASS.

## Übersicht

- Administrator erstellt PIN (HMAC-SHA256-Hash-Speicherung — kein Klartext gespeichert)
- Benutzer verifiziert PIN (Sperrung nach Erreichen der maximalen Fehleranzahl)
- Administrator entsperrt
- Versuchshistorie wird als Audit-Log aufgezeichnet

## Endpunkte

| Methode | Pfad | Auth | Beschreibung |
|---|---|---|---|
| `POST` | `/pins` | `X-Admin-Key` | PIN erstellen |
| `POST` | `/pins/{id}/verify` | — | PIN verifizieren |
| `GET` | `/pins/{id}` | `X-Admin-Key` | Status prüfen (verbleibende Versuche, Sperrdauer) |
| `POST` | `/pins/{id}/unlock` | `X-Admin-Key` | Entsperren |
| `DELETE` | `/pins/{id}` | `X-Admin-Key` | PIN löschen |

## Datenbankdesign

```sql
CREATE TABLE IF NOT EXISTS pins (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    label        TEXT    NOT NULL,
    pin_hash     TEXT    NOT NULL,        -- HMAC-SHA256(pin, secret)
    attempts     INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 5,
    locked_until TEXT,                    -- ISO 8601 UTC, NULL = entsperrt
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS pin_attempts (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    pin_id       INTEGER NOT NULL,
    success      INTEGER NOT NULL DEFAULT 0,
    attempted_at TEXT    NOT NULL
);
```

Position: `locked_until` als ISO-8601-String speichern und Sperrstatus durch String-Vergleich mit der aktuellen Zeit (`$lockedUntil > $now`) bestimmen. Kein Konvertierungsaufwand.

## HMAC-SHA256-PIN-Hash

PIN nicht im Klartext speichern, sondern mit HMAC-SHA256 hashen. Das Einmischen eines serverseitigen Geheimnisses (`$hmacSecret`) macht Brute-Force bei einem DB-Kompromiss schwieriger:

```php
private function hashPin(string $pin): string
{
    return hash_hmac('sha256', $pin, $this->hmacSecret);
}
```

## Zeitkonstanter Vergleich (VULN-E / ATK-02)

`===` bricht beim Byte-Vergleich ab, was es ermöglicht, den korrekten Hash durch Timing-Angriffe zu erraten. `hash_equals()` vergleicht immer alle Bytes:

```php
// ❌ Gefährlich: per Timing-Angriff erratbar
if ($stored === $provided) { ... }

// ✅ Sicher: zeitkonstanter Vergleich
$provided = $this->hashPin($pin);
$success  = hash_equals($pin1->pinHash, $provided);
```

## Brute-Force-Prävention (ATK-01)

Wenn die Fehleranzahl `max_attempts` erreicht, `locked_until` setzen und alle nachfolgenden Versuche (einschließlich der korrekten PIN) mit 423 ablehnen:

```php
public function verify(int $id, string $pin): string
{
    $now  = $this->now();
    $pin1 = $this->findById($id);

    // 1. Sperrprüfung vor dem Versuch durchführen
    if ($pin1->isLocked($now)) {
        return 'locked'; // → 423
    }

    // 2. Zeitkonstanter Vergleich
    $provided = $this->hashPin($pin);
    $success  = hash_equals($pin1->pinHash, $provided);

    if ($success) {
        // Bei Erfolg Versuchsanzahl zurücksetzen
        $this->resetAttempts($id, $now);
        return 'success'; // → 200
    }

    // 3. Fehler: Hochzählen → Sperren bei Limit
    $newAttempts = $pin1->attempts + 1;
    $lockedUntil = null;

    if ($newAttempts >= $pin1->maxAttempts) {
        $lockedUntil = $this->lockUntil($now); // 5 Minuten später
    }

    $this->incrementAttempts($id, $newAttempts, $lockedUntil, $now);

    return $newAttempts >= $pin1->maxAttempts ? 'locked' : 'wrong'; // → 423 oder 401
}
```

**Wichtig**: Sperrprüfung vor dem Versuch durchführen. Prüfung nach dem Versuch könnte ermöglichen, dass der letzte Versuch, der die Sperre auslöst, durchkommt.

## Administrator-Key fail-closed (VULN-H / ATK-03)

```php
private function isAdmin(ServerRequestInterface $request): bool
{
    if ($this->adminKey === '') {
        return false; // leerer adminKey immer abgelehnt
    }

    $provided = $request->getHeaderLine('X-Admin-Key');

    return $provided !== '' && hash_equals($this->adminKey, $provided);
}
```

Wenn `adminKey` ein leerer String ist, wird bedingungslos `false` zurückgegeben (verhindert, dass ein nicht gesetzter Umgebungsvariable eine offene Admin-Berechtigung verursacht).

## ID-Validierung (VULN-A / ATK-07)

```php
private function resolveId(ServerRequestInterface $request): ?int
{
    $raw = Router::param($request, 'id');

    if ($raw === null || !ctype_digit($raw) || strlen($raw) > 18) {
        return null; // → 422
    }

    $id = (int) $raw;

    return $id > 0 ? $id : null;
}
```

`strlen($raw) > 18` verhindert 64-Bit-Integer-Überlauf (`PHP_INT_MAX` hat 19 Stellen, mit Sicherheitspuffer).

## PIN-Validierung (VULN-D)

`ctype_digit()` verwenden. Reguläre Ausdrücke (`/^[0-9]+$/`) haben ReDoS-Potenzial (O(n²)), während `ctype_digit()` O(n) und sicher ist:

```php
private function validatePin(mixed $pin): ?string
{
    if (!is_string($pin)) {
        return 'pin must be a string.'; // VULN-G: Typverwechslungs-Prävention
    }

    $len = strlen($pin);
    if ($len < self::MIN_PIN_LEN || $len > self::MAX_PIN_LEN) {
        return 'pin must be between 4 and 8 digits.';
    }

    if (!ctype_digit($pin)) { // O(n), kein ReDoS
        return 'pin must contain only digits.';
    }

    return null;
}
```

## Antwort-Design

**PIN-Hash niemals in Antworten einschließen.** Gilt auch für Administratorantworten:

```php
public function toAdminArray(): array
{
    return [
        'id'                 => $this->id,
        'label'              => $this->label,
        'attempts'           => $this->attempts,
        'max_attempts'       => $this->maxAttempts,
        'locked_until'       => $this->lockedUntil,
        'remaining_attempts' => $this->remainingAttempts(),
        'created_at'         => $this->createdAt,
        // pin_hash nicht einschließen
        // updated_at nicht als interne Information einschließen
    ];
}
```

## Antwort-Beispiele

```json
// POST /pins (201)
{
    "pin": {
        "id": 1,
        "label": "vault",
        "attempts": 0,
        "max_attempts": 5,
        "locked_until": null,
        "remaining_attempts": 5,
        "created_at": "2026-05-26T10:00:00+00:00"
    }
}

// POST /pins/1/verify — Erfolg (200)
{ "success": true, "locked": false }

// POST /pins/1/verify — Fehler (401)
{ "success": false, "locked": false }

// POST /pins/1/verify — Gesperrt (423)
{ "success": false, "locked": true, "error": "PIN is locked due to too many failed attempts." }

// POST /pins/1/unlock (200)
{ "unlocked": true }
```

## Sicherheitspunkte (VULN-A~L / ATK-01~12 alle PASS)

| Bedrohung | Kategorie | Gegenmaßnahme |
|---|---|---|
| Brute-Force | ATK-01 | `max_attempts`-Limit → `locked_until` 5-Minuten-Sperre |
| Timing-Angriff (PIN) | ATK-02 / VULN-E | `hash_equals()` zeitkonstanter Vergleich |
| Administrator-Key-Bypass | ATK-03 / VULN-H | `adminKey = ''` → false (fail-closed) |
| ID-Enumeration | ATK-04 | Nicht existierende ID gibt 404 zurück (kein Informationsleck) |
| SQL-Injection (PIN-Wert) | ATK-05 / VULN-B | `ctype_digit` lässt nur Ziffern durch → PDO Prepared Statement |
| SQL-Injection (ID) | ATK-06 / VULN-B | `ctype_digit + strlen > 18` Schutz → 422 |
| Integer-Überlauf | ATK-07 / VULN-A / VULN-J | `strlen > 18` Schutz |
| Sperrumgehung | ATK-08 | Sperrprüfung vor Versuch, DB-Persistenz |
| Wiederangriff nach Entsperren | ATK-09 | Nach unlock attempts = 0 zurückgesetzt (Normal-Verhalten) |
| Body-Injection | ATK-10 / VULN-I | Nur explizite Felder akzeptiert |
| Administrator-Key-Timing | ATK-11 | `hash_equals()` zeitkonstanter Vergleich |
| BIDI/Unicode-Label | ATK-12 / VULN-L | `mb_strlen` für Längsprüfung, PDO-Speicherung sicher |
| ReDoS | VULN-D | `ctype_digit()` O(n), kein Regex |
| Typverwechslung | VULN-G | `!is_string($pin)` Prüfung |
| max_attempts-Überlauf | VULN-F | Bereichsprüfung 1~20 |
| SSRF | VULN-K | Keine externe HTTP-Kommunikation (N/A) |
| Pfad-Traversal | VULN-C | Keine Dateioperationen (N/A) |

## Verwandte Anleitungen

- [Account-Sperrung](account-lockout.md) — Per-Account-Fehleranzahl, 423-Design
- [OTP-Authentifizierungssystem](otp-authentication.md) — Ähnliches Sperrmuster (nur neuestes OTP gültig)
- [Webhook-Signaturverifizierung](webhook-signature.md) — `hash_equals()`-Muster
- [Numerischer Verifizierungscode](numeric-verification-code.md) — 6-stelliger Code-Generierungs- und Verifizierungsablauf
