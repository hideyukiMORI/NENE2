# Field Trial 5 — MCP Write Tools (v0.4.0)

## Date

2026-05-17

## Baseline

- NENE2 v0.4.0 (git clone / local repository)
- MCP write tools: `createExampleNote`, `updateExampleNoteById`, `deleteExampleNoteById` (added in #228)
- Local MCP server via `tools/mcp-smoke.sh`
- App container: `docker compose up -d app`
- Database: MySQL (default adapter)

## Goal

Validate that the MCP write tools added in v0.4.0 work end-to-end through the local MCP server, covering `POST`, `PUT`, and `DELETE` operations against the Note endpoints.

## Steps Taken

### 1. Start app container

```bash
docker compose up -d app
curl -sf http://localhost:8085/health  # → {"status":"ok","service":"NENE2"}
```

### 2. Verify tools/list includes write tools

```bash
bash tools/mcp-smoke.sh
```

All 8 tools appeared. Write tools (`createExampleNote`, `updateExampleNoteById`, `deleteExampleNoteById`) showed `"readOnlyHint": false`.

### 3. Attempt createExampleNote — 500 error

First attempt returned HTTP 500:

```json
{"statusCode": 500, "body": {"type": "https://nene2.dev/problems/internal-server-error", ...}}
```

Root cause: MySQL container was not running. The `mcp-smoke.sh` precondition comment says only `docker compose up -d app`, with no mention of database requirements for write operations.

### 4. Start MySQL and run migrations

```bash
docker compose up -d mysql
docker compose run --rm app composer migrations:migrate
# CreateNotesTable → up
```

### 5. createExampleNote → 201

```bash
bash tools/mcp-smoke.sh createExampleNote '{"title":"Field Trial 5 Note","body":"MCP write tools observation test"}'
```

Result: `201 Created`, id=1, title and body confirmed.

### 6. updateExampleNoteById → 200

```bash
bash tools/mcp-smoke.sh updateExampleNoteById '{"id":1,"title":"Field Trial 5 Updated","body":"PUT operation confirmed via MCP"}'
```

Result: `200 OK`. Path parameter `{id}` was interpolated correctly from the `id` argument. Updated title and body confirmed in the response body.

### 7. getExampleNoteById to verify update → 200

Confirmed persisted title = "Field Trial 5 Updated".

### 8. deleteExampleNoteById → 204

```bash
bash tools/mcp-smoke.sh deleteExampleNoteById '{"id":1}'
```

Result: `204 No Content`, `body: ""`.

### 9. getExampleNoteById after delete → 404

```bash
bash tools/mcp-smoke.sh getExampleNoteById '{"id":1}'
```

Result: `404 Not Found` Problem Details with `isError: true`. Detail message: "The requested note was not found."

### 10. Validation error — 422

```bash
bash tools/mcp-smoke.sh createExampleNote '{"title":"","body":"empty title test"}'
```

Result: `422 Validation Failed` with structured `errors` array: `{"field":"title","message":"Title is required.","code":"required"}`. `isError: true` was set correctly.

## Results

| Operation | Expected | Actual | Status |
|---|---|---|---|
| `tools/list` write tools appear | 3 write tools with `readOnlyHint: false` | ✅ | Pass |
| `createExampleNote` POST | 201 + body | 201 + `{id:1, title, body}` | Pass |
| `updateExampleNoteById` PUT with `{id}` | 200 + updated body | 200 + updated body | Pass |
| Path param interpolation | `{id}` → `1` | Confirmed | Pass |
| `deleteExampleNoteById` DELETE | 204 | 204 | Pass |
| GET after delete | 404 Problem Details | 404 + `isError: true` | Pass |
| Validation error | 422 + `errors` array | 422 + structured errors | Pass |

All write tool operations functioned correctly end-to-end.

## Friction Observed

### F-1: `mcp-smoke.sh` precondition does not mention database

The script header says:
```
# Precondition: docker compose up -d app
```

Write operations require MySQL to be running and migrations to be applied. Without this, the first `createExampleNote` call returns a generic 500 with no actionable detail.

**Impact**: A developer following only the smoke script comment will encounter a 500 error on the first write tool call with no clear fix path.

**Candidate fix**: Add `docker compose up -d mysql && docker compose run --rm app composer migrations:migrate` to the precondition comment for write operations, or add a note distinguishing read-only vs write preconditions.

### F-2: 500 response body lacks diagnostic context

The internal server error response (`application/problem+json`) gives no indication of what failed. The request ID is present and can be used to correlate logs, but that step is not documented in the smoke workflow.

**Impact**: Low — the request ID was present and the fix (start MySQL, run migrations) is discoverable. But the first-failure experience is rough.

**Candidate fix**: Document the "first 500 → check MySQL + migrations" debug step in the smoke workflow or local MCP server guidance.

## Follow-up Issues

- [ ] `mcp-smoke.sh` の precondition コメントに MySQL + migrations を追記する (#245)
- [ ] ローカル MCP サーバーガイドに write 操作の DB 前提条件を追記する (#246)

## Conclusion

MCP write tools work correctly end-to-end. Path parameter interpolation, HTTP method routing, and error response surfacing (`isError: true` for 4xx/5xx) all behaved as expected. The only friction was an undocumented database precondition that caused an opaque 500 error on the first write attempt.
