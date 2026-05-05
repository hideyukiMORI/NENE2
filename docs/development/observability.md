# Logging and Observability Policy

NENE2 keeps observability explicit and adapter-driven.

## Position

Framework core should make runtime behavior traceable without depending on one logging backend, metrics system, or error tracking service.

The standard direction is:

- Use PSR-3 as the logging boundary.
- Depend on `Psr\Log\LoggerInterface` in framework code that needs logging.
- Treat Monolog as the first concrete logger adapter candidate.
- Use structured logging with request context.
- Generate and propagate request ids through middleware.
- Keep metrics and error tracking as adapters or integrations, not core dependencies.
- Never log secrets, tokens, passwords, cookies, authorization headers, or private payloads.

This Issue defines the policy only. Packages and implementation should be added in later Issues.

## PSR-3 Boundary

NENE2 should use PSR-3 for logging interoperability.

Framework code may depend on:

```php
Psr\Log\LoggerInterface
```

Framework code should not depend directly on Monolog classes unless it is inside a Monolog-specific adapter or provider.

## Logger Adapter Direction

Monolog is the preferred first concrete logger candidate because it is widely used, PSR-3 compatible, and flexible enough for local files, stderr, JSON logs, and external handlers.

The first implementation should decide:

- local development output
- production output target
- JSON log formatter
- log level configuration
- whether a null logger is used as the default fallback

## Structured Logging

Logs should be structured enough to support debugging, operations, and AI-assisted diagnosis.

Recommended request log context:

- `request_id`
- `method`
- `path`
- `status`
- `duration_ms`
- `client_ip` when safe and useful
- `user_id` or account identifier only when it is safe and non-sensitive
- `exception_class` for server-side diagnostics
- `problem_type` for public Problem Details responses

Message text should stay short and stable. Variable details should go into context fields.

## Request ID

Request id handling belongs in middleware.

Policy:

- Accept an incoming `X-Request-Id` only when it passes safety checks.
- Generate a request id when missing or invalid.
- Add the request id to the response header.
- Include the request id in log context.
- Make the request id available to error handling and future metrics adapters.

Recommended header:

```text
X-Request-Id
```

Request ids are correlation identifiers, not authentication credentials.

## Error Tracking

Error tracking tools such as Sentry should be adapters.

NENE2 core should expose enough context for adapters to report errors, but should not require a specific SaaS or SDK.

The first error tracking adapter boundary should accept a small, explicit report object rather than raw request or response objects.

Adapters may receive:

- exception
- request id
- route or path
- problem type
- HTTP method
- HTTP status when a response exists
- safe user or account identifier
- environment name

Adapters must not receive raw secrets or private request payloads by default.

Error tracking adapters should classify expected client failures separately from server failures. Validation errors, `404`, and `405` responses should not be reported as unhandled exceptions unless an application explicitly opts in.

## Error Tracking Adapter Safety

Error tracking integrations must stay optional and environment-aware.

Policy:

- Default framework runtime must work without an error tracking package.
- SaaS SDKs belong in adapters or application wiring, not framework core.
- Adapter configuration must make DSNs, tokens, and environment names explicit.
- Reports must include `request_id` when available.
- Reports must not include `Authorization`, cookies, raw request bodies, uploaded files, database DSNs, or local file paths by default.
- Local development should prefer disabled or test transports unless explicitly configured.

## Metrics

Metrics should be adapter-driven.

Future metrics adapters may expose:

- request count
- status code count
- request duration
- exception count
- validation failure count
- rate limit events when rate limiting exists

Prometheus, StatsD, OpenTelemetry, Datadog, or another backend should be optional integrations, not required core dependencies.

The first metrics boundary should model framework events, not backend-specific metric names.

Recommended initial events:

- `http.request.completed`
- `http.request.failed`
- `http.validation.failed`
- `http.request.rejected`

Recommended metric context:

- `request_id` when available
- `method`
- `path` or route pattern when available
- `status`
- `duration_ms`
- `problem_type` for Problem Details responses
- `exception_class` for server-side failures

Metric labels must avoid high-cardinality or sensitive values. Do not use raw URLs with user identifiers, request bodies, authorization headers, cookies, email addresses, tokens, or database identifiers as labels.

## Metrics Adapter Direction

Metrics adapters should translate framework events into backend-specific counters, histograms, or spans.

Policy:

- Framework core may expose event context, but should not depend on Prometheus, OpenTelemetry, Datadog, StatsD, or another backend by default.
- Adapters should decide how to bucket durations and status codes.
- Applications should be able to disable metrics without changing HTTP behavior.
- Metrics failures should not break request handling unless an application explicitly chooses strict behavior.

## Secret and Payload Safety

NENE2 should default to safe logging.

Never log these values by default:

- passwords
- tokens
- API keys
- session ids
- cookies
- `Authorization` headers
- raw request bodies
- uploaded files
- private personal data
- database connection strings
- full SQL with bound secret values

If payload logging is needed for a local debugging tool, it must be opt-in, environment-aware, and clearly documented.

## Relationship to Error Responses

Public error responses use Problem Details. Logs may include internal diagnostic context, but public responses must not expose stack traces, SQL, file paths, secrets, or private identifiers.

When an exception becomes a public Problem Details response, logs should be able to correlate:

- request id
- HTTP status
- problem type
- exception class

## Testing

Observability tests should verify:

- request id is generated when missing
- safe incoming request id is preserved when configured
- invalid incoming request id is replaced
- response contains `X-Request-Id`
- logs contain request id context
- secrets and authorization headers are not logged

The first implementation can start with request id middleware and a test logger.

## Non-Goals

- Requiring Monolog in framework core before the logging adapter Issue.
- Requiring Sentry, Datadog, Prometheus, or OpenTelemetry by default.
- Logging raw request bodies by default.
- Making observability depend on a database.
- Exposing stack traces or private diagnostics in public API responses.
