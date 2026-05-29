---
title: "How to Add an Inbound Webhook Receiver"
category: infrastructure
tags: [webhook, hmac, signature-verification, idempotency]
difficulty: intermediate
related: [inbound-webhook-gateway, webhook-signature-verification, webhook-signature]
---

# How to Add an Inbound Webhook Receiver

Receive webhooks from multiple external services, validate HMAC signatures per source, and store events with idempotency.

## Schema

```sql
CREATE TABLE webhook_sources (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE, secret TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL
);
CREATE TABLE inbound_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id INTEGER NOT NULL REFERENCES webhook_sources(id),
    event_id TEXT NOT NULL, event_type TEXT NOT NULL,
    payload TEXT NOT NULL, processed_at TEXT NOT NULL,
    UNIQUE(source_id, event_id)
);
```

## Routes

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/sources` | Register a webhook source |
| `POST` | `/sources/{id}/receive` | Receive a webhook |
| `GET` | `/sources/{id}/events` | List received events |
| `GET` | `/events/{id}` | Get a specific event |

## HMAC-SHA256 Signature Validation

Each source has its own HMAC secret. Never expose it in responses.

```php
private function verifySignature(string $body, string $header, string $secret): bool
{
    if (!str_starts_with($header, 'sha256=')) {
        return false;
    }
    $expected = hash_hmac('sha256', $body, $secret);
    return hash_equals($expected, substr($header, 7)); // timing-safe
}
```

Call order: **validate signature first**, then idempotency check, then store:

```php
if (!$this->verifySignature($rawBody, $sigHeader, $source['secret'])) {
    return $this->json->create(['error' => 'Invalid signature'], 401);
}
// ... idempotency check ...
$this->repo->storeEvent($sourceId, $eventId, $eventType, $rawBody, $now);
```

## Idempotency (event_id per source)

```php
$existing = $this->repo->findEventBySourceAndEventId($sourceId, $eventId);
if ($existing !== null) {
    return $this->json->create(['status' => 'already_processed', 'id' => $existing['id']]);
}
```

The `UNIQUE(source_id, event_id)` constraint is the DB-level backstop. The PHP check above avoids the exception path on first duplicate.

## Never Expose the Secret

```php
$source = $this->repo->findSource($id);
unset($source['secret']); // strip before returning
return $this->json->create($source, 201);
```

## Inactive Source Check

```php
if (!(bool) $source['active']) {
    return $this->json->create(['error' => 'Source is inactive'], 403);
}
```

## MySQL Notes

The `UNIQUE KEY uq_source_event (source_id, event_id)` constraint works the same in MySQL. Use `VARCHAR(191)` for indexed text columns to stay within InnoDB's key length limit.

### Running MySQL Integration Tests

Start the shared FT MySQL container (port 3308, persistent volume):

```bash
docker compose -f ../NENE2-FT/docker-compose.yml up -d mysql
```

Then run the integration tests with environment variables:

```bash
MYSQL_HOST=127.0.0.1 MYSQL_PORT=3308 MYSQL_DATABASE=ft_test \
  MYSQL_USER=ft_user MYSQL_PASSWORD=ft_pass \
  php8.4 vendor/bin/phpunit --filter Mysql
```

Without `MYSQL_HOST`, the MySQL tests are automatically skipped (`markTestSkipped`).

## Security Notes

- `hash_equals()` prevents timing attacks on signature comparison.
- Raw JSON body is stored as-is; do not parse before signature verification.
- Same `event_id` from two different sources creates separate records — the UNIQUE constraint is `(source_id, event_id)`, not just `event_id`.
