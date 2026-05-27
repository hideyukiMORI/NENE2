# How-to: Persönliche Geheimnis-Tresor-API

Demonstriert benutzerspezifischen Key-Value-Speicher mit HMAC-Integrität, IDOR-Prävention und nur-Admin-Metadatenzugriff.
Feldversuch: FT195 (`../NENE2-FT/vaultlog/`). Enthält VULN-A bis L Sicherheitsprüfung.

---

## Muster-Zusammenfassung

| Problem | Ansatz |
|---|---|
| Benutzerisolation | `WHERE user_id = :uid` bei jeder Abfrage — IDOR unmöglich |
| Admin sieht niemals Werte | Admin-Endpunkte geben nur `user_id + key` zurück |
| HMAC-Integrität | `HMAC-SHA256(userId|key|value, secret)` wird pro Eintrag gespeichert |
| Key-Validierung | `preg_match('/\A[a-z0-9_-]{1,64}\z/', $key)` — sicher, kein ReDoS-Risiko |
| Benutzer-ID-Validierung | `ctype_digit()` + Längenguard + `> 0`-Prüfung |
| Admin-Key | `hash_equals()` zeitkonstant, fail-closed bei leerem Key |
| Upsert | `UNIQUE(user_id, key_name)` → erste Speicherung (201) oder Aktualisierung (200) |

---

## Routen

| Methode | Pfad | Auth | Beschreibung |
|---|---|---|---|
| `POST` | `/vault` | `X-User-Id` | Geheimnis speichern oder aktualisieren |
| `GET` | `/vault` | `X-User-Id` | Geheimnis-Keys des Benutzers auflisten (keine Werte) |
| `GET` | `/vault/{key}` | `X-User-Id` | Geheimnis-Wert des Benutzers abrufen |
| `DELETE` | `/vault/{key}` | `X-User-Id` | Geheimnis des Benutzers löschen |
| `GET` | `/admin/vault` | `X-Admin-Key` | Alle Benutzer + Keys auflisten (keine Werte) |
| `GET` | `/admin/vault/{userId}` | `X-Admin-Key` | Keys eines bestimmten Benutzers auflisten |

---

## Datenbankschema

```sql
CREATE TABLE vault_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    key_name   TEXT    NOT NULL,
    value      TEXT    NOT NULL,
    hmac       TEXT    NOT NULL,   -- HMAC-SHA256-Integritäts-Tag
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    UNIQUE (user_id, key_name)
);
```

Der `UNIQUE(user_id, key_name)`-Constraint erzwingt einen Eintrag pro (Benutzer, Key)-Paar.

---

## HMAC-Integrität

```php
private function computeHmac(int $userId, string $key, string $value): string
{
    return hash_hmac('sha256', "{$userId}|{$key}|{$value}", $this->hmacSecret);
}
```

Bei GET verifiziert der Handler den gespeicherten HMAC:

```php
if (!$this->repo->verifyIntegrity($entry)) {
    return $this->problem(500, 'integrity-error', 'Geheimnis-Integritätsprüfung fehlgeschlagen.');
}
```

Das erkennt direkte DB-Manipulation (z.B. ein kompromittierter DBA, der Werte ohne die API ändert).

---

## IDOR-Prävention

Jede Abfrage enthält `user_id = :uid`:

```sql
SELECT * FROM vault_entries WHERE user_id = :uid AND key_name = :key
```

Benutzer 200, der Key `private-key` von Benutzer 100 abfragt, erhält 404 — identisch mit "nicht gefunden",
verhindert die Enumeration welche Keys für andere Benutzer existieren.

Admin-Endpunkte geben niemals `value` zurück:

```php
// Benutzer sieht seinen eigenen Wert
public function toUserArray(): array
{
    return ['key' => ..., 'value' => $this->value, ...];
}

// Admin sieht nur Metadaten — keinen Wert
public function toAdminArray(): array
{
    return ['user_id' => ..., 'key' => ..., ...];
}
```

