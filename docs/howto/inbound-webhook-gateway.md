# How-to: Inbound Webhook Gateway

> **FT reference**: FT317 (`NENE2-FT/inboundlog`) — Inbound webhook gateway with per-source HMAC-SHA256 signature verification, duplicate event_id idempotency, secret never exposed in responses, 17 tests / 18 assertions PASS.

This guide shows how to build a multi-source inbound webhook receiver that validates request authenticity before processing.

## Schema

```sql
CREATE TABLE sources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    secret     TEXT    NOT NULL,   -- shared secret for HMAC
    active     INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL
);

CREATE TABLE webhook_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id   INTEGER NOT NULL REFERENCES sources(id),
    event_id    TEXT    NOT NULL,  -- provider-supplied dedup key
    event_type  TEXT    NOT NULL,
    payload     TEXT    NOT NULL,  -- raw JSON body
    received_at TEXT    NOT NULL,
    UNIQUE(source_id, event_id)
);
```

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/sources` | Register a new webhook source |
| `POST` | `/sources/{id}/receive` | Receive webhook event |
| `GET`  | `/sources/{id}/events` | List events for a source |
| `GET`  | `/events/{id}` | Get single event |

## Source Registration

```php
POST /sources
{"name": "stripe", "secret": "whsec_abc123..."}

→ 201
{"id": 1, "name": "stripe", "active": true, "created_at": "..."}
// secret is NEVER returned
```

```php
POST /sources  {"secret": "abc"}   → 422  // name required
POST /sources  {"name": "github"}  → 422  // secret required
```

## HMAC-SHA256 Signature Verification

Each incoming webhook must include an `X-Webhook-Signature` header with the HMAC-SHA256 of the raw body:

```
X-Webhook-Signature: sha256=<hex_digest>
```

```php
private function verifySignature(string $body, string $header, string $secret): bool
{
    if (!str_starts_with($header, 'sha256=')) {
        return false;
    }
    $expected = hash_hmac('sha256', $body, $secret);
    return hash_equals($expected, substr($header, 7));  // constant-time compare
}
```

**Important**: use `hash_equals()` — not `===` — to prevent timing attacks.

## Receiving Events

```php
// Sender (e.g. Stripe) computes:
$sig = 'sha256=' . hash_hmac('sha256', $rawBody, $sharedSecret);

POST /sources/1/receive
X-Webhook-Signature: sha256=<digest>
Content-Type: application/json

{"event_id": "evt-001", "event_type": "payment.succeeded", "data": {...}}

→ 201  {"id": 5, "event_type": "payment.succeeded", "status": "processed"}
```

### Error Cases

```php
// Wrong or missing signature
POST /sources/1/receive  (bad sig)  → 401 Unauthorized

// Source not found
POST /sources/9999/receive          → 404 Not Found

// Missing event_id in payload
POST /sources/1/receive  {"event_type": "x"}  → 422
```

## Duplicate Event Idempotency

Provider retries are common — `event_id` deduplication prevents double-processing:

```php
// First delivery
POST /sources/1/receive  {"event_id": "evt-dup", "event_type": "order.created"}
→ 201  {"status": "processed", "id": 5}

// Retry (same event_id)
POST /sources/1/receive  {"event_id": "evt-dup", "event_type": "order.created"}
→ 200  {"status": "already_processed", "id": 5}
```

`UNIQUE(source_id, event_id)` in the DB enforces this at the storage layer.

## Querying Events

```php
GET /sources/1/events
→ 200  {"events": [...], "count": 2}

GET /events/5
→ 200  {"id": 5, "source_id": 1, "event_type": "payment.succeeded", ...}

GET /events/9999
→ 404
```

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Return `secret` in source response | Leaks signing key to any client that can read the API response |
| Use `===` instead of `hash_equals()` for signature | Timing attack reveals HMAC byte-by-byte |
| No `event_id` dedup | Provider retries cause double processing (double charges, duplicate emails) |
| Verify signature after parsing JSON | Attacker can craft body that passes JSON parsing but fails HMAC; always verify raw bytes first |
| Single global secret for all sources | Compromise of one integration exposes all |
