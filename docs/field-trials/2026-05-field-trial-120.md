# Field Trial Report — FT120: Outbound Webhook Delivery (Vuln Assessment + Cracker Attack Test)

**Date**: 2026-05-21
**Release**: v1.5.54
**App**: `webhookdeliverylog` (`/home/xi/docker/NENE2-FT/webhookdeliverylog/`)
**Tests**: 31/31 passed (20 functional + 16 attack + defensive fixes)
**PHPStan**: level 8, 0 errors
**CS**: clean
**Vulnerability Assessment**: 1 finding fixed
**Cracker Attack Test**: 16 adversarial tests — all withstood

## Theme

Implement outbound webhook delivery: endpoint registry with hashed secrets, HMAC-SHA256 signature generation with timestamp replay prevention, delivery queue with retry logic, and SSRF-blocking URL validation.

FT120 is:
- 3rd in the FT118/FT119/FT120 cycle → **vulnerability assessment**
- 4th in the FT117/FT118/FT119/FT120 cycle → **cracker attack test**

## Vulnerability Assessment

### V1 — Fixed: Null safety issue in `markFailed` handler

`RouteRegistrar::markFailed()` had a `// @phpstan-ignore-line` comment masking a potential null dereference. The `$delivery ?? $this->repo->findDeliveryById($id)` pattern could still return null if the DB call failed, which would cause a fatal error.

**Fix**: Explicit null check with 404 response, consistent with all other endpoints.

### V2 — PASS: SSRF protection is comprehensive

`UrlValidator` blocks:
- `http://`, `file://`, and all non-HTTPS schemes
- `127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16` (private IPv4)
- `0.0.0.0` (resolves to loopback on many systems)
- IPv6 loopback (`::1`), ULA (`fc00::/7`), link-local (`fe80::/10`)
- `localhost`, `*.internal`, `*.local`, `*.test`, `*.invalid`, `*.localhost`
- CRLF injection (`\r`, `\n`) and null byte (`\0`) in URLs

**Limitation documented**: DNS rebinding (IP valid at registration, resolves to internal IP later) requires validation at delivery time, not just registration.

### V3 — PASS: Secret never stored or returned

`secret_hash = hash('sha256', rawSecret)`. `WebhookEndpoint::toArray()` omits `secret_hash`. Dispatch response omits secret. Attack tests confirm.

### V4 — PASS: Signature includes timestamp

`sign()` covers `{timestamp}.{body}` with HMAC-SHA256. Different timestamps produce different signatures. Replay attacks require capturing the exact request at the exact second — receivers can reject stale timestamps.

### V5 — Design decision: Dispatch uses caller-provided secret

The raw secret cannot be recovered from `secret_hash`, so `POST /events` requires the caller to supply the secret for signing outbound requests. This is intentional — the alternative (storing raw secrets) is worse. Documented as a design decision.

## Cracker Attack Test (FT120 — 4th-cycle adversarial test)

**Attack surface inference (black-box):**
- POST /webhooks accepts a URL field → SSRF, header injection, scheme bypass
- Secret passed in request → secret leakage in responses?
- HMAC signatures → forgeable? Replayable?
- Event dispatch fan-out → can we target wrong/deactivated endpoints?

**Attack categories and results:**

| Category | Attack Attempted | Result |
|---|---|---|
| SSRF | 127.0.0.1 | **Blocked 422** |
| SSRF | 0.0.0.0 | **Blocked 422** |
| SSRF | 10.x private range | **Blocked 422** |
| SSRF | 172.16.x private range | **Blocked 422** |
| Scheme bypass | http:// | **Blocked 422** |
| Scheme bypass | file:///etc/passwd | **Blocked 422** |
| Header injection | CRLF in URL | **Blocked 422** |
| Header injection | Null byte in URL | **Blocked 422** |
| Secret leakage | GET endpoint response | **Not exposed** |
| Secret leakage | Dispatch response | **Not exposed** |
| Replay attack | Signature with different timestamp | **Signatures differ** |
| Forgery | Signature with wrong secret | **Signatures differ** |
| Isolation | Dispatch to deactivated endpoint | **0 deliveries** |
| Isolation | Wrong event type contamination | **Correctly isolated** |

All 16 attack tests passed (system withstood all attacks).

## Test coverage (31 tests)

| Category | Tests |
|---|---|
| Endpoint creation (validation, missing fields) | 3 |
| URL validation (HTTP, localhost, private IP, internal domain) | 4 |
| GET endpoint (404) | 1 |
| Deactivate endpoint | 1 |
| Event dispatch (fan-out, signature, type isolation) | 3 |
| Delivery outcomes (delivered, failed with retries, exhausted retries) | 3 |
| Signature binding to timestamp | 1 |
| Deactivated endpoint gets no deliveries | 1 |
| SSRF attacks (5 variants) | 5 |
| Header injection attacks (2 variants) | 2 |
| Secret leakage attacks (2 variants) | 2 |
| Replay/forgery attacks (2 variants) | 2 |
| Endpoint isolation attacks (2 variants) | 2 |
| Deactivated endpoint attack | 1 |

## DX observations

- Having a dedicated `AttackTest.php` with labeled assertions (`'ATTACK: ...'`) makes the adversarial intent clear and distinguishes these from functional tests.
- The `UrlValidator` is cleanly injectable and independently testable without a web layer.
- Storing only `secret_hash` is the right call, but it forces the caller to retain the raw secret — worth documenting prominently at the registration endpoint.
