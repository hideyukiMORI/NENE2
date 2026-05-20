# Field Trial 43 — Batch Import with Partial Success (batchlog)

**Date**: 2026-05-20
**Project**: `/home/xi/docker/NENE2-FT/batchlog/`
**NENE2 version**: 1.5.17
**Theme**: Bulk import endpoint, per-row success/failure tracking, job persistence, transaction scoping for partial success

## Overview

Built a contact import API that accepts a JSON array of up to 500 contacts, processes each row independently, and returns a structured job result with per-row status (`ok` / `error`). Successfully imported rows are committed; failed rows are recorded with an error code and message. All job metadata and result rows are persisted atomically via a single transaction after row processing completes.

## Endpoints Implemented

- `POST /import` — run batch import; accepts `{contacts: [{email, name}, ...]}`, returns job with per-row results (201)
- `GET /import/{id}` — fetch a previously run job with all result rows

## Test Results

12 tests, 44 assertions — pass with no fixes required.

---

## Frictions Found

None. All patterns worked as expected on first attempt.

---

## Patterns Validated

### Partial-success architecture: process rows outside transaction

```php
// Row processing happens OUTSIDE the transaction
foreach ($rows as $i => $row) {
    $result = $this->processRow($i, $row, $now);
    $results[] = $result;
    ...
}

// Only job metadata + result rows go inside a transaction
$jobId = $this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use (...): int {
    $jobId = $tx->insert('INSERT INTO import_jobs (total, succeeded, failed, created_at) VALUES (?, ?, ?, ?)', [...]);
    foreach ($results as $r) {
        $tx->insert('INSERT INTO import_results (...) VALUES (...)', [...]);
    }
    return $jobId;
});
```

If row processing were inside a transaction, any exception would roll back previously successful inserts. Keeping contact inserts outside the transaction allows partial success, while the job-record transaction ensures consistent summary counts.

### Per-row result with typed error codes

```php
final readonly class ImportItemResult
{
    public static function ok(int $rowIndex, string $email, int $contactId): self { ... }
    public static function error(int $rowIndex, ?string $email, string $errorCode, string $errorMessage): self { ... }
}
```

Named factory methods avoid nullable field confusion and make call sites read cleanly.

### Input validation: batch size limits

```php
if (!isset($body['contacts']) || !is_array($body['contacts'])) {
    $errors[] = new ValidationError('contacts', 'contacts must be an array.', 'required');
} elseif (count($body['contacts']) === 0) {
    $errors[] = new ValidationError('contacts', 'contacts array must not be empty.', 'min-items');
} elseif (count($body['contacts']) > 500) {
    $errors[] = new ValidationError('contacts', 'contacts array must not exceed 500 items.', 'max-items');
}
```

The `min-items` / `max-items` error codes are custom — NENE2 `ValidationError` takes an arbitrary `code` string.

### Duplicate detection before insert

```php
$existing = $this->executor->fetchOne('SELECT id FROM contacts WHERE email = ?', [$email]);
if ($existing !== null) {
    return ImportItemResult::error($index, $email, 'duplicate-email', "...");
}
$contactId = $this->executor->insert('INSERT INTO contacts (email, name, created_at) VALUES (?, ?, ?)', [...]);
```

Read-then-write outside a transaction is acceptable for batch import (race condition risk is low; the `UNIQUE` constraint on `email` acts as the ultimate guard and would surface as a `DatabaseConnectionException` if it fires).

### All-failed jobs still return 201

The HTTP 201 indicates the job was created and persisted, not that all rows succeeded. The caller must inspect `succeeded` / `failed` counts and per-row `status` to determine actual outcome. This matches the "job was accepted and processed" semantic, not "all items were successful".

### Response shape

```json
{
  "id": 1,
  "total": 3,
  "succeeded": 2,
  "failed": 1,
  "created_at": "2026-05-20T12:00:00Z",
  "results": [
    {"row_index": 0, "status": "ok", "email": "a@example.com", "contact_id": 1, "error_code": null, "error_message": null},
    {"row_index": 1, "status": "error", "email": null, "contact_id": null, "error_code": "missing-email", "error_message": "email is required."},
    {"row_index": 2, "status": "ok", "email": "c@example.com", "contact_id": 2, "error_code": null, "error_message": null}
  ]
}
```

---

## NENE2 Changes Required

None — zero frictions. `transactional()`, `fetchOne()`, `insert()`, `JsonRequestBodyParser`, `ValidationException`, and `ProblemDetailsResponseFactory` all composed cleanly.

No version bump needed.
