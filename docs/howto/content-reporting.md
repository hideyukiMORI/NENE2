# How-to: Content Reporting System

> **FT reference**: FT289 (`NENE2-FT/reportlog`) — Content reporting: allowlisted reasons (ReportReason enum), UNIQUE(reporter_id, article_id) with idempotent 200 on duplicate, pending→resolved/dismissed state machine, moderator-only list/resolve/dismiss, DB-level CHECK constraints, 32 tests / 58 assertions PASS.

This guide shows how to build a content reporting system where users flag content and moderators review and resolve reports.

## Schema

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user',
    created_at TEXT NOT NULL,
    CHECK (role IN ('user', 'moderator'))
);

CREATE TABLE reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reporter_id INTEGER NOT NULL,
    article_id INTEGER NOT NULL,
    reason TEXT NOT NULL,
    details TEXT,
    status TEXT NOT NULL DEFAULT 'pending',
    resolved_by INTEGER,
    resolved_at TEXT,
    resolution_note TEXT,
    created_at TEXT NOT NULL,
    UNIQUE (reporter_id, article_id),
    CHECK (status IN ('pending', 'resolved', 'dismissed')),
    CHECK (reason IN ('spam', 'harassment', 'misinformation', 'other')),
    FOREIGN KEY (reporter_id) REFERENCES users(id),
    FOREIGN KEY (article_id) REFERENCES articles(id),
    FOREIGN KEY (resolved_by) REFERENCES users(id)
);
```

DB-level `CHECK` constraints enforce enum values even if application validation is bypassed.

## Endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/reports` | `X-User-Id` | Submit a report |
| `GET` | `/reports` | Moderator | List all reports |
| `GET` | `/reports/{id}` | Reporter or Moderator | Get report |
| `PUT` | `/reports/{id}/resolve` | Moderator | Resolve report |
| `PUT` | `/reports/{id}/dismiss` | Moderator | Dismiss report |

## ReportReason Enum

```php
enum ReportReason: string
{
    case Spam         = 'spam';
    case Harassment   = 'harassment';
    case Misinformation = 'misinformation';
    case Other        = 'other';
}
```

`ReportReason::tryFrom($reasonStr)` rejects unknown values. The handler returns valid reasons in the error response:

```php
$reason = ReportReason::tryFrom($reasonStr);
if ($reason === null) {
    $validReasons = array_map(fn(ReportReason $r) => $r->value, ReportReason::cases());
    return $this->responseFactory->create(['error' => 'invalid reason', 'valid_reasons' => $validReasons], 422);
}
```

## Idempotent Report Submission

If a user already reported the same article, return the existing report with 200 (not 201):

```php
$existing = $this->repository->findReportByReporterAndArticle($actorId, $articleId);
if ($existing !== null) {
    return $this->responseFactory->create($this->formatReport($existing), 200);
}

// First time: 201 Created
$id = $this->repository->createReport(...);
return $this->responseFactory->create($this->formatReport(...), 201);
```

`UNIQUE(reporter_id, article_id)` backs this up at the DB level. The application checks first to return a friendly response, but the UNIQUE constraint is the safety net.

## Status Lifecycle

```
pending ──→ resolved (moderator action)
       └──→ dismissed (moderator action)
```

Once resolved or dismissed, a report cannot transition. Attempting to change a non-pending report returns 422:

```php
if ($report['status'] !== 'pending') {
    return $this->responseFactory->create([
        'error' => 'report is not pending',
        'current_status' => $report['status'],
    ], 422);
}
```

## Moderator Role Check

```php
$actor = $this->repository->findUserById($actorId);
if ($actor === null || $actor['role'] !== 'moderator') {
    return $this->responseFactory->create(['error' => 'moderator role required'], 403);
}
```

Role is stored in the `users` table and checked on every privileged operation. A DB-level `CHECK (role IN ('user', 'moderator'))` prevents invalid roles from being inserted.

## Access Control: Reporter vs Moderator

GET `/reports/{id}` is accessible to both the original reporter and moderators:

```php
$isModerator = $actor['role'] === 'moderator';
$isReporter  = (int)$report['reporter_id'] === $actorId;

if (!$isModerator && !$isReporter) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

Reporters can view their own reports to track status. Moderators see all reports.

## Resolution with Audit Trail

```php
$this->repository->updateReportStatus($id, $newStatus, $actorId, date('c'), $note);
```

`resolved_by` (moderator ID), `resolved_at` (timestamp), and `resolution_note` (optional) create an audit trail for every moderation action.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Accept free-form reason string | Typos, injection, infinite categories; use enum allowlist |
| No `UNIQUE(reporter_id, article_id)` | Same user submits dozens of reports for same article; bloated queue |
| Return 409 on duplicate report | Retry-safe idempotency: duplicate → 200 with existing report, not error |
| Allow transition from resolved/dismissed | Resolved report re-opened; audit trail becomes unreliable |
| No moderator role check on list/resolve | Any user reads all reports; privacy violation + audit bypass |
| Return reporter's own report to another user | IDOR — always check reporter === actor or actor is moderator |
| No `resolution_note` field | Moderators cannot communicate why a report was dismissed vs resolved |
| No `resolved_by` field | Cannot audit which moderator took action |
| DB CHECK only, no app validation | DB throws exception on invalid reason; user gets 500 instead of 422 |
