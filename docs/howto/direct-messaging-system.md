# How to Build a Direct Messaging System with NENE2

> **FT reference**: FT278 (`NENE2-FT/messagelog`) — Direct messaging: conversation threading, UNIQUE(initiator_id, recipient_id) + CHECK(initiator_id != recipient_id), participant-only access control, direction-agnostic lookup, idempotent conversation start, 31 tests / 96 assertions PASS.
>
> Also validated in FT135 — original implementation.

This guide walks through building a Twitter/Instagram-style direct message (DM) system — users start conversations with each other, send messages, and only participants can read or send in a conversation.

**NENE2 version**: ^1.5  
**Covered topics**: conversation threading, participant access control, direction-agnostic conversation lookup, idempotent conversation start

---

## What we're building

A REST API where:

- Any two users can start a conversation (idempotent — re-starting returns the existing one)
- Only participants can send messages or read a conversation's messages
- A user can list their own conversations (but not another user's)
- Messages are ordered oldest-first within a conversation

---

## Database schema

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE conversations (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    initiator_id INTEGER NOT NULL,
    recipient_id INTEGER NOT NULL,
    created_at   TEXT    NOT NULL,
    UNIQUE (initiator_id, recipient_id),
    CHECK  (initiator_id != recipient_id),
    FOREIGN KEY (initiator_id) REFERENCES users(id),
    FOREIGN KEY (recipient_id) REFERENCES users(id)
);

CREATE TABLE messages (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id INTEGER NOT NULL,
    sender_id       INTEGER NOT NULL,
    content         TEXT    NOT NULL,
    created_at      TEXT    NOT NULL,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id),
    FOREIGN KEY (sender_id)       REFERENCES users(id)
);
```

The `UNIQUE (initiator_id, recipient_id)` constraint enforces one conversation per ordered pair. The application layer handles the reverse direction (Bob→Alice returns the same conversation as Alice→Bob).

---

## API endpoints

| Method | Path                                   | Description                                  |
|--------|----------------------------------------|----------------------------------------------|
| POST   | `/users`                               | Create a user                                |
| POST   | `/conversations`                       | Start a conversation (idempotent)            |
| POST   | `/conversations/{id}/messages`         | Send a message (participants only)           |
| GET    | `/conversations/{id}/messages`         | Read messages (participants only, X-User-Id) |
| GET    | `/users/{userId}/conversations`        | List user's conversations (self only, X-User-Id) |

---

## Direction-agnostic conversation lookup

The key challenge: Alice starts a conversation with Bob (`initiator=Alice, recipient=Bob`). Later Bob also starts one with Alice. They should get the same conversation, not two separate ones.

```php
public function findConversation(int $userA, int $userB): ?int
{
    $row = $this->executor->fetchOne(
        'SELECT id FROM conversations
         WHERE (initiator_id = ? AND recipient_id = ?)
            OR (initiator_id = ? AND recipient_id = ?)',
        [$userA, $userB, $userB, $userA],
    );

    if ($row === null) {
        return null;
    }

    $arr = (array) $row;

    return isset($arr['id']) ? (int) $arr['id'] : null;
}

