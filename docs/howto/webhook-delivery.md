---
title: "Outbound Webhook Delivery"
category: infrastructure
tags: [webhook, outbound, ssrf, signature]
difficulty: intermediate
related: [webhook-delivery-api, webhook-delivery-system, webhook-signature]
---

# Outbound Webhook Delivery

Outbound webhooks notify third-party systems when events occur in your application. The primary security concerns are SSRF (sending requests to internal infrastructure), secret leakage, and signature integrity.

## Core components

- **Endpoint registry**: stores the URL, event filter, and a hashed secret per subscriber.
- **Delivery queue**: one record per (endpoint, event) pair, tracking attempt count and status.
- **Signer**: generates HMAC-SHA256 signatures that the receiver can verify.
- **URL validator**: blocks SSRF targets before storing endpoints.

## Schema

```sql
CREATE TABLE webhook_endpoints (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    url         TEXT    NOT NULL,
    event_type  TEXT    NOT NULL,
    secret_hash TEXT    NOT NULL,       -- SHA-256 of raw secret; raw secret never stored
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
    last_status   INTEGER,                             -- last HTTP response code
    last_error    TEXT,
    delivered_at  TEXT,
    created_at    TEXT    NOT NULL,
    updated_at    TEXT    NOT NULL
);
```

Only the SHA-256 hash of the secret is stored. The raw secret is never persisted — if the database is compromised, hashes cannot be reversed to forge signatures (SHA-256 without HMAC is not reversible for a random 32-byte secret).

## Signature format

```
X-Webhook-Signature: sha256={hex}
X-Webhook-Timestamp: {unix_timestamp}
```

Signed content: `{timestamp}.{body}` — binding the signature to both the payload and a point in time.

```php
public function sign(string $rawSecret, string $body, string $timestamp): string
{
    $payload = $timestamp . '.' . $body;
    $mac     = hash_hmac('sha256', $payload, $rawSecret);

    return 'sha256=' . $mac;
}
```

Including the timestamp in the signed content prevents replay attacks: an attacker who captures a valid webhook cannot reuse it later because the timestamp would be stale. Receivers should reject signatures older than a threshold (e.g., 5 minutes).

## SSRF prevention

Validate every webhook URL before storing it. At minimum, block:

```php
final class UrlValidator
{
    public function validate(string $url): ?string
    {
        // Block CRLF/null byte injection
        if (str_contains($url, "\n") || str_contains($url, "\r") || str_contains($url, "\0")) {
            return 'URL contains illegal control characters.';
        }

        // HTTPS only
        if (strtolower(parse_url($url, PHP_URL_SCHEME) ?? '') !== 'https') {
            return 'Only HTTPS URLs are allowed.';
        }

        // Block private/loopback IPs and reserved hostnames
        // ...
    }
}
```

Private IPv4 ranges to block: `127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16`, `0.0.0.0`.

Hostnames to block: `localhost`, `*.local`, `*.internal`, `*.test`, `*.invalid`.

IPv6: `::1`, `fc00::/7` (ULA), `fe80::/10` (link-local).

**DNS rebinding**: validating the URL at registration time is not sufficient — the DNS record could change between registration and delivery to point at an internal IP. For production, also validate the resolved IP at delivery time before opening the TCP connection.

## Response filtering — never expose secrets

The `toArray()` method on `WebhookEndpoint` must omit both `secret` and `secret_hash`:

```php
public function toArray(): array
{
    return [
        'id', 'url', 'event_type', 'max_retries', 'active', 'created_at',
        // secret_hash intentionally absent
    ];
}
```

This applies to: GET /webhooks/{id}, list endpoints, and any audit log that records endpoint metadata.

## Retry logic

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

- `attempt_count < max_retries` → status stays `pending` → worker picks it up again.
- `attempt_count >= max_retries` → status becomes `failed` → no more retries.

Workers should implement exponential backoff (e.g., `2^attempt_count` seconds) to avoid hammering a struggling receiver.

## Deactivation

Deactivated endpoints (`active = 0`) are excluded from the fan-out query at dispatch time:

```sql
SELECT * FROM webhook_endpoints WHERE event_type = ? AND active = 1
```

This gives subscribers a way to pause delivery without deleting their registration.

## Design decisions

**Why store `secret_hash` instead of raw secret?**
If the DB is compromised, the attacker cannot extract secrets to forge webhook signatures sent to receivers. The raw secret is returned once at creation time and must be stored securely by the caller.

**Why include timestamp in the signature?**
Signatures without timestamps are replayable indefinitely. Including `{timestamp}.{body}` in the HMAC means an attacker who intercepts a webhook cannot resend it — receivers can reject timestamps outside a ±5 minute window.

**Why validate URL at registration, not at dispatch?**
Blocking invalid URLs at registration gives immediate feedback to the subscriber and prevents bad data from entering the delivery queue. DNS rebinding attacks require additional validation at dispatch time.
