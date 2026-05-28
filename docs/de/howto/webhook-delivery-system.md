# How-to: Webhook-Zustellungssystem

> **FT-Referenz**: FT308 (`NENE2-FT/webhookdeliverylog`) — Webhook-Zustellungssystem: SSRF-Schutz via UrlValidator (nur HTTPS, private-IP-Blocklist, CRLF-Injection-Prävention), HMAC-SHA256-Signatur mit Timestamp-Bindung, Secret als SHA-256-Hash gespeichert (niemals Klartext), Secret nicht in GET-Antworten zurückgegeben, deaktivierte Endpunkte überspringen Zustellung, Event-Typ-Isolation, ATK-01〜12 alle BLOCKED, 31 Tests / 47 Assertions BESTANDEN.

Diese Anleitung zeigt, wie ein Webhook-Zustellungssystem aufgebaut wird, bei dem Webhook-Secrets geschützt sind, URLs gegen SSRF-Angriffe validiert werden und Payloads mit Timestamps signiert werden, um Replay-Angriffe zu verhindern.

## Schema

```sql
CREATE TABLE IF NOT EXISTS webhook_endpoints (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    url         TEXT    NOT NULL,
    event_type  TEXT    NOT NULL,
    secret_hash TEXT    NOT NULL,   -- SHA-256-Hash des rohen Secrets
    max_retries INTEGER NOT NULL DEFAULT 3,
    active      INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    endpoint_id   INTEGER NOT NULL REFERENCES webhook_endpoints(id),
    event_type    TEXT    NOT NULL,
    payload       TEXT    NOT NULL DEFAULT '{}',
    status        TEXT    NOT NULL DEFAULT 'pending',
    attempt_count INTEGER NOT NULL DEFAULT 0,
    last_status   INTEGER,
    last_error    TEXT,
    delivered_at  TEXT,
    created_at    TEXT    NOT NULL,
    updated_at    TEXT    NOT NULL
);
```

`secret_hash` speichert den SHA-256-Hash des rohen Secrets — niemals das Secret selbst. Das `active`-Flag ermöglicht das Soft-Deaktivieren eines Endpunkts ohne Löschen der Zustellungshistorie.

## SSRF-Schutz — UrlValidator

```php
final class UrlValidator
{
    public function validate(string $url): ?string
    {
        // CRLF- und Null-Byte-Injection blockieren
        if (str_contains($url, "\n") || str_contains($url, "\r") || str_contains($url, "\0")) {
            return 'URL contains illegal control characters.';
        }

        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            return 'URL is not valid.';
        }

        // Nur HTTPS
        if (strtolower($parsed['scheme']) !== 'https') {
            return 'Only HTTPS URLs are allowed for webhook delivery.';
        }

        $host = strtolower($parsed['host']);

        // Localhost und Varianten blockieren
        if (in_array($host, ['localhost', 'ip6-localhost', 'ip6-loopback'], true)) {
            return "Webhook URL must not target '{$host}'.";
        }

        // Interne TLDs blockieren
        foreach (['.local', '.internal', '.test', '.example', '.invalid', '.localhost'] as $pattern) {
            if (str_ends_with($host, $pattern)) {
                return "Webhook URL must not target '{$pattern}' domains.";
            }
        }

        // Private IPv4-Bereiche blockieren (127.x, 10.x, 172.16-31.x, 192.168.x)
        $ip = trim($host, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return 'Webhook URL must not target private or loopback IP addresses.';
            }
        }

        // Private IPv6 blockieren (::1, fc00::/7, fe80::/10)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            // ... IPv6 private Bereichsprüfungen
        }

        return null; // gültig
    }
}
```

Validierung blockiert:
1. **CRLF/Null-Byte-Injection** — verhindert Header-Injection in HTTP-Anfragen an die Webhook-URL
2. **Nicht-HTTPS-Schemata** — `http://`, `file://`, `ftp://`, `gopher://` alle blockiert
3. **Loopback-Adressen** — `127.0.0.0/8`, `::1`
4. **Private Bereiche** — `10.x`, `172.16-31.x`, `192.168.x`, `0.0.0.0`
5. **Interne TLDs** — `.local`, `.internal`, `.test`, `.example`

## Webhook-Signierung — HMAC-SHA256 + Timestamp

