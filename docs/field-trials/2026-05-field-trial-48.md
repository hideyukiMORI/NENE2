# Field Trial 48 — Webhook Delivery System (webhooklog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/webhooklog/`
**NENE2 version**: 1.5.18
**Theme**: Webhook registration + event dispatching + delivery tracking with retry, interface-based HTTP client, multi-table state machines

## Overview

Built a webhook delivery system API. Users register webhook endpoints (URL + optional secret + event type subscriptions), then dispatch events. The system fans out to all active matching webhooks and records each delivery attempt (status, HTTP status, response body, error). Failed deliveries can be retried.

## Endpoints Implemented

- `POST /webhooks` — register webhook (url, secret, events[])
- `GET /webhooks` — list all webhooks
- `GET /webhooks/{id}` — show webhook
- `DELETE /webhooks/{id}` — unregister webhook
- `GET /webhooks/{id}/deliveries` — list delivery attempts for a webhook
- `POST /events/dispatch` — dispatch event_type + payload to matching webhooks (fan-out)
- `POST /deliveries/{id}/retry` — retry a specific delivery

## Test Results

19 tests, 41 assertions — all pass. PHPStan level 8: no errors. PHP-CS-Fixer: no issues.

---

## Frictions Found

### Friction 1 — `Router::PARAMETERS_ATTRIBUTE` is required; direct `getAttribute('id')` returns null [MEDIUM]

**Symptom**: `GET /webhooks/1` returned 404. All ID-based routes failed silently.

**Root cause**: `Router` stores path parameters under `$request->getAttribute(Router::PARAMETERS_ATTRIBUTE)` (a named constant `'nene2.route.parameters'`), not directly as individual attributes. Calling `$request->getAttribute('id')` returns `null`, causing `(int) null === 0` which then fails to find the record.

**Fix**:
```php
// Wrong — returns null
$id = (int) $request->getAttribute('id');

// Correct — unpack from PARAMETERS_ATTRIBUTE
$params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
$id     = (int) ($params['id'] ?? 0);
```

**NENE2 impact**: This pattern is already documented in some howto files, but is easily missed when starting from scratch. The `add-database-endpoint.md` howto should include a clear example of the `Router::PARAMETERS_ATTRIBUTE` pattern in path parameter extraction sections.

---

## Patterns Validated

### Interface-based HTTP client for testability

```php
interface HttpDeliveryClientInterface {
    /** @param array<string, mixed> $payload */
    public function post(string url, array $payload, string $secret): DeliveryResult;
}
```

Two test implementations (`SuccessDeliveryClient`, `FailDeliveryClient`) allow testing both success and failure paths without real HTTP calls.

### Backed enum for delivery status

```php
enum DeliveryStatus: string {
    case Pending = 'pending';
    case Success = 'success';
    case Failed  = 'failed';
}
```

`DeliveryStatus::from((string) $row['status'])` cleanly deserializes from SQLite text.

### Fan-out dispatch (loop over matching webhooks)

```php
$webhooks = $repo->findActiveForEvent($eventType);
foreach ($webhooks as $webhook) {
    $deliveries[] = $repo->deliver($webhook, $eventType, $payload, $client, $now)->toArray();
}
```

`events = []` (empty array) means "subscribe to all events". Matching uses `in_array()`.

### JSON serialization for event lists in SQLite

Webhook `events[]` stored as JSON text in SQLite. `json_encode` on insert, `json_decode` on hydrate:
```php
$eventsJson = json_encode($events, JSON_THROW_ON_ERROR);
// INSERT ... VALUES (?, ..., ?, ...)  -- $eventsJson as string
$events = json_decode((string) $row['events'], true, 512, JSON_THROW_ON_ERROR);
```

### Delivery retry with UPDATE

```php
$this->executor->execute(
    'UPDATE deliveries SET status = ?, http_status = ?, response = ?, error = ?, attempted_at = ? WHERE id = ?',
    [$status->value, $result->httpStatus, $result->response, $result->error, $now, $deliveryId],
);
```

Retry re-uses the existing delivery row rather than creating a new one, keeping history clean.

### Secret masking in API response

```php
public function toArray(): array {
    return [
        'secret' => $this->secret !== '' ? '***' : '',
        // ...
    ];
}
```

The secret is stored in DB for HMAC signing (out of scope for this FT) but masked in all API responses.

---

## NENE2 Changes Required

- **Howto update**: `docs/howto/add-database-endpoint.md` should have a prominent example of `Router::PARAMETERS_ATTRIBUTE` in its path parameter section — this is the second or third time a FT hit this gotcha on first use (the constant name is not intuitive). LOW priority since workaround is a one-liner, but HIGH discoverability value.

No version bump needed (no NENE2 code changes; only doc enhancement candidate).
