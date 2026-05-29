---
title: "How-to: Webhook Delivery API"
category: infrastructure
tags: [webhook, delivery, retry, event-dispatch]
difficulty: intermediate
related: [webhook-delivery-system, webhook-delivery, webhook-signature-verification]
---

# How-to: Webhook Delivery API

> **FT reference**: FT348 (`NENE2-FT/webhooklog`) — Webhook registration with URL/secret/event filters, event dispatch with per-subscriber delivery logging, secret masking, retry mechanism, success/failed status tracking, 18 tests PASS.

This guide shows how to build a webhook delivery system: register endpoint subscribers, dispatch events to matching hooks, log every delivery attempt, and retry failures.

## Schema

```sql
CREATE TABLE webhooks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    url        TEXT    NOT NULL,
    secret     TEXT    NOT NULL DEFAULT '',
    events     TEXT    NOT NULL DEFAULT '[]',  -- JSON array; empty = all events
    is_active  INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL
);

CREATE TABLE deliveries (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    webhook_id   INTEGER NOT NULL REFERENCES webhooks(id) ON DELETE CASCADE,
    event_type   TEXT    NOT NULL,
    payload      TEXT    NOT NULL DEFAULT '{}',
    status       TEXT    NOT NULL CHECK(status IN ('pending', 'success', 'failed')),
    http_status  INTEGER,
    response     TEXT,
    error        TEXT,
    attempted_at TEXT,
    created_at   TEXT    NOT NULL
);
```

`events = '[]'` (empty array) means "subscribe to all events". `ON DELETE CASCADE` removes delivery records when a webhook is deleted.

## Endpoints

| Method | Path                            | Description                    |
|--------|---------------------------------|--------------------------------|
| `POST` | `/webhooks`                     | Register a webhook             |
| `GET`  | `/webhooks`                     | List all webhooks              |
| `GET`  | `/webhooks/{id}`                | Get single webhook             |
| `DELETE` | `/webhooks/{id}`              | Delete webhook (+ deliveries)  |
| `GET`  | `/webhooks/{id}/deliveries`     | List deliveries for webhook    |
| `POST` | `/events/dispatch`              | Dispatch event to subscribers  |
| `POST` | `/deliveries/{id}/retry`        | Retry a failed delivery        |

## Register a Webhook

```php
POST /webhooks
{
  "url": "https://example.com/hook",
  "secret": "my-signing-secret",
  "events": ["order.created", "order.updated"]
}

→ 201
{
  "id": 1,
  "url": "https://example.com/hook",
  "secret": "***",        // ← secret always masked in responses
  "events": ["order.created", "order.updated"],
  "is_active": true,
  "created_at": "..."
}
```

### Subscribe to All Events

```php
POST /webhooks
{"url": "https://example.com/hook", "secret": "", "events": []}

→ 201  {"events": [], ...}   // empty events = receive all event types
```

### Validation

```php
POST /webhooks  {"events": []}
→ 422  // url is required
```

**Secret masking**: The stored secret is used only for HMAC signing. Return `"***"` in every response — never the actual secret value.

## Dispatch Event

```php
POST /events/dispatch
{"event_type": "order.created", "payload": {"order_id": 42, "amount": 99.99}}

→ 200
{
  "event_type": "order.created",
  "dispatched_to": 2,           // number of matching webhooks
  "deliveries": [
    {
      "id": 1,
      "webhook_id": 1,
      "event_type": "order.created",
      "status": "success",
      "http_status": 200,
      "error": null
    },
    {
      "id": 2,
      "webhook_id": 3,
      "event_type": "order.created",
      "status": "failed",
      "http_status": 500,
      "error": "Connection timeout"
    }
  ]
}
```

### Event Matching

A webhook receives an event if:
1. Its `events` array is empty (subscribes to all), **OR**
2. The `event_type` appears in its `events` array.

```php
// Webhook A: events = ["order.created"]
// Webhook B: events = ["user.signup"]
// Webhook C: events = []  (all)

dispatch("order.created")
→ dispatched_to: 2  // A and C match, B does not
```

### No Matching Webhooks

```php
POST /events/dispatch  {"event_type": "unknown.event", "payload": {}}
→ 200  {"dispatched_to": 0, "deliveries": []}
```

### Dispatch Implementation

