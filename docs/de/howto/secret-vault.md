# Anleitung: Persönlicher Secret-Vault API

Demonstriert benutzerspezifischen Key-Value-Speicher mit HMAC-Integrität, IDOR-Prävention und admin-exklusivem Metadatenzugriff.
Feldversuch: FT195 (`../NENE2-FT/vaultlog/`). Enthält VULN-A~L Sicherheitsaudit.

---

## Musterzusammenfassung

| Bereich | Ansatz |
|---|---|
| Benutzerisolation | `WHERE user_id = :uid` bei jeder Abfrage — IDOR unmöglich |
| Admin sieht nie Werte | Admin-Endpunkte geben nur `user_id + key` zurück |
| HMAC-Integrität | `HMAC-SHA256(userId|key|value, secret)` pro Eintrag gespeichert |
| Schlüssel-Validierung | `preg_match('/\A[a-z0-9_-]{1,64}\z/', $key)` — sicher, kein ReDoS-Risiko |
| User-ID-Validierung | `ctype_digit()` + Längenschutz + `> 0`-Prüfung |
| Admin-Key | `hash_equals()` zeitkonstant, fail-closed bei leerem Key |
| Upsert | `UNIQUE(user_id, key_name)` → erstmals speichern (201) oder aktualisieren (200) |

---

## Routen

| Methode | Pfad | Auth | Beschreibung |
|---|---|---|---|
| `POST` | `/vault` | `X-User-Id` | Secret speichern oder aktualisieren |
| `GET` | `/vault` | `X-User-Id` | Schlüsselliste des Benutzers auflisten (ohne Werte) |
| `GET` | `/vault/{key}` | `X-User-Id` | Secret-Wert des Benutzers abrufen |
| `DELETE` | `/vault/{key}` | `X-User-Id` | Secret des Benutzers löschen |
| `GET` | `/admin/vault` | `X-Admin-Key` | Alle Benutzer + Schlüssel auflisten (ohne Werte) |
| `GET` | `/admin/vault/{userId}` | `X-Admin-Key` | Schlüssel eines bestimmten Benutzers auflisten |

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

Die `UNIQUE(user_id, key_name)`-Bedingung erzwingt einen Eintrag pro (Benutzer, Schlüssel)-Paar.

---

## HMAC-Integrität

```php
private function computeHmac(int $userId, string $key, string $value): string
{
    return hash_hmac('sha256', "{$userId}|{$key}|{$value}", $this->hmacSecret);
}
```

Beim GET überprüft der Handler den gespeicherten HMAC:

```php
if (!$this->repo->verifyIntegrity($entry)) {
    return $this->problem(500, 'integrity-error', 'Secret integrity check failed.');
}
```

Dies erkennt direktes DB-Tampering (z. B. wenn ein kompromittierter DBA Werte direkt ändert, ohne die API zu nutzen).

---

## IDOR-Prävention

Jede Abfrage enthält `user_id = :uid`:

```sql
SELECT * FROM vault_entries WHERE user_id = :uid AND key_name = :key
```

Wenn Benutzer 200 den Schlüssel `private-key` von Benutzer 100 abfragt, erhält er 404 — identisch mit „nicht gefunden",
was die Enumeration verhindert, welche Schlüssel für andere Benutzer existieren.

Admin-Endpunkte geben nie `value` zurück:

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

## Schlüssel-Validierung

```php
private const string KEY_PATTERN = '/\A[a-z0-9_-]{1,64}\z/';
```

Die Anker `\A` und `\z` verhindern Teilübereinstimmungen. Die Zeichenklasse ist minimal:
Kleinbuchstaben alphanumerisch, Bindestrich, Unterstrich. Die Länge ist auf `{1,64}` begrenzt — kein Backtracking-Amplification.

Folgendes wird abgelehnt:
- Großbuchstaben (`UPPER_CASE`)
- Leerzeichen oder Sonderzeichen
- Path-Traversal-Fragmente (`../etc/passwd`)
- SQL-injizierbare Strings (`' OR '1'='1`)
- Leerer String oder Strings > 64 Zeichen

---

## User-ID-Validierung

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
- `> 0` lehnt `"0"` als ungültige User-ID ab

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
Der Handler ordnet diese HTTP-Statuscodes zu.

---

## VULN-A~L Ergebnisse

| Prüfung | Test | Ergebnis |
|---|---|---|
| VULN-A | SQL-Injection im Key-Parameter / Body | BESTANDEN — Schlüssel-Validierung lehnt vor der Abfrage ab |
| VULN-B | IDOR: Benutzer liest/löscht Schlüssel eines anderen Benutzers | BESTANDEN — 404 bei Cross-User-Zugriff |
| VULN-C | Liste gibt nur eigene Einträge zurück | BESTANDEN — WHERE user_id gescoped |
| VULN-D | Admin-Key Brute-Force / Bypass | BESTANDEN — hash_equals + fail-closed |
| VULN-E | XSS im Wert | BESTANDEN — als-is gespeichert, JSON-Antwort kein HTML |
| VULN-F | Key-Upsert-Idempotenz | BESTANDEN — letzter Schreibvorgang gewinnt, keine Duplikate |
| VULN-G | Path-Traversal im Schlüssel | BESTANDEN — Muster lehnt `..` und Schrägstriche ab |
| VULN-H | Negative / zero User-ID | BESTANDEN — ctype_digit + > 0-Schutz |
| VULN-I | Sehr große User-ID (Überlauf) | BESTANDEN — strlen > 18-Schutz |
| VULN-J | Null-Byte im Pfad | BESTANDEN — Router / Muster lehnt ab |
| VULN-K | Überlanger Schlüssel im Body | BESTANDEN — 422-Validierung |
| VULN-L | Leeres HMAC-Secret (kein Panic) | BESTANDEN — deterministisches HMAC mit leerem Key, kein Absturz |

---

## Testhinweise

- `AppFactory::create(?PDO, ?string adminKey, ?string hmacSecret)` — alles injizierbar für Unit-Tests.
- `withParsedBody($body)` ist in Test-Helfern erforderlich (Nyholm PSR-7 parst JSON nicht automatisch).
- IDOR-Tests: als Benutzer 100 speichern, Zugriff als Benutzer 200 versuchen → muss 404 zurückgeben.
- Admin-Tests: sicherstellen, dass `value`-Schlüssel in jedem Antwort-Array fehlt.