public function findOrCreateConversation(int $initiatorId, int $recipientId, string $now): int
{
    $existing = $this->findConversation($initiatorId, $recipientId);

    if ($existing !== null) {
        return $existing;
    }

    $this->executor->execute(
        'INSERT INTO conversations (initiator_id, recipient_id, created_at) VALUES (?, ?, ?)',
        [$initiatorId, $recipientId, $now],
    );

    return (int) $this->executor->lastInsertId();
}
```

---

## Participant check

Before reading messages or sending, verify the caller is in the conversation:

```php
public function isParticipant(int $conversationId, int $userId): bool
{
    return $this->executor->fetchOne(
        'SELECT id FROM conversations
         WHERE id = ? AND (initiator_id = ? OR recipient_id = ?)',
        [$conversationId, $userId, $userId],
    ) !== null;
}
```

---

## Actor identity — X-User-Id header

Protected endpoints use a simple `X-User-Id` header to identify the caller. Production systems would use a JWT claim instead.

```php
private function resolveActorId(ServerRequestInterface $request): int
{
    $header = $request->getHeaderLine('X-User-Id');

    return is_numeric($header) ? (int) $header : 0;
}
```

**Note**: `is_numeric()` returns false for non-numeric strings, so `X-User-Id: admin` → `actorId = 0` → 404.

---

## Send message handler

```php
private function sendMessage(ServerRequestInterface $request): ResponseInterface
{
    $params         = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $conversationId = isset($params['conversationId']) && is_numeric($params['conversationId'])
        ? (int) $params['conversationId'] : 0;

    if ($conversationId <= 0 || $this->repo->findConversationById($conversationId) === null) {
        return $this->responseFactory->create(['error' => 'conversation not found'], 404);
    }

    $body     = JsonRequestBodyParser::parse($request);
    $senderId = isset($body['sender_id']) && is_int($body['sender_id']) ? $body['sender_id'] : 0;
    $content  = isset($body['content']) && is_string($body['content']) ? trim($body['content']) : '';

    if ($senderId <= 0 || !$this->repo->findUserById($senderId)) {
        return $this->responseFactory->create(['error' => 'sender not found'], 404);
    }

    if (!$this->repo->isParticipant($conversationId, $senderId)) {
        return $this->responseFactory->create(['error' => 'not a participant'], 403);
    }

    if ($content === '') {
        return $this->responseFactory->create(['error' => 'content is required'], 422);
    }

    $now       = date('Y-m-d H:i:s');
    $messageId = $this->repo->sendMessage($conversationId, $senderId, $content, $now);

    return $this->responseFactory->create([...], 201);
}
```

**Order of checks**: conversation exists → sender exists → sender is participant → content valid. Existence checks before access checks prevents information leakage about conversation IDs.

---

## Read messages handler — GET with no body

For GET endpoints that require identity (`listMessages`, `listUserConversations`), the actor comes from the `X-User-Id` header. **Do not call `JsonRequestBodyParser::parse()` on GET requests** — it returns 400 because GET requests have no JSON body.

```php
private function listMessages(ServerRequestInterface $request): ResponseInterface
{
    $params         = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $conversationId = isset($params['conversationId']) && is_numeric($params['conversationId'])
        ? (int) $params['conversationId'] : 0;

    if ($conversationId <= 0 || $this->repo->findConversationById($conversationId) === null) {
        return $this->responseFactory->create(['error' => 'conversation not found'], 404);
    }

    // No JsonRequestBodyParser::parse() here — actor comes from header only
    $actorId = $this->resolveActorId($request);

    if ($actorId <= 0 || !$this->repo->findUserById($actorId)) {
        return $this->responseFactory->create(['error' => 'actor not found'], 404);
    }

    if (!$this->repo->isParticipant($conversationId, $actorId)) {
        return $this->responseFactory->create(['error' => 'not a participant'], 403);
    }

    $messages = $this->repo->listMessages($conversationId);

    return $this->responseFactory->create(['items' => $messages, 'count' => count($messages)]);
}
```

---

## Message ordering

Messages use `ORDER BY id ASC` — oldest first, matching chat UI conventions. Follow/notification lists use `ORDER BY id DESC` (newest first). Choose based on UI expectation.

---

## Vulnerability assessment (FT135)

Twelve vulnerability tests verify:

| ID | Attack | Expected | Result |
|----|--------|----------|--------|
| VULN-A | Read messages from another user's conversation (IDOR) | 403 | Pass |
| VULN-B | Send message to conversation you're not part of (IDOR) | 403 | Pass |
| VULN-C | Read another user's conversation list (IDOR) | 403 | Pass |
| VULN-D | Missing X-User-Id on list messages | 404/403 | Pass |
| VULN-E | Missing X-User-Id on conversation list | 403 | Pass |
| VULN-F | Negative user ID in path | 404 | Pass |
| VULN-G | Zero conversation ID in path | 404 | Pass |
| VULN-H | Non-numeric X-User-Id header | not 200 | Pass |
| VULN-I | SQL injection in message content | 201 (stored verbatim) | Pass |
| VULN-J | XSS in message content | 201 (stored verbatim) | Pass |
| VULN-K | Self-conversation attempt | 422 | Pass |
| VULN-L | 100KB message content | 201 or 413 | Pass |

All 12 vulnerability tests pass. No vulnerabilities found.

---

## Common pitfalls

| Pitfall | Fix |
|---------|-----|
| Calling `JsonRequestBodyParser::parse()` on GET requests | Only call it for POST/PUT/PATCH handlers that expect a body |
| `UNIQUE (initiator_id, recipient_id)` doesn't prevent A→B and B→A as two conversations | Look up direction-agnostic with OR query before INSERT |
| Checking participant after checking content validity | Check participant *before* content to avoid leaking info |
| Accepting any non-zero integer as actor ID without user existence check | Always verify `findUserById(actorId)` before checking participation |

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Store conversations as `(user_a, user_b)` with direction — two separate rows for A→B and B→A | Same two users accumulate duplicate conversations; direction-agnostic lookup fails |
| No `CHECK (initiator_id != recipient_id)` constraint | Users can message themselves, creating confusing self-conversations |
| No `UNIQUE (initiator_id, recipient_id)` constraint | Concurrent conversation-start requests create duplicate rows for the same pair |
| Return 404 instead of 403 on non-participant access | Reveals conversation ID existence to non-participants |
| Call `JsonRequestBodyParser::parse()` on GET `/conversations/{id}/messages` | GET requests have no body; parser returns 400 |
| Check content validity before participant check | Leaks info — attacker can probe valid conversation IDs by sending empty content and watching for 403 vs 422 |
| Use `is_numeric()` without cast to `int` then `> 0` | `is_numeric("0")` is true; user ID 0 would be treated as valid |
| Skip user existence check after participant check | `isParticipant()` only checks FK — deleted or non-existent users can still appear if DB has no cascade |
| Allow any user to list another user's conversations | IDOR — always verify `actorId === targetUserId` before returning conversation list |
| Index only on `conversation_id` for messages | Missing `id ASC` in index causes slow ORDER BY on large message histories |