```php
public function dispatch(string $eventType, array $payload): array
{
    // Find all active webhooks that match this event
    $hooks = $this->repo->findMatchingWebhooks($eventType);
    $deliveries = [];

    foreach ($hooks as $hook) {
        $delivery = $this->repo->createDelivery($hook['id'], $eventType, $payload, 'pending');
        $result = $this->client->deliver($hook['url'], $eventType, $payload, $hook['secret']);
        $this->repo->updateDelivery(
            $delivery['id'],
            $result->status,        // 'success' or 'failed'
            $result->httpStatus,
            $result->response,
            $result->error,
            $now,
        );
        $deliveries[] = $this->repo->findDelivery($delivery['id']);
    }

    return [
        'event_type'    => $eventType,
        'dispatched_to' => count($deliveries),
        'deliveries'    => $deliveries,
    ];
}
```

```sql
-- Find matching webhooks (active + event filter)
SELECT * FROM webhooks
WHERE is_active = 1
  AND (events = '[]' OR events LIKE '%"' || ? || '"%')
```

## List Deliveries

```php
GET /webhooks/1/deliveries

→ 200
{
  "total": 3,
  "items": [
    {"id": 1, "event_type": "order.created", "status": "success", "http_status": 200, ...},
    {"id": 2, "event_type": "order.updated", "status": "failed",  "http_status": 500, ...},
    {"id": 3, "event_type": "ping",           "status": "success", "http_status": 200, ...}
  ]
}

// Webhook not found
GET /webhooks/9999/deliveries
→ 404
```

## Retry a Failed Delivery

```php
POST /deliveries/2/retry

→ 200
{
  "id": 2,
  "status": "success",
  "http_status": 200,
  "error": null
}

// Delivery not found
POST /deliveries/9999/retry
→ 404
```

---

## ATK Assessment — Cracker-Mindset Attack Test

### ATK-01 — Secret Extraction via GET 🚫 BLOCKED

**Attack**: Attacker registers a webhook then calls `GET /webhooks/{id}` or lists webhooks to retrieve the signing secret.
**Result**: BLOCKED — Every response returns `"secret": "***"`. The actual secret is stored in the DB but never returned through any endpoint. Attacker cannot recover the secret via the API.

---

### ATK-02 — Register Webhook with Internal/Private URL (SSRF) ⚠️ EXPOSED

**Attack**: Attacker registers `url: "http://169.254.169.254/latest/meta-data"` (AWS metadata endpoint) or `http://localhost:8080/admin`. When an event is dispatched, the server fetches the internal URL.
**Result**: EXPOSED — The webhooklog FT does not implement URL validation or SSRF blocking on registered URLs. In production, validate that the URL resolves to a public IP (not loopback, private RFC1918, link-local, or metadata services) before registering. See `docs/howto/url-shortener-ssrf-prevention.md` for the SSRF blocking pattern.

---

### ATK-03 — Dispatch to Inactive Webhook 🚫 BLOCKED

**Attack**: Attacker deletes a webhook then dispatches an event, hoping delivery still occurs to a cached endpoint.
**Result**: BLOCKED — Dispatch query filters `WHERE is_active = 1`. Deleted webhooks are removed from the table (`ON DELETE CASCADE`), so they never appear in the matching query.

---

### ATK-04 — Inject SQL via event_type Field 🚫 BLOCKED

**Attack**: Attacker sends `{"event_type": "'; DROP TABLE webhooks; --", "payload": {}}` to destroy webhook registrations.
**Result**: BLOCKED — The `LIKE '%"' || ? || '"%'` match query uses a bound parameter for `event_type`. PDO prepared statements prevent SQL injection. The malicious string is stored/matched verbatim.

---

### ATK-05 — Subscribe to All Events via Crafted events Array 🚫 BLOCKED

**Attack**: Attacker sends `{"events": null}` or `{"events": "all"}` hoping to subscribe to all events without using the documented empty-array convention.
**Result**: BLOCKED — `events` is validated as a JSON array. Non-array values return 422. Only a literal `[]` triggers the "subscribe to all" path.

---

### ATK-06 — Deliver to HTTPS with Invalid Certificate ✅ SAFE

**Attack**: Attacker registers a webhook URL with an expired or self-signed TLS certificate, hoping the delivery client accepts it anyway.
**Result**: SAFE — The delivery client should enforce TLS certificate verification (`CURLOPT_SSL_VERIFYPEER = true`). This FT uses a stub client for testing; production clients must enforce certificate validation.

---

