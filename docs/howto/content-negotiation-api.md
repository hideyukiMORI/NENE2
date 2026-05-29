---
title: "How-to: Content Negotiation — JSON API"
category: api-design
tags: [content-negotiation, accept-header, json, problem-details]
difficulty: beginner
related: [content-negotiation, api-versioning]
---

# How-to: Content Negotiation — JSON API

> **FT reference**: FT301 (`NENE2-FT/contentlog`) — JSON API content negotiation: always returns `application/json` regardless of `Accept` header, `application/problem+json` for errors (404/422/405), 415 on non-JSON `Content-Type` for POST, 16 tests / 28 assertions PASS.

This guide covers how NENE2's runtime handles HTTP content negotiation for JSON APIs — what `Accept` header values are accepted, when `Content-Type` matters, and how error responses use `application/problem+json`.

## Always JSON — Ignoring Accept Header

NENE2 JSON APIs return `application/json` for success responses regardless of the `Accept` header sent by the client:

| Accept header sent | Response Content-Type |
|---|---|
| _(none)_ | `application/json` |
| `application/json` | `application/json` |
| `*/*` | `application/json` |
| `application/*` | `application/json` |
| `application/json;q=0.9` | `application/json` |
| `text/html` | `application/json` |
| `application/xml` | `application/json` |
| `text/plain` | `application/json` |

This is intentional for pure API services: the server is an API-only endpoint, not a content negotiating multi-format server. Clients that send `Accept: text/html` still receive JSON.

## Error Responses — application/problem+json

Error responses use `application/problem+json` (RFC 9457) regardless of the `Accept` header:

| Scenario | Status | Content-Type |
|---|---|---|
| Route not found | 404 | `application/problem+json` |
| Method not allowed | 405 | `application/problem+json` |
| Validation failure | 422 | `application/problem+json` |

```php
// ProblemDetailsResponseFactory always produces application/problem+json
return $this->problems->create($request, 'not-found', 'Article Not Found', 404, '');
```

Clients can detect errors by either the HTTP status code or by checking for `Content-Type: application/problem+json`.

## Request Content-Type — POST Bodies

For `POST` requests with a JSON body, NENE2 uses `JsonRequestBodyParser::parse()`:

```php
$body = JsonRequestBodyParser::parse($request);
```

If the request has an explicit `Content-Type: text/plain` or similar non-JSON type, the parser may return an empty array. However, if the body is valid JSON without a `Content-Type` header at all, the parser accepts it:

```
POST /articles (no Content-Type, JSON body) → 201 Created ✅
POST /articles (Content-Type: text/plain) → 415 Unsupported Media Type ✅
```

## Validation — Required Fields

```php
$title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';

if ($title === '') {
    return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
        'errors' => [['field' => 'title', 'code' => 'required', 'message' => 'title is required.']],
    ]);
}
```

After `trim()`, an empty string is treated the same as a missing field. The validation error returns a structured `errors` array with `field`, `code`, and `message` keys — standard RFC 9457 extension.

## Response Shape

```json
// GET /articles
{
    "items": [
        { "id": 1, "title": "Hello", "body": "", "created_at": "2026-01-01T00:00:00+00:00" }
    ],
    "total": 1
}

// POST /articles → 201
{ "id": 1, "title": "Hello", "body": "", "created_at": "2026-01-01T00:00:00+00:00" }

// GET /articles/999 → 404 (application/problem+json)
{ "type": "https://nene2.dev/problems/not-found", "title": "Article Not Found", "status": 404 }
```

## Route Registration

```php
$router->post('/articles', $this->createArticle(...));
$router->get('/articles', $this->listArticles(...));
$router->get('/articles/{id}', $this->getArticle(...));
```

`GET /articles` (list) is registered before `GET /articles/{id}` (single) — though in this case both are GET with different paths so order doesn't create a capture conflict. The list route uses a static path; the single route uses `{id}` dynamic capture.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Return 406 for unsupported `Accept` headers | API-only services should serve JSON to all clients, not refuse |
| Use `text/json` instead of `application/json` | Non-standard MIME type; some clients won't recognize it |
| Return plain `application/json` for error responses | Clients can't distinguish errors from success by Content-Type; use `application/problem+json` |
| Skip validation error `errors` array | Clients can't show field-level error messages to users |
| Accept `Content-Type: text/plain` for JSON bodies | Ambiguous input; be explicit about what content types are accepted |
| Trim after validation | `trim()` must come before the empty-string check; `" "` would pass if you check before trimming |