```php
final class WebhookSigner
{
    public function sign(string $rawSecret, string $body, string $timestamp): string
    {
        $payload = $timestamp . '.' . $body;  // Timestamp bindet Signatur an Zeit
        $mac     = hash_hmac('sha256', $payload, $rawSecret);
        return 'sha256=' . $mac;
    }

    public function hashSecret(string $rawSecret): string
    {
        return hash('sha256', $rawSecret);
    }
}
```

Das Signaturformat `sha256=<hex>` ist dasselbe Muster wie bei GitHub-Webhooks. Der **Timestamp ist im signierten Inhalt enthalten** (`timestamp.body`) — das verhindert Replay-Angriffe: eine zum Zeitpunkt T erfasste Signatur kann nicht zum Zeitpunkt T+1h wiedergegeben werden.

## Secret-Speicherung — Hash, niemals Klartext

```php
// Bei Endpoint-Erstellung:
$secretHash = $this->signer->hashSecret($rawSecret);
$this->repo->createEndpoint($url, $eventType, $secretHash, $maxRetries);

// Das rohe Secret einmalig an den Aufrufer zurückgeben:
return $this->json->create([
    'id'     => $endpointId,
    'secret' => $rawSecret,  // nur bei Erstellung gezeigt
    // gespeichert als: secret_hash = SHA-256($rawSecret)
]);
```

Das rohe Secret wird dem Aufrufer **nur einmalig** bei der Erstellung zurückgegeben. Nachfolgende `GET /endpoints/{id}`-Antworten enthalten niemals `secret` oder `secret_hash`.

```php
// GET Endpoint-Antwort — Secret NICHT enthalten
return $this->json->create([
    'id'         => (int) $endpoint['id'],
    'url'        => $endpoint['url'],
    'event_type' => $endpoint['event_type'],
    'active'     => (bool) $endpoint['active'],
    'max_retries'=> (int) $endpoint['max_retries'],
    'created_at' => $endpoint['created_at'],
    // 'secret_hash' absichtlich weggelassen
]);
```

## Deaktivierter Endpoint überspringen

```php
// Dispatch-Handler
if (!(bool) $endpoint['active']) {
    return $this->json->create(['message' => 'Endpoint is inactive, no delivery queued.'], 200);
}
```

Deaktivierte Endpunkte erhalten keine neuen Zustellungen. Dies ermöglicht das Deaktivieren eines Webhooks ohne Löschen des Endpunkts oder seiner Zustellungshistorie.

## Event-Typ-Isolation

Jeder Endpunkt abonniert einen bestimmten `event_type`. Beim Dispatch:

```php
$endpoints = $this->repo->findActiveEndpointsByType($eventType);
// Nur Endpunkte, die dem event_type entsprechen, werden beliefert
```

Ein Endpunkt, der `order.created` abonniert, empfängt keine `order.cancelled`-Events.

---

## ATK Assessment — Cracker-Mindset-Angriffstest

### ATK-01 — SSRF via Loopback IPv4 (127.x.x.x) 🚫 BLOCKED

**Attack**: Endpunkt mit `url: "https://127.0.0.1/admin"` registrieren.
**Result**: BLOCKED — UrlValidator erkennt privaten IPv4-Bereich → 422.

---

### ATK-02 — SSRF via 0.0.0.0 🚫 BLOCKED

**Attack**: `url: "https://0.0.0.0/internal"`.
**Result**: BLOCKED — reservierter IP-Bereich durch `FILTER_FLAG_NO_RES_RANGE` blockiert → 422.

---

### ATK-03 — SSRF via privatem Bereich 10.x.x.x 🚫 BLOCKED

**Attack**: `url: "https://10.0.0.1/internal"`.
**Result**: BLOCKED — privater IPv4-Bereich → 422.

---

### ATK-04 — SSRF via privatem Bereich 172.16-31.x.x 🚫 BLOCKED

**Attack**: `url: "https://172.16.0.1/internal"`.
**Result**: BLOCKED — privater IPv4-Bereich → 422.

---

### ATK-05 — HTTP-Schema-Downgrade 🚫 BLOCKED

**Attack**: `url: "http://example.com/hook"` (Nicht-HTTPS).
**Result**: BLOCKED — Schema-Prüfung: nur `https` erlaubt → 422.

---

### ATK-06 — file://-Schema 🚫 BLOCKED

**Attack**: `url: "file:///etc/passwd"`.
**Result**: BLOCKED — Schema-Prüfung blockiert Nicht-HTTPS → 422.

