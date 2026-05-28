# Anleitung: URL-Verkürzer mit SSRF-Prävention

> **FT-Referenz**: FT337 (`NENE2-FT/shortlog`) — URL-Verkürzer mit SSRF-Blocking (private IPs, Loopback, Link-Local, gefährliche Schemata), Slug-Validierung, Mass-Assignment-Prävention, ISO 8601-Datumsvalidierung, ReDoS-sichere Limit-Parsing, 50+ Tests BESTANDEN.

Diese Anleitung zeigt, wie ein URL-Verkürzer gebaut wird, der nur sichere öffentliche URLs akzeptiert, Slugs validiert, Mass Assignment verhindert und vor Server-Side Request Forgery (SSRF) schützt.

## Schema

```sql
CREATE TABLE links (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    slug         TEXT    NOT NULL UNIQUE,
    original_url TEXT    NOT NULL,
    expires_at   TEXT,               -- ISO 8601, nullable
    click_count  INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT    NOT NULL
);
```

## Endpunkte

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| `POST` | `/links` | Kurzlink erstellen |
| `GET`  | `/links` | Eigene Links auflisten |
| `GET`  | `/links/{slug}` | Link nach Slug abrufen |
| `DELETE` | `/links/{slug}` | Eigenen Link löschen |

## Kurzlink erstellen

```php
POST /links
X-User-Id: 1
{
  "original_url": "https://example.com/very/long/path",
  "slug": "my-link",
  "expires_at": "2030-12-31T23:59:59+09:00"
}
→ 201
{
  "id": 1,
  "user_id": 1,
  "slug": "my-link",
  "original_url": "https://example.com/very/long/path",
  "expires_at": "2030-12-31T23:59:59+09:00",
  "click_count": 0,
  "created_at": "..."
}
```

`slug` ist optional — automatisch generiert (`[a-z0-9_-]+`), wenn weggelassen.

### Fehlende Authentifizierung

```php
POST /links  (kein X-User-Id-Header)
→ 401
```

### Doppelter Slug

```php
POST /links  {"slug": "my-link"}  // existiert bereits
→ 409
```

## Slug-Validierung

```
Gültig: Kleinbuchstaben, Ziffern, Bindestriche, Unterstriche
Länge: 3–20 Zeichen

Gültige Beispiele: "abc", "my-link", "link123", "test-link-01"
```

```php
POST /links  {"slug": "ab"}          → 422  // zu kurz (min 3)
POST /links  {"slug": "a".repeat(21)} → 422  // zu lang (max 20)
POST /links  {"slug": "MySlug"}       → 422  // Großbuchstaben nicht erlaubt
POST /links  {"slug": "sl@g!"}        → 422  // Sonderzeichen
POST /links  {"slug": "my slug"}      → 422  // Leerzeichen nicht erlaubt
POST /links  {"slug": 42}             → 422  // Typ muss String sein (VULN-B)
```

## URL-Validierung

```php
POST /links  {"original_url": ""}              → 422  // leer
POST /links  {}                                → 422  // fehlend
POST /links  {"original_url": 42}              → 422  // kein String (VULN-B)
POST /links  {"original_url": true}            → 422  // bool (VULN-B)
POST /links  {"original_url": null}            → 422  // null (VULN-B)
POST /links  {"original_url": "https://..."+"x".repeat(2030)}  → 422  // zu lang
```

## SSRF-Prävention

URLs blockieren, die den Server dazu bringen würden, interne Infrastruktur aufzurufen:

### Blockierte Schemata

```php
POST /links  {"original_url": "javascript:alert(1)"}  → 422
POST /links  {"original_url": "file:///etc/passwd"}   → 422
POST /links  {"original_url": "ftp://example.com/"}   → 422
```

Nur `http://` und `https://` sind erlaubt.

### Blockierte IP-Bereiche

```php
// Loopback
POST /links  {"original_url": "http://127.0.0.1/admin"}     → 422
POST /links  {"original_url": "http://localhost/secret"}     → 422
POST /links  {"original_url": "http://internal.localhost/"}  → 422  // *.localhost

// RFC 1918 private Bereiche
POST /links  {"original_url": "http://10.0.0.1/metadata"}    → 422
POST /links  {"original_url": "http://192.168.1.1/router"}   → 422
POST /links  {"original_url": "http://172.16.0.1/internal"}  → 422

// Link-Local (AWS-Metadata, etc.)
POST /links  {"original_url": "http://169.254.169.254/latest/meta-data/"}  → 422

// Öffentliche IP — akzeptiert
POST /links  {"original_url": "https://8.8.8.8/"}            → 201  ✅
```

### DNS-Rebinding-Prävention

Hostnamen, die auf private IPs aufgelöst werden, sind ebenfalls blockiert:

```php
// "private.internal" löst auf 10.0.0.1 → blockiert
POST /links  {"original_url": "http://private.internal/data"}  → 422

// "public.example.com" löst auf 93.184.216.34 → erlaubt
POST /links  {"original_url": "https://public.example.com/page"}  → 201  ✅
```

### Implementierung

```php
private const BLOCKED_RANGES = [
    '127.',          // Loopback
    '10.',           // RFC 1918
    '172.16.', '172.17.', '172.18.', '172.19.',
    '172.20.', '172.21.', '172.22.', '172.23.',
    '172.24.', '172.25.', '172.26.', '172.27.',
    '172.28.', '172.29.', '172.30.', '172.31.',  // RFC 1918
    '192.168.',      // RFC 1918
    '169.254.',      // Link-Local
];

private const ALLOWED_SCHEMES = ['http', 'https'];

public function validate(string $url): bool
{
    $parsed = parse_url($url);
    if (!$parsed || !in_array($parsed['scheme'] ?? '', self::ALLOWED_SCHEMES, true)) {
        return false;
    }

    $host = $parsed['host'] ?? '';

    // *.localhost blockieren
    if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
        return false;
    }

    // Hostnamen zu IP auflösen
    $ip = ($this->dnsResolver)($host);

    foreach (self::BLOCKED_RANGES as $prefix) {
        if (str_starts_with($ip, $prefix)) {
            return false;
        }
    }

    return true;
}
```

## Mass-Assignment-Prävention

```php
// Angreifer versucht click_count oder created_at zu setzen
POST /links
{
  "original_url": "https://example.com",
  "slug": "attack",
  "click_count": 999999,
  "created_at": "2000-01-01T00:00:00+00:00"
}
→ 201  {"click_count": 0, "created_at": "2026-..."}  // ignorierte Felder
```

Nur `original_url`, `slug`, `expires_at` aus dem Request-Body auf die Allowlist setzen. Niemals `click_count`, `created_at` oder `user_id` aus dem Body lesen.

## ISO 8601-Datumsvalidierung

```php
// Ungültige Kalenderdaten
POST /links  {"expires_at": "2024-02-30T00:00:00+00:00"}  → 422  // Feb 30
POST /links  {"expires_at": "2024-13-01T00:00:00+00:00"}  → 422  // Monat 13
POST /links  {"expires_at": "2030-06-01T00:00:00+25:00"}  → 422  // +25:00 Offset

// Gültig
POST /links  {"expires_at": "2030-06-01T00:00:00+09:00"}  → 201  ✅
```

Validierungsmuster: mit `DateTimeImmutable::createFromFormat()` parsen und den Round-Trip verifizieren:

```php
$dt = DateTimeImmutable::createFromFormat(DATE_RFC3339, $value);
if ($dt === false) return false;
// Round-Trip-Prüfung fängt "2024-02-30" ab, das PHP zu "2024-03-01" normalisiert
return $dt->format(DATE_RFC3339) === $value;
```

## ReDoS-sichere Limit-Validierung

```php
// ctype_digit für O(n) — immun gegen ReDoS
GET /links?limit=10       → 200  ✅
GET /links?limit=999999   → 422  // überschreitet MAX_LIMIT
GET /links?limit=9...9 (19 Ziffern)  → 422  // Überlaufschutz
GET /links?limit=111...1x (51 Zeichen mit x)  → 422, <100ms  // ReDoS-Payload
```

## IDOR-Prävention

```php
// Benutzer 2 versucht den Link von Benutzer 1 zu löschen
DELETE /links/user1-link
X-User-Id: 2
→ 404  // NICHT 403 — verhindert Aufzählung
```

Der Link existiert, aber der Lookup ist auf `WHERE slug = ? AND user_id = ?` begrenzt. Eine Abweichung gibt 404 zurück, als ob der Link nicht existiert.

---

## Was NICHT zu tun ist

| Anti-Muster | Risiko |
|---|---|
| `http://localhost` oder `http://127.0.0.1` erlauben | Server ruft seinen eigenen Admin-Endpunkt über den Kurzlink auf |
| DNS-Auflösungs-Prüfung überspringen | Angreifer registriert `evil.example.com` → A-Eintrag `10.0.0.1`, um IP-Literal-Prüfung zu umgehen |
| `javascript:`-Schema erlauben | XSS über Kurzlink in jedem Browser, der die Weiterleitung öffnet |
| `file://`-Schema erlauben | Server liest `/etc/passwd`, wenn der Verkürzer die URL bei der Erstellung abruft |
| `click_count` aus dem Request-Body akzeptieren | Angreifer bläst Klick-Metriken auf |
| Keine Slug-Längen-/Zeichensatz-Einschränkung | `slug = "' OR 1=1--"` besteht Validierung, erreicht SQL |
| Regex `/^\d+$/` für Limit-Validierung verwenden | ReDoS auf langen gemischten Ziffern-Payloads |
| `created_at` aus dem Request-Body zurückgeben | Zeitspoofing korrumpiert Prüfpfad |