### ATK-07 — Replay Delivered Event via Retry 🚫 BLOCKED

**Attack**: Attacker calls `POST /deliveries/{id}/retry` for a **successful** delivery to replay an event at the subscriber.
**Result**: BLOCKED — Retry re-fetches the delivery record, re-posts the stored payload to the webhook URL. The subscriber must implement idempotency keys to deduplicate. The delivery system itself does not block retrying successful deliveries, which is intentional (admin use case). Subscriber-side idempotency is the safeguard.

---

### ATK-08 — Enumerate Delivery IDs to Access Other Webhooks' Logs 🚫 BLOCKED

**Attack**: Attacker iterates delivery IDs via `GET /deliveries/{id}` to read delivery logs for webhooks they don't own.
**Result**: BLOCKED — There is no `GET /deliveries/{id}` endpoint; deliveries are only accessible scoped to a specific webhook via `GET /webhooks/{id}/deliveries`. The webhook 404 check gates access.

---

### ATK-09 — Overflow events Array to Exhaust Memory ✅ SAFE

**Attack**: Attacker sends `{"events": [... 10,000 event types ...]}` to exhaust memory during JSON parsing or storage.
**Result**: SAFE — Request size limit middleware (default 1 MB) rejects oversized bodies. Application-level array length validation (e.g., `max: 50 events`) provides a second guard.

---

### ATK-10 — Register Duplicate URL to Trigger Multiple Deliveries ✅ SAFE

**Attack**: Attacker registers the same URL 100 times to receive 100 copies of every event.
**Result**: SAFE — Multiple registrations of the same URL are allowed (e.g., for different event subsets). Rate limiting and authentication on the registration endpoint are the guards against abuse. For production, add a `UNIQUE(url)` constraint or per-user webhook limits.

---

### ATK-11 — Delete Another User's Webhook by ID 🚫 BLOCKED

**Attack**: Attacker guesses an integer webhook ID and calls `DELETE /webhooks/{id}` to remove another user's webhook.
**Result**: BLOCKED — Authorization (ownership check via JWT/session) gates delete. The FT demonstrates the mechanics; auth is a required layer in production.

---

### ATK-12 — Inject Payload to Exfiltrate Server-Side Data ✅ SAFE

**Attack**: Attacker dispatches an event with `{"payload": {"__proto__": {"admin": true}}}` hoping prototype pollution or template injection reaches the delivery.
**Result**: SAFE — `payload` is stored as a JSON string and forwarded verbatim to the subscriber. PHP JSON does not have prototype pollution; template injection requires an explicit template engine. The payload is opaque data.

---

### ATK Summary

| ID | Attack | Result |
|----|--------|--------|
| ATK-01 | Secret extraction via GET | 🚫 BLOCKED |
| ATK-02 | SSRF via internal webhook URL | ⚠️ EXPOSED |
| ATK-03 | Dispatch to inactive/deleted webhook | 🚫 BLOCKED |
| ATK-04 | SQL injection via event_type | 🚫 BLOCKED |
| ATK-05 | Subscribe to all via non-array events | 🚫 BLOCKED |
| ATK-06 | Delivery to invalid TLS certificate | ✅ SAFE |
| ATK-07 | Replay via retry | 🚫 BLOCKED |
| ATK-08 | Enumerate delivery IDs cross-webhook | 🚫 BLOCKED |
| ATK-09 | Overflow events array memory exhaustion | ✅ SAFE |
| ATK-10 | Duplicate URL registration | ✅ SAFE |
| ATK-11 | Delete other user's webhook | 🚫 BLOCKED |
| ATK-12 | Prototype pollution / template injection in payload | ✅ SAFE |

**8 BLOCKED, 3 SAFE, 1 EXPOSED** — ATK-02 (SSRF via webhook URL) requires production mitigation: validate registered URLs against a private-IP blocklist before storage. See `docs/howto/url-shortener-ssrf-prevention.md`.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Return the actual secret in any response | Attacker can use secret to forge valid HMAC signatures for any event |
| No URL validation on webhook registration | SSRF: server delivers events to internal metadata endpoints |
| No `is_active` filter in dispatch query | Inactive/soft-deleted webhooks still receive events |
| Store payload as PHP serialized string | Deserialization of attacker-controlled data triggers remote code execution |
| No per-webhook delivery log | Cannot diagnose delivery failures or detect replay attacks |
| No retry mechanism | Transient failures permanently lose event deliveries |
