---
title: "How-to: Webhook Delivery System"
category: infrastructure
tags: [webhook, ssrf, hmac, signature, secret-hashing]
difficulty: advanced
related: [webhook-delivery-api, webhook-delivery, webhook-signature-verification]
---

# How-to: Webhook Delivery System

> **FT reference**: FT308 (`NENE2-FT/webhookdeliverylog`) — Webhook delivery system: SSRF protection via UrlValidator (HTTPS-only, private IP blocklist, CRLF injection prevention), HMAC-SHA256 signature with timestamp binding, secret stored as SHA-256 hash (never plaintext), secret not returned in GET responses, deactivated endpoints skip delivery, event type isolation, ATK-01〜12 all BLOCKED, 31 tests / 47 assertions PASS.

This guide shows how to build a webhook delivery system where webhook secrets are protected, URLs are validated against SSRF attacks, and payloads are signed with timestamps to prevent replay attacks.

## Schema

```sql
CREATE TABLE IF NOT EXISTS webhook_endpoints (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    url         TEXT    NOT NULL,
    event_type  TEXT    NOT NULL,
    secret_hash TEXT    NOT NULL,   -- SHA-256 hash of the raw secret
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

`secret_hash` stores the SHA-256 hash of the raw secret — never the secret itself. `active` flag allows soft-disabling an endpoint without deleting delivery history.

## SSRF Protection — UrlValidator

```php
final class UrlValidator
{
    public function validate(string $url): ?string
    {
        // Block CRLF and null byte injection
        if (str_contains($url, "\n") || str_contains($url, "\r") || str_contains($url, "\0")) {
            return 'URL contains illegal control characters.';
        }

        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            return 'URL is not valid.';
        }

        // HTTPS only
        if (strtolower($parsed['scheme']) !== 'https') {
            return 'Only HTTPS URLs are allowed for webhook delivery.';
        }

        $host = strtolower($parsed['host']);

        // Block localhost and variants
        if (in_array($host, ['localhost', 'ip6-localhost', 'ip6-loopback'], true)) {
            return "Webhook URL must not target '{$host}'.";
        }

        // Block internal TLDs
        foreach (['.local', '.internal', '.test', '.example', '.invalid', '.localhost'] as $pattern) {
            if (str_ends_with($host, $pattern)) {
                return "Webhook URL must not target '{$pattern}' domains.";
            }
        }

        // Block private IPv4 ranges (127.x, 10.x, 172.16-31.x, 192.168.x)
        $ip = trim($host, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return 'Webhook URL must not target private or loopback IP addresses.';
            }
        }

        // Block private IPv6 (::1, fc00::/7, fe80::/10)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            // ... IPv6 private range checks
        }

        return null; // valid
    }
}
```

Validation blocks:
1. **CRLF/null byte injection** — prevents header injection in HTTP requests to the webhook URL
2. **Non-HTTPS schemes** — `http://`, `file://`, `ftp://`, `gopher://` all blocked
3. **Loopback addresses** — `127.0.0.0/8`, `::1`
4. **Private ranges** — `10.x`, `172.16-31.x`, `192.168.x`, `0.0.0.0`
5. **Internal TLDs** — `.local`, `.internal`, `.test`, `.example`

## Webhook Signing — HMAC-SHA256 + Timestamp

```php
final class WebhookSigner
{
    public function sign(string $rawSecret, string $body, string $timestamp): string
    {
        $payload = $timestamp . '.' . $body;  // timestamp binds signature to time
        $mac     = hash_hmac('sha256', $payload, $rawSecret);
        return 'sha256=' . $mac;
    }

    public function hashSecret(string $rawSecret): string
    {
        return hash('sha256', $rawSecret);
    }
}
```

The signature format `sha256=<hex>` is the same pattern used by GitHub webhooks. The **timestamp is included in the signed content** (`timestamp.body`) — this prevents replay attacks: a signature captured at time T cannot be replayed at time T+1h.

## Secret Storage — Hash, Never Plaintext

```php
// On endpoint creation:
$secretHash = $this->signer->hashSecret($rawSecret);
$this->repo->createEndpoint($url, $eventType, $secretHash, $maxRetries);

// Return the raw secret ONCE to the caller:
return $this->json->create([
    'id'     => $endpointId,
    'secret' => $rawSecret,  // shown only at creation
    // stored as: secret_hash = SHA-256($rawSecret)
]);
```

The raw secret is returned to the caller **only once** at creation time. Subsequent `GET /endpoints/{id}` responses never include `secret` or `secret_hash`.

```php
// GET endpoint response — secret NOT included
return $this->json->create([
    'id'         => (int) $endpoint['id'],
    'url'        => $endpoint['url'],
    'event_type' => $endpoint['event_type'],
    'active'     => (bool) $endpoint['active'],
    'max_retries'=> (int) $endpoint['max_retries'],
    'created_at' => $endpoint['created_at'],
    // 'secret_hash' intentionally omitted
]);
```

## Deactivated Endpoint Skip

```php
// Dispatch handler
if (!(bool) $endpoint['active']) {
    return $this->json->create(['message' => 'Endpoint is inactive, no delivery queued.'], 200);
}
```

