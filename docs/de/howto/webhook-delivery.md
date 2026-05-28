# Ausgehende Webhook-Zustellung

Ausgehende Webhooks benachrichtigen Drittsysteme, wenn Ereignisse in der Anwendung auftreten. Die primären Sicherheitsbedenken sind SSRF (Anfragen an interne Infrastruktur senden), Secret-Leak und Signaturintegrität.

## Kernkomponenten

- **Endpoint-Registry**: speichert URL, Event-Filter und ein gehashtes Secret pro Subscriber.
- **Delivery-Queue**: ein Datensatz pro (Endpoint, Event)-Paar, verfolgt Versuchsanzahl und Status.
- **Signer**: generiert HMAC-SHA256-Signaturen, die der Empfänger verifizieren kann.
- **URL-Validator**: blockiert SSRF-Ziele vor der Speicherung von Endpunkten.

## Schema

```sql
CREATE TABLE webhook_endpoints (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    url         TEXT    NOT NULL,
    event_type  TEXT    NOT NULL,
    secret_hash TEXT    NOT NULL,       -- SHA-256 des rohen Secrets; rohes Secret wird nie gespeichert
    max_retries INTEGER NOT NULL DEFAULT 3,
    active      INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT    NOT NULL
);

CREATE TABLE webhook_deliveries (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    endpoint_id   INTEGER NOT NULL,
    event_type    TEXT    NOT NULL,
    payload       TEXT    NOT NULL,
    status        TEXT    NOT NULL DEFAULT 'pending',  -- pending | delivered | failed
    attempt_count INTEGER NOT NULL DEFAULT 0,
    last_status   INTEGER,                             -- letzter HTTP-Antwortcode
    last_error    TEXT,
    delivered_at  TEXT,
    created_at    TEXT    NOT NULL,
    updated_at    TEXT    NOT NULL
);
```

Nur der SHA-256-Hash des Secrets wird gespeichert. Das rohe Secret wird nie persistiert — wenn die Datenbank kompromittiert wird, können Hashes nicht rückgängig gemacht werden, um Signaturen zu fälschen (SHA-256 ohne HMAC ist für ein zufälliges 32-Byte-Secret nicht umkehrbar).

## Signaturformat

```
X-Webhook-Signature: sha256={hex}
X-Webhook-Timestamp: {unix_timestamp}
```

Signierter Inhalt: `{timestamp}.{body}` — bindet die Signatur sowohl an das Payload als auch an einen Zeitpunkt.

```php
public function sign(string $rawSecret, string $body, string $timestamp): string
{
    $payload = $timestamp . '.' . $body;
    $mac     = hash_hmac('sha256', $payload, $rawSecret);

    return 'sha256=' . $mac;
}
```

Der Timestamp im signierten Inhalt verhindert Replay-Angriffe: Ein Angreifer, der einen gültigen Webhook erfasst, kann ihn nicht später wiederverwenden, weil der Timestamp veraltet wäre. Empfänger sollten Signaturen ablehnen, die älter als ein Schwellenwert (z. B. 5 Minuten) sind.

## SSRF-Prävention

Jede Webhook-URL vor der Speicherung validieren. Mindestens blockieren:

```php
final class UrlValidator
{
    public function validate(string $url): ?string
    {
        // CRLF/Null-Byte-Injection blockieren
        if (str_contains($url, "\n") || str_contains($url, "\r") || str_contains($url, "\0")) {
            return 'URL contains illegal control characters.';
        }

        // Nur HTTPS
        if (strtolower(parse_url($url, PHP_URL_SCHEME) ?? '') !== 'https') {
            return 'Only HTTPS URLs are allowed.';
        }

        // Private/Loopback-IPs und reservierte Hostnamen blockieren
        // ...
    }
}
```

Zu blockierende private IPv4-Bereiche: `127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16`, `0.0.0.0`.

Zu blockierende Hostnamen: `localhost`, `*.local`, `*.internal`, `*.test`, `*.invalid`.

IPv6: `::1`, `fc00::/7` (ULA), `fe80::/10` (link-local).

**DNS-Rebinding**: Die URL nur bei der Registrierung zu validieren ist nicht ausreichend — der DNS-Eintrag könnte sich zwischen Registrierung und Zustellung ändern, um auf eine interne IP zu zeigen. Für die Produktion auch die aufgelöste IP bei der Zustellung validieren, bevor die TCP-Verbindung geöffnet wird.

## Antwortfilterung — Secrets niemals preisgeben

Die `toArray()`-Methode auf `WebhookEndpoint` muss sowohl `secret` als auch `secret_hash` weglassen:

```php
public function toArray(): array
{
    return [
        'id', 'url', 'event_type', 'max_retries', 'active', 'created_at',
        // secret_hash absichtlich weggelassen
    ];
}
```

Dies gilt für: GET /webhooks/{id}, Endpunkte auflisten und jedes Audit-Log, das Endpoint-Metadaten aufzeichnet.

## Wiederholungslogik

```php
public function markFailed(int $id, string $error, ?int $httpStatus, string $now, int $maxRetries): ?WebhookDelivery
{
    $newCount  = $delivery->attemptCount + 1;
    $newStatus = $newCount >= $maxRetries ? 'failed' : 'pending';

    $this->executor->execute(
        'UPDATE webhook_deliveries SET status = ?, attempt_count = ?, last_error = ?, updated_at = ? WHERE id = ?',
        [$newStatus, $newCount, $error, $now, $id],
    );
}
```

- `attempt_count < max_retries` → Status bleibt `pending` → Worker nimmt ihn erneut auf.
- `attempt_count >= max_retries` → Status wird `failed` → Keine weiteren Wiederholungen.

Worker sollten exponentielles Backoff implementieren (z. B. `2^attempt_count` Sekunden), um einen kämpfenden Empfänger nicht zu überlasten.

## Deaktivierung

Deaktivierte Endpunkte (`active = 0`) werden beim Dispatch aus der Fan-Out-Abfrage ausgeschlossen:

```sql
SELECT * FROM webhook_endpoints WHERE event_type = ? AND active = 1
```

Das gibt Subscribern eine Möglichkeit, die Zustellung zu pausieren ohne ihre Registrierung zu löschen.

## Designentscheidungen

**Warum `secret_hash` statt rohes Secret speichern?**
Wenn die DB kompromittiert wird, kann der Angreifer keine Secrets extrahieren, um an Empfänger gesendete Webhook-Signaturen zu fälschen. Das rohe Secret wird einmalig bei der Erstellung zurückgegeben und muss vom Aufrufer sicher gespeichert werden.

**Warum Timestamp in der Signatur einschließen?**
Signaturen ohne Timestamps sind unbegrenzt wiederholbar. Das Einschließen von `{timestamp}.{body}` im HMAC bedeutet, dass ein Angreifer, der einen Webhook abfängt, ihn nicht erneut senden kann — Empfänger können Timestamps außerhalb eines ±5-Minuten-Fensters ablehnen.

**Warum URL bei Registrierung und nicht bei Dispatch validieren?**
Das Blockieren ungültiger URLs bei der Registrierung gibt dem Subscriber sofortiges Feedback und verhindert, dass schlechte Daten in die Delivery-Queue gelangen. DNS-Rebinding-Angriffe erfordern zusätzliche Validierung zum Zeitpunkt der Zustellung.