---

## Key-Validierung

```php
private const string KEY_PATTERN = '/\A[a-z0-9_-]{1,64}\z/';
```

`\A`- und `\z`-Anker verhindern Teilübereinstimmungen. Die Zeichenklasse ist minimal:
Kleinbuchstaben alphanumerisch, Bindestrich, Unterstrich. Länge ist begrenzt `{1,64}` — keine Backtracking-Amplifikation.

Das lehnt ab:
- Großbuchstaben (`UPPER_CASE`)
- Leerzeichen oder Sonderzeichen
- Path-Traversal-Fragmente (`../etc/passwd`)
- SQL-injizierbare Strings (`' OR '1'='1`)
- Leerer String oder Strings > 64 Zeichen

---

## Benutzer-ID-Validierung

```php
private function resolveUserId(ServerRequestInterface $request): ?int
{
    $raw = $request->getHeaderLine('X-User-Id');
    if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 18) return null;
    $id = (int) $raw;
    return $id > 0 ? $id : null;
}
```

- `ctype_digit()` lehnt negative Zahlen ab (das `-`-Zeichen ist keine Ziffer)
- `strlen > 18` verhindert Integer-Überlauf (`PHP_INT_MAX` hat 19 Ziffern)
- `> 0` lehnt `"0"` als ungültige Benutzer-ID ab

---

## Upsert-Muster

```php
public function store(int $userId, string $key, string $value): string
{
    $existing = $this->findEntry($userId, $key);
    if ($existing !== null) {
        // UPDATE ...
        return 'updated';  // → 200
    }
    // INSERT ...
    return 'stored';  // → 201
}
```

Gibt `'stored'` (201) beim ersten Schreiben zurück, `'updated'` (200) beim Überschreiben.
Der Handler ordnet diese den HTTP-Statuscodes zu.

---

## VULN-A bis L Ergebnisse

| Prüfung | Test | Ergebnis |
|---|---|---|
| VULN-A | SQL-Injection in Key-Param / Body | BESTANDEN — Key-Validierung lehnt vor Abfrage ab |
| VULN-B | IDOR: Benutzer liest/löscht Key eines anderen | BESTANDEN — 404 bei benutzerübergreifendem Zugriff |
| VULN-C | Liste gibt nur eigene Einträge zurück | BESTANDEN — WHERE user_id begrenzt |
| VULN-D | Admin-Key Brute-Force / Bypass | BESTANDEN — hash_equals + fail-closed |
| VULN-E | XSS im Wert | BESTANDEN — als-ist gespeichert, JSON-Antwort kein HTML |
| VULN-F | Key-Upsert-Idempotenz | BESTANDEN — letzter Schreibvorgang gewinnt, keine Duplikate |
| VULN-G | Path-Traversal im Key | BESTANDEN — Muster lehnt `..` und Schrägstriche ab |
| VULN-H | Negative / null Benutzer-ID | BESTANDEN — ctype_digit + > 0 Guard |
| VULN-I | Sehr große Benutzer-ID (Überlauf) | BESTANDEN — strlen > 18 Guard |
| VULN-J | Null-Byte im Pfad | BESTANDEN — Router / Muster lehnen ab |
| VULN-K | Überlanger Key im Body | BESTANDEN — 422 Validierung |
| VULN-L | Leeres HMAC-Secret (kein Absturz) | BESTANDEN — deterministischer HMAC mit leerem Key, kein Absturz |

---

## Test-Hinweise

- `AppFactory::create(?PDO, ?string adminKey, ?string hmacSecret)` — alle injizierbar für Unit-Tests.
- `withParsedBody($body)` ist in Test-Helfern erforderlich (Nyholm PSR-7 parst JSON nicht automatisch).
- IDOR-Tests: als Benutzer 100 speichern, Zugriff als Benutzer 200 versuchen → muss 404 erhalten.
- Admin-Tests: überprüfen, dass `value`-Key in jedem Antwort-Array fehlt.
