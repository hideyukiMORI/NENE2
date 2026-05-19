# ADR 0011: Security Review Policy — Adversarial Review and HTTP Security Invariants

## Status

accepted

## Context

During v1.5.0 development, a structured adversarial review of the NENE2 codebase identified
several exploitable flaws in the default middleware configuration. The findings fell into two
categories:

**Bypasses and injections (P1/P2)**
- `RequestSizeLimitMiddleware`: negative or non-numeric `Content-Length` values (e.g. `-1`,
  `abc`) bypassed both the header check and the body-stream fallback, allowing unlimited payload
  delivery with no limit enforcement.
- `BearerTokenMiddleware`: `WWW-Authenticate` header values were built from
  `TokenVerificationException::getMessage()` without sanitisation. An exception message
  containing `"` or `\r\n` (CRLF) could produce a malformed or split header — a class of
  HTTP response splitting and header injection vulnerabilities.

**Footguns and missing guards (P3)**
- `CorsMiddleware`: passing `allowedOrigins: ['*']` performs a literal string match against the
  `Origin` header. No browser sends `Origin: *`, so the intent of "open all origins" is silently
  defeated. The constructor now throws `\InvalidArgumentException` to make the misconfiguration
  explicit.
- `CorsMiddleware`: the `$maxAge` parameter previously accepted `0` or negative values silently,
  producing an invalid `Access-Control-Max-Age: 0` header. The constructor now enforces a
  positive integer at runtime.

## Decision

### 1. Structured adversarial reviews are part of the release process

Before each minor or major release, one review pass is conducted from an attacker's perspective:
assume the defaults are misconfigured, assume attacker-controlled input reaches every parameter,
and attempt to find bypasses, injections, and information-disclosure paths. Findings are triaged
as P1 (critical / exploitable bypass), P2 (injection / privilege escalation), or P3 (footgun /
defence-in-depth gap) and tracked in GitHub Issues.

### 2. HTTP security invariants for middleware

The following invariants must hold for all shipped middleware:

| Invariant | Rationale |
|---|---|
| `Content-Length` must be a non-negative decimal integer (RFC 9110 §8.6); any other value is treated as oversized | Prevents bypass of the request size limit |
| All values placed into HTTP response headers must have `\r\n` stripped and embedded `"` escaped (RFC 7230 §3.2) | Prevents HTTP response splitting and header injection |
| Constructor guards reject obviously invalid configuration at construction time, not silently at request time | Fail-fast; surfaces misconfiguration in tests, not in production |
| `allowedOrigins: ['*']` is explicitly rejected because it is a common misconception that produces a silently broken CORS configuration | The literal string `*` is never sent as a browser `Origin`; use per-origin allowlists |

### 3. Error logging is unconditional

`ErrorHandlerMiddleware` logs every unhandled exception at PSR-3 `error` level regardless of
the `$debug` flag. The `$debug` flag controls only whether the exception message is exposed in
the HTTP response body — never whether the server-side log entry is written. This ensures that
production incidents are always observable.

### 4. Test coverage for security-relevant paths

Every bypass, injection, and footgun identified in a security review must be covered by a
dedicated test before the fix is merged. Tests are named to make the security intent explicit
(e.g. `testRequestSizeLimitRejectsNegativeContentLength`,
`testWwwAuthenticateStripsCrlfFromDescription`).

## Consequences

**Benefits**
- Common classes of middleware bypass and header injection are closed at the framework level,
  so consumer projects inherit the fixes automatically via Composer update.
- The adversarial review cadence creates an explicit checklist that can be extended as new
  middleware is added.
- Fail-fast constructor guards surface misconfiguration in unit tests rather than in production
  traffic.

**Costs**
- Consumer code that passes `allowedOrigins: ['*']` will receive a runtime `\InvalidArgumentException`
  after upgrading. This is intentional — the previous silent behaviour was always wrong.
- Consumer code that passes `maxAge: 0` to `CorsMiddleware` will also receive an exception.
  Positive integers only.

**Follow-up**
- The adversarial review checklist should be added to `docs/review/middleware-security.md` so
  it is applied consistently before each release.

## Related

- Issue: `#528` — `Content-Length` bypass in `RequestSizeLimitMiddleware`
- Issue: `#529` — WWW-Authenticate CRLF/quote injection in `BearerTokenMiddleware`
- Issue: `#530` — CORS `['*']` footgun guard
- Issue: `#532` — `CorsMiddleware` `$maxAge` runtime validation
- PR: `#534`, `#535` — implementation
- Supersedes: none
- Superseded by: none
