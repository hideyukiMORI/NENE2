# How-to: Signierte URLs für sichere Downloads

> **FT-Referenz**: FT338 (`NENE2-FT/signedlog`) — HMAC-SHA256-signierte URL-Generierung mit TTL,
> Manipulationserkennung (401), Ablauf (410 Gone), ressourcengebundene Tokens und Ablehnung falscher
> Secrets; 16 Tests / 40+ Assertions PASS.

Diese Anleitung zeigt, wie zeitlich begrenzte signierte URLs generiert werden, die einen nicht-authentifizierten
Download privater Dateien ermöglichen — ohne langlebige Anmeldedaten preiszugeben.

## Schema

```sql
CREATE TABLE files (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    owner_id   INTEGER NOT NULL,
    mime_type  TEXT    NOT NULL DEFAULT 'application/octet-stream',
    created_at TEXT    NOT NULL
);
```

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|-------------|
| `POST`  | `/files` | Dateidatensatz registrieren |
| `POST`  | `/files/{id}/sign` | Signierte Download-URL generieren |
| `GET`   | `/download?token=...` | Mittels signiertem Token herunterladen |

## Datei registrieren

```php
POST /files
{"name": "report.pdf", "owner_id": 1}
→ 201
{
  "id": 1,
  "name": "report.pdf",
  "owner_id": 1,
  "mime_type": "application/octet-stream",
  "created_at": "..."
}

// Benutzerdefinierter MIME-Typ
POST /files  {"name": "image.png", "owner_id": 2, "mime_type": "image/png"}
→ 201  {"mime_type": "image/png", ...}

// Validierung
POST /files  {"owner_id": 1}     → 422  // name erforderlich
POST /files  {"name": "f.pdf"}   → 422  // owner_id erforderlich
```

## Signierte URL generieren

```php
POST /files/1/sign
{"ttl_seconds": 300}
→ 200
{
  "token": "1|2026-05-27 09:05:00|a3f9e2...",
  "expires_at": "2026-05-27T09:05:00Z",
  "url": "/download?token=1%7C2026-05-27+09%3A05%3A00%7Ca3f9e2...",
  "ttl_seconds": 300
}

// Standard-TTL = 3600 (1 Stunde) wenn weggelassen
POST /files/1/sign  {}
→ 200  {"ttl_seconds": 3600}

// Unbekannte Datei
POST /files/999/sign  {"ttl_seconds": 60}
→ 404
```

## Herunterladen mit Token

```php
GET /download?token=1|2026-05-27+09:05:00|a3f9e2...
→ 200  {"id": 1, "name": "report.pdf", "mime_type": "application/octet-stream"}

// Fehlendes Token
GET /download
→ 401

// Manipuliertes Token (letzte 4 Zeichen geändert)
GET /download?token=1|2026-05-27+09:05:00|XXXX
→ 401

// Abgelaufenes Token (expires_at in der Vergangenheit)
GET /download?token=1|2020-01-01+00:00:00|...valid_hmac...
→ 410 Gone

// Zufälliger Unsinn
GET /download?token=totally-invalid-garbage
→ 401
```

**410 Gone** (nicht 401) für abgelaufene Tokens: die URL existierte und war gültig — sie ist lediglich
abgelaufen. So können Clients zwischen „niemals gültig" und „einmal gültig, jetzt veraltet" unterscheiden.

## Token-Format — HMAC-SHA256

```
token = "{file_id}|{expires_at}|{hmac}"

hmac = HMAC-SHA256(key=server_secret, message="{file_id}|{expires_at}")
```

```php
class HmacSigner
{
    public function __construct(private readonly string $secret)
    {
    }

    public function sign(int $fileId, string $expiresAt): string
    {
        $payload = "{$fileId}|{$expiresAt}";
        $hmac    = hash_hmac('sha256', $payload, $this->secret);
        return "{$payload}|{$hmac}";
    }

    public function verify(string $token, string $now): ?int
    {
        $parts = explode('|', $token, 3);
        if (count($parts) !== 3) {
            return null;
        }

        [$fileIdStr, $expiresAt, $receivedHmac] = $parts;
        $fileId  = (int) $fileIdStr;
        $payload = "{$fileId}|{$expiresAt}";

        // Zeitkonstanter Vergleich
        $expected = hash_hmac('sha256', $payload, $this->secret);
        if (!hash_equals($expected, $receivedHmac)) {
            return null;  // manipuliert oder falsches Secret
        }

        // Ablauf NACH HMAC-Verifizierung prüfen
        if ($expiresAt < $now) {
            return -1;  // abgelaufen — Aufrufer gibt 410 zurück
        }

        return $fileId;
    }
}
```

**Kritische Reihenfolge**: den HMAC immer vor dem Ablauf prüfen. Wenn zuerst der Ablauf bei einem
ungültigen Token geprüft wird, können Angreifer das Ablaufverhalten erkunden.

### Ressourcen-Bindung

Jedes Token kodiert die `file_id`. Tokens für verschiedene Dateien erzeugen unterschiedliche HMAC-Digests:

```php
$token1 = $signer->sign(1, $future);
$token2 = $signer->sign(2, $future);
// $token1 !== $token2 — Token von Datei-1 kann nicht für Datei-2 wiederverwendet werden
```

### Falsches Secret

Ein Token, das mit einem anderen Secret signiert wurde, gibt `null` bei `verify()` zurück:

```php
$otherSigner = new HmacSigner('different-secret');
$token = $otherSigner->sign(1, $future);
$signer->verify($token, $now);  // null — HMAC-Mismatch
```

---

## Was NOT zu tun ist

| Anti-Muster | Risiko |
|---|---|
| `===` statt `hash_equals()` für HMAC-Vergleich verwenden | Timing-Angriff gibt HMAC Byte für Byte preis |
| Ablauf vor HMAC-Verifizierung prüfen | Angreifer erkundet den Ablauf bei gefälschten Tokens, um die Serveruhr zu erfahren |
| Nur `user_id` im Token-Payload einschließen, nicht `file_id` | Token für Benutzer-1-Datei-1 ist für Benutzer-1-Datei-2 wiederverwendbar |
| `md5()` oder `sha1()` statt HMAC-SHA256 verwenden | Keyed Hash erforderlich; unkeyed Hash ist trivial fälschbar |
| 401 für abgelaufene Tokens zurückgeben | 410 teilt dem Client mit: „Token war echt, aber veraltet"; ermöglicht korrekten Neu-Signierungsflow |
| Token-Wert in Zugriffsprotokollen loggen | Token gewährt Zugriff — wie ein Passwort behandeln; in Protokollen maskieren oder weglassen |
| Schwaches oder vorhersehbares Secret verwenden | Key muss mindestens 32 zufällige Bytes haben; niemals aus Zeitstempel oder Hostname ableiten |
