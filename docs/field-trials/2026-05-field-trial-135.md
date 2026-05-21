# Field Trial 135 — Direct Messaging System (messagelog)

**Date**: 2026-05-21  
**Theme**: ダイレクトメッセージ（DM）システム — 会話スレッドと参加者アクセス制御  
**Project**: `NENE2-FT/messagelog/`  
**NENE2 version**: ^1.5 (released as v1.5.69)  
**Issue**: #774  
**Special**: 脆弱性診断（3FT ごとの診断対象 — FT135）

---

## Overview

This trial implements a direct messaging (DM) system with two-user conversations, message threading, and strict participant-only access control. The key challenges were direction-agnostic conversation deduplication and enforcing access control on GET endpoints that carry identity via header rather than request body.

---

## Implementation Summary

### Schema

Three tables: `users`, `conversations`, `messages`.

- `conversations.UNIQUE (initiator_id, recipient_id)` — prevents duplicate conversations for the same ordered pair
- `conversations.CHECK (initiator_id != recipient_id)` — prevents self-conversations at DB level
- Messages reference both `conversation_id` and `sender_id`

### API

| Method | Path                                    | Status codes      |
|--------|-----------------------------------------|-------------------|
| POST   | `/users`                                | 201, 422          |
| POST   | `/conversations`                        | 200, 201, 404, 422 |
| POST   | `/conversations/{id}/messages`          | 201, 403, 404, 422 |
| GET    | `/conversations/{id}/messages`          | 200, 403, 404     |
| GET    | `/users/{userId}/conversations`         | 200, 403, 404     |

### Key design choices

**Direction-agnostic lookup** — `findConversation()` queries with an OR condition (`initiator=A AND recipient=B OR initiator=B AND recipient=A`) so Alice→Bob and Bob→Alice resolve to the same conversation. The `UNIQUE` constraint is on ordered pair, but the application layer normalizes direction.

**Idempotent conversation start** — `POST /conversations` returns 201 for a new conversation and 200 if one already exists (in either direction). Same `bool`-from-repository pattern as followlog.

**Participant enforcement** — `isParticipant()` is called before both read and write message operations. 403 (not 404) is returned to clearly signal authorization failure.

**X-User-Id header** — GET endpoints need caller identity but have no request body. `resolveActorId()` reads the `X-User-Id` header and falls back to 0 for non-numeric values.

**Message ordering** — `ORDER BY id ASC` (oldest-first), matching chat UI conventions. Contrast with follow/notification lists which use `ORDER BY id DESC`.

---

## Bug Found and Fixed

**VULN-BUG: `JsonRequestBodyParser::parse()` called on GET request**

The initial `listMessages` handler included an unused `$body = JsonRequestBodyParser::parse($request)` call inherited from a POST handler template. GET requests have no JSON body, so `JsonRequestBodyParser` returned 400.

**Fix**: Removed the unnecessary `parse()` call from all GET handlers. Actor identity comes from the `X-User-Id` header only.

---

## Test Results

```
31 tests, 96 assertions — all pass
19 normal tests + 12 vulnerability tests
```

---

## Vulnerability Assessment (FT135)

### Methodology

Twelve targeted tests in `tests/Message/VulnTest.php`. Each test sends a crafted request designed to bypass access control or inject malicious data.

### Findings

| ID | Category | Description | Severity | Status |
|----|----------|-------------|----------|--------|
| VULN-A | IDOR | Non-participant reads another conversation's messages | High | **No issue — returns 403** |
| VULN-B | IDOR | Non-participant sends message to another conversation | High | **No issue — returns 403** |
| VULN-C | IDOR | User reads another user's conversation list | High | **No issue — returns 403** |
| VULN-D | Auth bypass | Missing X-User-Id on list messages | Medium | **No issue — returns 404** |
| VULN-E | Auth bypass | Missing X-User-Id on conversation list | Medium | **No issue — returns 403** |
| VULN-F | Input validation | Negative user ID in path | Low | **No issue — returns 404** |
| VULN-G | Input validation | Zero conversation ID | Low | **No issue — returns 404** |
| VULN-H | Header injection | Non-numeric X-User-Id (e.g. `admin`) | Medium | **No issue — `is_numeric()` rejects → 404** |
| VULN-I | SQL injection | `'; DROP TABLE messages; --` in content | High | **No issue — prepared statements protect** |
| VULN-J | XSS | `<script>alert("xss")</script>` in content | Medium | **No issue — JSON API, no HTML rendering** |
| VULN-K | Logic flaw | Self-conversation | Low | **No issue — returns 422** |
| VULN-L | DoS | 100KB message content | Low | **Accepted — content limit is middleware concern** |

**Overall: No vulnerabilities found.** All 12 tests pass without code changes.

### Observations