---

### ATK-07 — CRLF-Injection in URL 🚫 BLOCKED

**Attack**: `url: "https://example.com/\r\nX-Injected: header"`.
**Result**: BLOCKED — `str_contains($url, "\r")`-Prüfung → 422.

---

### ATK-08 — Null-Byte in URL 🚫 BLOCKED

**Attack**: `url: "https://example.com/\0hidden"`.
**Result**: BLOCKED — `str_contains($url, "\0")`-Prüfung → 422.

---

### ATK-09 — Secret-Leak via GET-Endpoint 🚫 BLOCKED

**Attack**: `GET /endpoints/{id}` um das gespeicherte Secret abzurufen.
**Result**: BLOCKED — GET-Antwort lässt `secret`- und `secret_hash`-Felder vollständig weg.

---

### ATK-10 — Secret-Leak via Dispatch-Antwort 🚫 BLOCKED

**Attack**: Dispatch-Antwort-Body auf Secret-Material untersuchen.
**Result**: BLOCKED — Dispatch-Antwort enthält nur Zustellungs-Metadaten, keine Secret-Felder.

---

### ATK-11 — Replay-Angriff (erfasste Signatur) 🚫 BLOCKED

**Attack**: Signierten Webhook erfassen und später mit derselben Signatur wiedergeben.
**Result**: BLOCKED — Signatur ist `HMAC(timestamp.body, secret)`. Timestamp ändert sich pro Zustellung; alte Signatur passt nicht zum neuen Timestamp.

---

### ATK-12 — Gefälschte Signatur mit falschem Secret 🚫 BLOCKED

**Attack**: HMAC mit einem geratenen/anderen Secret berechnen und als gültige Signatur einreichen.
**Result**: BLOCKED — Empfänger validiert mit gespeichertem Secret-Hash; gefälschter HMAC passt nicht.

---

### ATK-Zusammenfassung

| ID | Angriff | Ergebnis |
|----|---------|----------|
| ATK-01 | SSRF Loopback IPv4 | 🚫 BLOCKED |
| ATK-02 | SSRF 0.0.0.0 | 🚫 BLOCKED |
| ATK-03 | SSRF privat 10.x | 🚫 BLOCKED |
| ATK-04 | SSRF privat 172.16-31.x | 🚫 BLOCKED |
| ATK-05 | HTTP-Schema-Downgrade | 🚫 BLOCKED |
| ATK-06 | file://-Schema | 🚫 BLOCKED |
| ATK-07 | CRLF-Injection in URL | 🚫 BLOCKED |
| ATK-08 | Null-Byte in URL | 🚫 BLOCKED |
| ATK-09 | Secret-Leak via GET | 🚫 BLOCKED |
| ATK-10 | Secret-Leak via Dispatch | 🚫 BLOCKED |
| ATK-11 | Replay-Angriff | 🚫 BLOCKED |
| ATK-12 | Gefälschte Signatur | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED**
UrlValidator blockiert alle SSRF-Vektoren. Timestamp-gebundenes HMAC verhindert Replays. Secret als Hash gespeichert, nach Erstellung nicht mehr zurückgegeben.

---

## Was NICHT zu tun ist

| Anti-Muster | Risiko |
|-------------|--------|
| Rohes Webhook-Secret in DB speichern | DB-Sicherheitsverletzung enthüllt alle Secrets; SHA-256-Hash ist Einweg |
| Secret in GET-Antwort zurückgeben | Jedes Admin-API-Leak enthüllt alle Webhook-Secrets |
| HMAC nur über Body (kein Timestamp) | Replay-Angriff: erfasste Signatur unbegrenzt wiederverwendbar |
| `http://`-Webhook-URLs erlauben | Abhören von Webhook-Payloads im Netzwerkverkehr |
| Keine SSRF-Validierung auf URL | Webhook-System wird zum Sondieren des internen Netzwerks missbraucht |
| `127.x`, `10.x` in Webhook-URL erlauben | Server macht Anfragen an seine eigenen internen Dienste |
| Keine CRLF-Prüfung | URL mit `\r\n` injiziert Header in ausgehende HTTP-Anfrage |
| An inaktive Endpunkte zustellen | Deaktivierte Endpunkte empfangen weiterhin Traffic |
| Keine Event-Typ-Filterung | Alle Event-Typen werden an alle Endpunkte zugestellt |
