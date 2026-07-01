# Security Assessment — Post-Remediation Verification (2026-07-02)

Framework version at time of test: **v1.5.332**
Scope of this report: NENE2 framework core (`src/`) and the shipped example
application, exercised as a running service.

This assessment re-tests NENE2 after the remediation of every `EXPOSED` finding
from the previous cross-cutting audit (issue #1441). Its two goals are to
**verify the fixes hold under live attack** (regression) and to **re-sweep the
core attack surface**. It complements — it does not replace — the standing
[security review policy](../adr/0011-security-review-policy.md) and the
per-change checklist in [`docs/review/middleware-security.md`](../review/middleware-security.md).

## Result

**0 Critical / 0 High / 0 Medium / 0 Low `EXPOSED`.** Every attack category was
blocked. The application processed the full battery without a single server
error and without restarting (`restarts=0`), and no SQL, DSN, column name, or
file path reached the logs.

## Methodology

- **Black-box live attack (ATK):** the framework and example app were booted in
  a disposable Docker stack (Apache + mod_php 8.4 + MySQL 8.4), migrated, and
  attacked over HTTP with a cracker mindset — malicious inputs aimed at breaking
  authentication, injecting SQL, leaking data, and crashing the process. Source
  was not modified; the container and its volumes were destroyed afterwards.
- **Regression verification:** each prior `EXPOSED` finding was re-exercised with
  the exact input that previously triggered it, confirming the new behaviour.
- **Test-suite corroboration:** findings whose fix is not fully observable in the
  default HTTP wiring (library primitives, opt-in features, the stdio MCP server)
  are cross-referenced to the unit tests that lock the behaviour in.

Verdict legend: `BLOCKED` (attack defended) · `SAFE` (no attack surface) ·
`EXPOSED` (defect).

## Environment

| Item | Value |
|---|---|
| Runtime | Docker, Apache + mod_php, PHP 8.4.21 |
| Database | MySQL 8.4 |
| App wiring | Example Note/Tag CRUD, machine API key (`/machine/*`), Bearer JWT (`/examples/protected`) |
| `APP_DEBUG` | `false` (production default) |

## Attack results

| # | Category | Techniques | Result |
|---|----------|------------|--------|
| A | SQL injection | path id (`1 OR 1=1`, `;DROP TABLE`), query `limit`/`offset`, time-based `SLEEP`, body & tag stacked queries | 🚫 BLOCKED — id routes require `\d+` (404); list params cast to int; body/tag payloads stored as inert literals; tables intact; `SLEEP(3)` returned in 9 ms |
| B | API-key auth | none / wrong / prefix / SQLi-in-key / valid | 🚫 BLOCKED — all 401 except the correct key; constant-time `hash_equals` |
| C | JWT auth | none / `alg:none` / wrong-secret / tampered-sig / expired / valid | 🚫 BLOCKED — all 401 except valid HS256; alg-confusion and signature forgery rejected |
| D | HEAD / method (#1443) | `HEAD` on machine + bearer routes, `X-HTTP-Method-Override` | 🚫 BLOCKED — HEAD gated like GET (401); method-override ignored (405) |
| E | Input length / type (#1450) | 100k-char title/body, 256-char title/name, array/number title | 🚫 BLOCKED — all **422** (validation-failed), not 500; 255-char and 200-char Japanese titles accepted (201) |
| F | Request size / DoS (#1444) | 5 MB body (Content-Length), 5 MB chunked (no Content-Length) | 🚫 BLOCKED — both **413**; the unknown-size/chunked body is now measured and capped by the framework |
| G | Header injection | CRLF in `X-Request-Id` | 🚫 BLOCKED — no injected header; value sanitized |
| G | Mass assignment | `id` / `is_admin` in create body | 🚫 BLOCKED — id server-assigned, extra fields ignored |
| G | CORS | `Origin: https://evil.example` | 🚫 BLOCKED — no `Access-Control-Allow-Origin` reflected |
| H | Info disclosure | error bodies, response banners | 🚫 BLOCKED — generic Problem Details; `Server: Apache` (no version), no `X-Powered-By` |
| I | Log hygiene (#1445) | force errors, inspect stderr | 🚫 BLOCKED — **0** lines containing SQL/DSN/column/path; **0** ERROR-level records across the whole battery |
| — | Stability | full battery + burst | ✅ SAFE — `restarts=0`, `/health` 200 throughout |

## Regression verification of prior findings

Every `EXPOSED` finding from #1441 was confirmed remediated.

| Prior finding | Severity | Fix (PR) | Verification in this test |
|---------------|----------|----------|---------------------------|
| #1442 RecoveryCodes 40-bit + unsalted hash | Medium | #1449 | Library primitive — default entropy raised to 80 bits; locked by `RecoveryCodesTest` (default ≥ 20 hex chars) |
| #1443 HEAD bypasses `protectedMethods` | Medium | #1452 | Live: `HEAD` on protected routes → 401. Method-filter normalization (HEAD→GET) locked by `ApiKeyAuthenticationMiddlewareTest`; HEAD body suppression by `ResponseEmitterTest` |
| #1444 size limit skipped for unknown-size body | Medium | #1453 | Live: 5 MB chunked (no Content-Length) → **413**. Unknown-size measurement locked by `RequestSizeLimitMiddlewareTest` |
| #1445 raw `X-Request-Id` + full exception in logs | Low | #1451 | Live: **0** SQL/DSN/path lines and **0** ERROR records during the battery |
| #1450 unvalidated input length/type → 500 | Low | #1451 | Live: oversized/typed input → **422** everywhere; the 500 → log-leak chain is closed at the source |
| #1446 MCP write tools lacked audit/confirmation | Low | #1458 | stdio server — state-changing calls now audited; destructive calls require explicit confirmation; locked by `LocalMcpServerTest` |
| #1447 no HSTS, weak Referrer-Policy | Low | #1457 | Live: `Referrer-Policy: strict-origin-when-cross-origin`. HSTS is opt-in and off in default wiring (correct); emission locked by `BaselineMiddlewareTest` |
| #1448 `undici` (high) / `brace-expansion` | deps | #1456 | `npm audit`: 0 vulnerabilities |

## Verified-safe core controls

Confirmed intact (evidence in the run above and the audit report on #1441):

- Parameterized SQL end-to-end; no dynamic SQL reaches user input.
- Constant-time secret comparison (`hash_equals`) for API keys, JWT signatures,
  TOTP codes, and recovery codes.
- Strict HS256-only JWT verification with mandatory `exp`.
- CORS allowlist with no origin reflection and no wildcard-with-credentials.
- Sanitized, length-capped `X-Request-Id`; no CRLF/header injection.
- Generic RFC 9457 Problem Details with `APP_DEBUG=false` by default; no stack
  traces, SQL, or paths in client responses.
- Suppressed server/version banners.

## Residual notes (non-blocking)

These are `INFO`-level hardening ideas, tracked for future consideration rather
than as defects:

- **MCP per-call scope authorization** — the MCP layer now audits and confirms
  state-changing calls (#1446), but does not yet validate a per-tool scope
  against the token. Real authorization is enforced at the HTTP/JWT boundary the
  call is proxied through. Adding scope checks would require exposing token
  scopes on `LocalMcpHttpClientInterface` and extending the tool catalog schema.
- **`machineDatabasePreflight`** is a side-effecting POST catalogued with
  `readOnlyHint`; it fails closed at the HTTP layer (API-key gated) but the hint
  could mislead an LLM client.
- **`InMemoryRateLimitStorage`** is non-atomic and single-process — documented as
  dev-only; production deployments must use a shared atomic store.
- **Front controller** has no bootstrap-level `try/catch`; leak safety before the
  pipeline depends on `display_errors=Off` (set in the shipped image).
- **Docs toolchain** (`esbuild`/`vite`/`vitepress`) carries dev-server-only
  advisories with no upstream fix; not part of the production runtime.

## Conclusion

NENE2 at v1.5.332 withstands the full ATK battery with **zero exposed findings**.
All previously identified issues are remediated and regression-verified, and the
core security controls hold. The remaining items are low-severity hardening
opportunities, not exploitable defects.

---

*Methodology: authorized black-box penetration test of the maintainer's own
framework in a disposable container, plus unit-test corroboration. Source was
not modified. ~60 requests across 9 categories.*
