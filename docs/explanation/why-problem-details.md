# Why RFC 9457 Problem Details?

NENE2 API errors use the RFC 9457 Problem Details format. This page explains the choice.

## What Problem Details looks like

```http
HTTP/1.1 422 Unprocessable Entity
Content-Type: application/problem+json

{
  "type": "https://nene2.dev/problems/validation-failed",
  "title": "Validation failed",
  "status": 422,
  "errors": [
    { "field": "title", "code": "required", "message": "Title is required." }
  ]
}
```

## Why a standard instead of a custom shape?

### 1. Clients can handle errors generically

A client that knows RFC 9457 can display `title` and `status` for any error from any RFC 9457 API, without knowing the specific application. Application-specific fields such as `errors` are additive extensions — they add detail without breaking generic handlers.

### 2. `Content-Type: application/problem+json` is machine-readable

When a response carries `application/problem+json`, a client knows it received an error object, not a partial success. This distinction matters for MCP tools and other machine clients that inspect Content-Type before deserializing.

### 3. The `type` URI gives errors a stable identity

Each problem type has a URI such as `https://nene2.dev/problems/validation-failed`. That URI is:

- Stable — it does not change when the HTTP status code is reused for a different error
- Documentable — the URI can point to human-readable documentation
- Matchable — a client can switch on the `type` string rather than parsing the `title`

### 4. It is a published standard

RFC 9457 (successor to RFC 7807) is a published IETF standard. Using it means the error format is not a bespoke invention that requires documentation for every API consumer.

## Trade-offs

| Benefit | Cost |
|---------|------|
| Machine-readable error type | Requires a `type` URI scheme decision |
| Stable client contracts | More verbose than `{"error": "..."}` |
| Additive extension model | Clients must understand the base standard |

## The `nene2.dev` URIs

The `type` URIs in NENE2 currently use `https://nene2.dev/problems/...` as a placeholder domain. Before a project goes to production, the deployer should either:

- Register `nene2.dev` and host the problem documentation there, or
- Replace the base URL with a project-specific domain in `ProblemDetailsResponseFactory`.

This decision is tracked as part of Phase 26 (Production Deployment Guide).