- The `is_numeric()` guard on `resolveActorId()` cleanly rejects header injection attempts like `X-User-Id: admin` or `X-User-Id: 1 OR 1=1`.
- SQL injection is fully blocked by PDO prepared statements throughout.
- The existence check order (conversation → sender → participant → content) prevents information leakage about conversation IDs to non-participants.
- XSS in message content is stored verbatim — this is correct for a JSON API. The rendering layer (browser/client) is responsible for escaping. A production system would add content sanitization if the messages are ever rendered in HTML.
- 100KB content is accepted by this FT (no request-size middleware configured). Production deployments should use `RequestSizeLimitMiddleware`.

---

## Developer Experience (DX) Review

### Persona 1: Junko — Junior PHP Developer (2 years experience)

*"The direction-agnostic conversation lookup was confusing at first. Why do I need to query with OR? Then I understood — Alice→Bob and Bob→Alice shouldn't create two different conversations. The howto explains it well. I tripped up calling `JsonRequestBodyParser::parse()` in a GET handler and got a mysterious 400 — I wish the error message was clearer about 'no body expected'."*

**Rating**: ★★★★☆ (4/5)  
**Friction**: 400 on GET with `parse()` is confusing; direction-agnostic lookup needs explanation

---

### Persona 2: Tariq — Mid-level Backend Developer (5 years, first time with NENE2)

*"I appreciate that `isParticipant()` is called in both the read and write paths — I've seen systems where the write path checks authorization but the read path doesn't. The fix for the GET 400 bug is good documentation. The `resolveActorId()` pattern is clean and I can swap it for a JWT claim later."*

**Rating**: ★★★★☆ (4/5)  
**Note**: Authorization symmetry (read AND write both check participant) is explicit and good

---

### Persona 3: Priya — Senior Developer / Tech Lead

*"The UNIQUE constraint is on the ordered pair (A,B) but the application normalizes direction with an OR query. This means a concurrent race where Alice and Bob both start a conversation simultaneously could create two rows. The DB constraint would catch one of them — but the application would need to catch `DatabaseConstraintException` on INSERT. Not caught here; acceptable for FT scale but a production gap."*

**Rating**: ★★★☆☆ (3/5)  
**Concern**: Race condition on concurrent `findOrCreateConversation` — missing `DatabaseConstraintException` catch

---

### Persona 4: Markus — DevOps / Platform Engineer

*"Twelve vuln tests run in half a second. The SQLite temp-file isolation pattern continues to work well. One operational concern: messages have no content length limit at the application layer — a 100KB message is accepted. The `RequestSizeLimitMiddleware` needs to be wired up in production. The howto mentions this but the default AppFactory doesn't include it."*

**Rating**: ★★★★☆ (4/5)  
**Gap**: No content size limit — production needs `RequestSizeLimitMiddleware`

---

### Persona 5: Amara — QA / Test Engineer

*"The bug found — `JsonRequestBodyParser::parse()` on a GET request → 400 — was caught by the normal tests before the vuln tests ran. Good regression coverage. The vuln tests cover IDOR, auth bypass, injection, and DoS — solid breadth. Missing: concurrent conversation creation race (would require threading/parallel requests, hard to test in PHPUnit)."*

**Rating**: ★★★★★ (5/5)  
**Highlight**: Bug caught by normal tests before vuln assessment — good test coverage

---

### Persona 6: Keiko — Non-technical Product Manager

*"A DM system! I can understand the tests as a spec: 'Alice and Bob can have a conversation, Carol can't read it, sending an empty message fails.' That's clear. My question: can a user delete a message or unsend it? The API doesn't have that — I'd want it for a real product. Also: can a user block someone from messaging them? That would require a block list."*

**Rating**: ★★★☆☆ (3/5)  
**Feature gaps**: No message delete/unsend, no block list — expected at FT scale

---

### DX Summary

| Persona | Rating | Primary concern |
|---------|--------|-----------------|
| Junko (Junior PHP) | ★★★★☆ | GET + parse() 400 error, direction-agnostic concept |
| Tariq (Mid backend) | ★★★★☆ | Authorization symmetry is good; resolveActorId swappable |
| Priya (Tech Lead) | ★★★☆☆ | Race condition on concurrent findOrCreateConversation |
| Markus (DevOps) | ★★★★☆ | No content size limit; needs middleware in production |
| Amara (QA) | ★★★★★ | Bug caught early; solid vuln test breadth |
| Keiko (PM) | ★★★☆☆ | Missing delete/unsend, block list |

**Overall DX**: ★★★★☆ (3.8/5) — Access control is well-enforced. The direction-agnostic conversation pattern solves a real problem cleanly. Main gaps are production-readiness (race condition, content limit) and missing user-facing features (delete, block).

---

## Issues Raised for NENE2

None. Framework behaviour was as expected throughout.

---

## Howto

`docs/howto/direct-messaging-system.md`