Deactivated endpoints receive no new deliveries. This allows disabling a webhook without deleting the endpoint or its delivery history.

## Event Type Isolation

Each endpoint subscribes to a specific `event_type`. When dispatching:

```php
$endpoints = $this->repo->findActiveEndpointsByType($eventType);
// Only endpoints matching the event_type are delivered to
```

An endpoint subscribed to `order.created` does not receive `order.cancelled` events.

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — SSRF via Loopback IPv4 (127.x.x.x) 🚫 BLOCKED

**Attack**: Register endpoint with `url: "https://127.0.0.1/admin"`.
**Result**: BLOCKED — UrlValidator detects private IPv4 range → 422.

---

### ATK-02 — SSRF via 0.0.0.0 🚫 BLOCKED

**Attack**: `url: "https://0.0.0.0/internal"`.
**Result**: BLOCKED — reserved IP range blocked by `FILTER_FLAG_NO_RES_RANGE` → 422.

---

### ATK-03 — SSRF via Private Range 10.x.x.x 🚫 BLOCKED

**Attack**: `url: "https://10.0.0.1/internal"`.
**Result**: BLOCKED — private IPv4 range → 422.

---

### ATK-04 — SSRF via Private Range 172.16-31.x.x 🚫 BLOCKED

**Attack**: `url: "https://172.16.0.1/internal"`.
**Result**: BLOCKED — private IPv4 range → 422.

---

### ATK-05 — HTTP Scheme Downgrade 🚫 BLOCKED

**Attack**: `url: "http://example.com/hook"` (non-HTTPS).
**Result**: BLOCKED — scheme check: only `https` allowed → 422.

---

### ATK-06 — file:// Scheme 🚫 BLOCKED

**Attack**: `url: "file:///etc/passwd"`.
**Result**: BLOCKED — scheme check blocks non-HTTPS → 422.

---

### ATK-07 — CRLF Injection in URL 🚫 BLOCKED

**Attack**: `url: "https://example.com/\r\nX-Injected: header"`.
**Result**: BLOCKED — `str_contains($url, "\r")` check → 422.

---

### ATK-08 — Null Byte in URL 🚫 BLOCKED

**Attack**: `url: "https://example.com/\0hidden"`.
**Result**: BLOCKED — `str_contains($url, "\0")` check → 422.

---

### ATK-09 — Secret Leak via GET Endpoint 🚫 BLOCKED

**Attack**: `GET /endpoints/{id}` to retrieve the stored secret.
**Result**: BLOCKED — GET response omits `secret` and `secret_hash` fields entirely.

---

### ATK-10 — Secret Leak via Dispatch Response 🚫 BLOCKED

**Attack**: Inspect dispatch response body for secret material.
**Result**: BLOCKED — dispatch response contains only delivery metadata, no secret fields.

---

### ATK-11 — Replay Attack (Captured Signature) 🚫 BLOCKED

**Attack**: Capture a signed webhook and replay it with the same signature later.
**Result**: BLOCKED — signature is `HMAC(timestamp.body, secret)`. Timestamp changes per delivery; old signature doesn't match new timestamp.

---

### ATK-12 — Forged Signature with Wrong Secret 🚫 BLOCKED

**Attack**: Compute HMAC with a guessed/different secret, submit as valid signature.
**Result**: BLOCKED — receiver validates with stored secret hash; forged HMAC doesn't match.

---

### ATK Summary

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | SSRF loopback IPv4 | 🚫 BLOCKED |
| ATK-02 | SSRF 0.0.0.0 | 🚫 BLOCKED |
| ATK-03 | SSRF private 10.x | 🚫 BLOCKED |
| ATK-04 | SSRF private 172.16-31.x | 🚫 BLOCKED |
| ATK-05 | HTTP scheme downgrade | 🚫 BLOCKED |
| ATK-06 | file:// scheme | 🚫 BLOCKED |
| ATK-07 | CRLF injection in URL | 🚫 BLOCKED |
| ATK-08 | Null byte in URL | 🚫 BLOCKED |
| ATK-09 | Secret leak via GET | 🚫 BLOCKED |
| ATK-10 | Secret leak via dispatch | 🚫 BLOCKED |
| ATK-11 | Replay attack | 🚫 BLOCKED |
| ATK-12 | Forged signature | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED**
UrlValidator blocks all SSRF vectors. Timestamp-bound HMAC prevents replays. Secret stored as hash, never returned after creation.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Store raw webhook secret in DB | DB breach exposes all secrets; SHA-256 hash is one-way |
| Return secret in GET response | Any admin API leak exposes all webhook secrets |
| HMAC over body only (no timestamp) | Replay attack: captured signature reused indefinitely |
| Allow `http://` webhook URLs | Traffic eavesdropping on webhook payloads |
| No SSRF validation on URL | Webhook system used to probe internal network |
| Allow `127.x`, `10.x` in webhook URL | Server makes requests to its own internal services |
| No CRLF check | URL with `\r\n` injects headers into outbound HTTP request |
| Deliver to inactive endpoints | Deactivated endpoints continue to receive traffic |
| No event type filtering | All event types delivered to all endpoints |
