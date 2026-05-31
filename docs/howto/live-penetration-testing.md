---
title: "Live Container Penetration Testing"
category: security
tags: [penetration-testing, cracker-mindset, live-test, security-hardening, atk]
difficulty: advanced
related: [pagination-boundary-attack, webhook-signature-verification, add-jwt-authentication]
---

# Live Container Penetration Testing

This guide documents how to run an adversarial live-container penetration test against a NENE2 application — starting from setup through all 30 attack phases — and records the canonical results from the v1.5.329 test session (2026-05-31, 150+ cases).

The test adopts a **cracker mindset**: assume the attacker has full access to the source code (white-box), has read all public documentation, and will try every known attack class before giving up.

---

## Prerequisites

- Docker Compose available (`docker compose version`)
- `curl`, `nc` (netcat), `openssl`, `python3` installed on the host
- A running NENE2 container with test credentials

---

## 1. Container Setup

Start an isolated test target. Use a dedicated port (never the production port) and inject test credentials:

```bash
# PHP built-in server target — fastest to spin up, tests raw NENE2 behaviour
NENE2_MACHINE_API_KEY=pentest-key docker compose run -d --rm \
  -e NENE2_LOCAL_JWT_SECRET=pentest-jwt-secret-32chars-min!! \
  -e APP_ENV=local \
  -e APP_DEBUG=false \
  -p 8299:80 \
  app php -S 0.0.0.0:80 -t public_html/

# Apache target — tests full stack including Apache config hardening
NENE2_MACHINE_API_KEY=pentest-key docker compose up -d app
# Available on :8200 (see port registry in CLAUDE.md §8)
```

Baseline smoke check:

```bash
curl -si http://localhost:8299/
# Expected: 200 OK, security headers present, no Server/X-Powered-By
```

Enumerate attack surface from OpenAPI:

```bash
curl -s http://localhost:8299/openapi.php | grep -E "^  /"
# → /, /health, /machine/health, /examples/protected,
#   /examples/notes, /examples/notes/{id}, /examples/tags, /examples/tags/{id}
```

Generate test credentials inside the container:

```bash
CID=$(docker ps --filter "publish=8299" --format "{{.ID}}")
VALID_JWT=$(docker exec $CID php -r "
  require 'vendor/autoload.php';
  \$v = new Nene2\Auth\LocalBearerTokenVerifier('pentest-jwt-secret-32chars-min!!');
  echo \$v->issue(['sub'=>'tester','exp'=>time()+86400]);
")
```

---

## 2. Attack Phases

### Phase 1 — JWT Algorithm Confusion

| ID | Attack | Expected | v1.5.329 |
|----|--------|----------|----------|
| J-01 | `alg:none` (empty signature) | 401 | ✅ BLOCKED |
| J-02 | `alg:NONE` (uppercase) | 401 | ✅ BLOCKED |
| J-03 | `alg:None` (mixed case) | 401 | ✅ BLOCKED |
| J-04 | `alg:hs256` (lowercase) | 401 | ✅ BLOCKED |
| J-05 | `alg:RS256` (key confusion) | 401 | ✅ BLOCKED |
| J-06 | No `alg` field | 401 | ✅ BLOCKED |
| J-07 | `kid: ../../etc/passwd` | 200 (valid sig) | ✅ SAFE — extra header fields ignored |
| J-08 | `jku: http://evil.com` | 200 (valid sig) | ✅ SAFE — no JWK fetch |

```bash
# J-01: alg:none
H=$(echo -n '{"typ":"JWT","alg":"none"}' | base64 -w0 | tr '+/' '-_' | tr -d '=')
P=$(echo -n '{"sub":"admin","exp":9999999999}' | base64 -w0 | tr '+/' '-_' | tr -d '=')
curl -si -H "Authorization: Bearer $H.$P." http://localhost:8299/examples/protected
# → 401  detail: "Token algorithm must be HS256."
```

### Phase 1b — JWT Payload Manipulation

| ID | Attack | Expected | v1.5.329 |
|----|--------|----------|----------|
| J-09 | `exp: 0` (epoch 1970) | 401 expired | ✅ BLOCKED |
| J-10 | `exp: null` | 401 must be numeric | ✅ BLOCKED |
| J-11 | `exp: "never"` | 401 must be numeric | ✅ BLOCKED |
| J-12 | `exp: 9999999999.9` (float) | 401 must be numeric | ✅ BLOCKED |
| J-13 | Payload is JSON array | 401 must be numeric | ✅ BLOCKED |
| J-14 | Double space in Bearer value | 401 | ✅ BLOCKED |
| J-15 | No Bearer scheme | 401 | ✅ BLOCKED |
| J-16 | 4-segment token (extra dot) | 401 format invalid | ✅ BLOCKED |
| J-17 | Header + payload only (no sig) | 401 | ✅ BLOCKED |

> **Key invariant**: `exp` must be a present integer — absence or wrong type is rejected (fixed in v1.5.329).

### Phase 2 — SQL Injection

All repositories use `?` placeholder parameterized queries. No raw string interpolation.

| ID | Attack | Expected | v1.5.329 |
|----|--------|----------|----------|
| S-01 | Classic `' OR 1=1--` in title | 201 (stored as literal) | ✅ SAFE |
| S-02 | `UNION SELECT 1,2,3--` | 201 (stored as literal) | ✅ SAFE |
| S-03 | Boolean blind `AND 1=1--` | 201 (stored as literal) | ✅ SAFE |
| S-04 | Time-based `AND SLEEP(2)--` | 201 in <50ms | ✅ SAFE — SLEEP not executed |
| S-05 | Path param SQLi `/notes/1' OR '1'='1` | 200 (int cast → 1) | ✅ SAFE |
| S-06 | Null byte `\0' OR '1'='1` | 201 (literal) | ✅ SAFE |
| S-07 | Second-order: store payload, then read | 200 (literal re-read) | ✅ SAFE |
| S-08 | SLEEP(5) in body field | 201 in <50ms | ✅ SAFE |
| S-10 | `limit=UNION SELECT...` in query string | 422 (validation) | ✅ SAFE |

```bash
# Verify parameterized queries: SLEEP is not executed
time curl -si -X POST -H "Content-Type: application/json" \
  -d '{"title":"x'\'' AND SLEEP(3)--","body":"x"}' \
  http://localhost:8299/examples/notes
# → 201 in < 100ms  (SLEEP never ran)
```

### Phase 3 — Path Traversal / LFI / PHP Wrappers

| ID | Attack | Expected | v1.5.329 |
|----|--------|----------|----------|
| P-01 | `../../etc/passwd` | 404 | ✅ BLOCKED |
| P-02 | URL-encoded `%2e%2e%2f` variants (5 forms) | 404 | ✅ BLOCKED |
| P-03 | Double-encoded `%252e%252e` | 404 | ✅ BLOCKED |
| P-04 | UTF-8 overlong `%c0%ae` | 404 | ✅ BLOCKED |
| P-05 | `php://input` / `php://filter` / `data://` | 404 | ✅ BLOCKED |
| P-06 | LFI via `{id}` parameter | 404 | ✅ BLOCKED |
| P-07 | Null byte `1%00.html` | 200 (int cast → 1) | ✅ SAFE — DB record for id=1 returned |
| P-08 | `.htaccess` on Apache | 403 | ✅ BLOCKED |
| P-08b | `.htaccess` on PHP built-in server | **200** | ⚠️ EXPOSED (see VULN-01) |
| P-09 | `.git/HEAD` | 404 | ✅ BLOCKED |
| P-10 | Backup files (`.bak`, `.swp`, `~`, etc.) | 404 | ✅ BLOCKED |

### Phase 4 — HTTP Protocol Attacks

| ID | Attack | Expected | v1.5.329 |
|----|--------|----------|----------|
| H-01 | CL.TE request smuggling | no response (PHP built-in blocks) | ✅ |
| H-02 | TE.CL smuggling | 405 (root method mismatch) | ✅ |
| H-03 | TE.TE obfuscated Transfer-Encoding | no response | ✅ |
| H-04 | HTTP/1.0 downgrade | 200 (correct body) | ✅ |
| H-05 | Absolute URI proxy abuse | 404 | ✅ |
| H-06 | HTTP header folding | 500 (PHP built-in bug) | ⚠️ VULN-02 |
| H-07 | HTTP pipelining | responses interleaved | ✅ SAFE |
| H-08 | 100 simultaneous custom headers | 200 | ✅ SAFE |
| H-10 | WebSocket upgrade | 200 (upgrade ignored) | ✅ SAFE |
| H-12 | Invalid HTTP version (`HTTP/9.9`) | 200 (PHP built-in accepts) | ✅ SAFE |

### Phase 5 — Mass Assignment / IDOR / Business Logic

| ID | Attack | Expected | v1.5.329 |
|----|--------|----------|----------|
| B-01 | Mass assignment (`id`, `__proto__` in body) | 201 (extra fields ignored) | ✅ SAFE |
| B-02 | IDOR: DELETE another user's note | 204 | ℹ️ Expected (examples have no ownership) |
| B-04 | Negative / zero ID | 404 | ✅ SAFE |
| B-05 | Integer overflow ID | 404 | ✅ SAFE |
| B-06 | DELETE then re-access same ID | 404 | ✅ SAFE |
| B-07 | Concurrent DELETE race | all 404 (idempotent) | ✅ SAFE |
| B-08 | Body at 1MB limit | 413 | ✅ BLOCKED |

### Phase 6 — API Key Bypass

| ID | Attack | Expected | v1.5.329 |
|----|--------|----------|----------|
| A-01 | No key | 401 | ✅ BLOCKED |
| A-02 | Key in query string (`?key=`, `?api_key=`) | 401 | ✅ BLOCKED |
| A-03 | Key in request body | 401 | ✅ BLOCKED |
| A-04 | Header name case variations | 200 (PSR-7 normalises) | ✅ SAFE |
| A-05 | Leading/trailing whitespace in value | 200 (PSR-7 trims) | ✅ SAFE |
| A-06 | `//machine/health` double slash | 401 without key, 200 with | ✅ SAFE |
| A-07 | `X-Original-URL` / `X-Rewrite-URL` | 200 (header ignored) | ✅ SAFE |
| A-08 | OPTIONS preflight bypass | 405 | ✅ BLOCKED |
| A-09 | HEAD method | 401 | ✅ BLOCKED |
| A-10 | Common password brute force | 401 all | ✅ BLOCKED |
| A-11 | URL-encoded path (`%6Dachine`) | 404 | ✅ BLOCKED |

```bash
# Timing attack: hash_equals used → constant-time comparison
time (for i in $(seq 1 10); do
  curl -so /dev/null -H "X-NENE2-API-Key: a" http://localhost:8299/machine/health
done)
time (for i in $(seq 1 10); do
  curl -so /dev/null -H "X-NENE2-API-Key: pentest-key" http://localhost:8299/machine/health
done)
# → timing difference < 5ms over 10 requests: SAFE
```

### Phase 7 — Injection / XSS / SSTI / Code Execution

| ID | Attack | Expected | v1.5.329 |
|----|--------|----------|----------|
| I-01 | XSS `<script>alert(1)</script>` stored | 201, returned as JSON string | ✅ SAFE — JSON encoding neutralises |
| I-02 | SSTI `{{7*7}}` / `${7*7}` | 201, stored literally | ✅ SAFE — no template engine |
| I-03 | PHP `<?php system("id"); ?>` | 201, stored as literal | ✅ SAFE |
| I-04 | Log4Shell `${jndi:ldap://...}` | 200 (header ignored) | ✅ SAFE — PHP, not Java |
| I-05 | 1000-level nested JSON | 400 (PHP parse limit) | ✅ BLOCKED |
| I-06 | Unicode BiDi control characters | 201 (stored) | ✅ SAFE — display-only risk |
| I-07 | Duplicate JSON keys | last value wins (PHP behaviour) | ℹ️ INFO-01 |

> **Stored XSS note**: XSS payloads are stored and returned verbatim in JSON responses. Since the API is JSON-only (`Content-Type: application/json` + `X-Content-Type-Options: nosniff`), browsers will not execute the script. The risk only materialises if another application renders this data in an HTML context without escaping.

### Phase 8 — Deserialisation / PHP Object Injection

| ID | Attack | Expected | v1.5.329 |
|----|--------|----------|----------|
| D-01 | `phar://` wrapper in path param | 404 | ✅ BLOCKED |
| D-02 | PHP `O:8:"stdClass":...` serialize payload | 400 (invalid body) | ✅ BLOCKED |
| D-03 | URL-encoded form with serialize payload | 400 (wrong Content-Type) | ✅ BLOCKED |

### Phase 9 — Header Injection / Response Splitting

| ID | Attack | Expected | v1.5.329 |
|----|--------|----------|----------|
| R-01 | Location header injection via created note id | `/examples/notes/<int>` | ✅ SAFE — int-only |
| R-02 | CRLF in WWW-Authenticate via JWT error | sanitised fixed message | ✅ SAFE |
| R-03 | Content-Type sniffing | `X-Content-Type-Options: nosniff` | ✅ SAFE |
| R-04 | Clickjacking | `X-Frame-Options: SAMEORIGIN` | ✅ SAFE |

### Phase 10 — CORS / SOP Bypass

| ID | Attack | Expected | v1.5.329 |
|----|--------|----------|----------|
| C-01 | `Origin: null` (sandboxed iframe) | Vary: Origin, no ACAO header | ✅ SAFE |
| C-02 | CRLF in Origin header | sanitised by curl/http layer | ✅ SAFE |
| C-03 | Vary header cache poisoning | `Vary: Origin` present | ✅ SAFE |
| C-04 | Preflight with injected method | method ignored by PHP | ✅ SAFE |
| C-05 | `Access-Control-Allow-Origin: *` | header absent (allowlist empty) | ✅ SAFE |

### Phase 11-20 — Encoding / Protocol / Timing

| ID | Attack | Result |
|----|--------|--------|
| E-01 | Emoji / high Unicode in JSON | ✅ 201 (stored correctly) |
| E-02 | BiDi RTL override (spoofing risk) | ✅ 201 (display-only) |
| E-05 | Pagination SQLi via query params | ✅ 422 (validated as integer) |
| H-06b | Folded Authorization header | ⚠️ 500 (PHP built-in bug) |
| 20 | X-Request-Id 129-char rejected | ✅ Server generates new random ID |
| 21 | Log injection via X-Request-Id `%0a` | ✅ Rejected (invalid chars) |
| 22 | Apache ServerTokens/ServerSignature | ✅ `Server: Apache` only |
| 23 | JWT sub=admin privilege escalation | ✅ Claims not used for authz |
| 26 | JWT replay (expired 2s ago) | ✅ 401 `Token has expired.` |
| 27 | 500 stack trace disclosure | ✅ Generic message only |
| 28 | XSS in Problem Details `instance` | ✅ URL-encoded (safe) |
| 29 | SSRF via health check endpoint | ✅ No URL accepted |
| 15 | API key timing oracle | ✅ `hash_equals` — < 5ms diff |

---

## 3. Findings

### VULN-01 — `.htaccess` readable from PHP built-in server ⚠️ MEDIUM

**Trigger**: `curl http://localhost:8299/.htaccess`  
**Response**: 200 + full file content (Apache rewrite rules)  
**Root cause**: PHP's built-in server (`php -S`) does not enforce `.htaccess` access restrictions — it treats `.htaccess` as a static file.  
**Impact**: Reveals URL rewrite rules. The content is non-secret (no passwords/tokens), but confirms the rewrite-to-index-php pattern.  
**Mitigation**: Use the Apache container (`docker compose up -d app`) instead of `php -S` for security-sensitive testing. Apache correctly returns 403.

```bash
# Apache (correct): 403 Forbidden
curl -si http://localhost:8200/.htaccess | head -1

# PHP built-in server (exposed): 200 OK
curl -si http://localhost:8299/.htaccess | head -1
```

### VULN-02 — HTTP header folding crashes PHP built-in server ⚠️ LOW

**Trigger**:
```
GET / HTTP/1.1\r\nHost: localhost\r\nX-NENE2-API-Key:\r\n <key>\r\n\r\n
```
**Response**: `HTTP/1.0 500 Internal Server Error` (empty body)  
**Root cause**: PHP's built-in HTTP server does not support RFC 7230 header folding (deprecated but still valid in HTTP/1.1). NENE2 framework code is not involved.  
**Impact**: Development-only (PHP built-in server). Apache handles folded headers correctly.

### INFO-01 — Duplicate JSON keys: last value wins

`{"title":"first","title":"INJECTED"}` → `title = "INJECTED"`  
Standard PHP `json_decode` behaviour. Validation applies to the final (last) value, so there is no validation-bypass path. Noted for awareness.

---

## 4. Security Invariants Verified

These guarantees held across all 150+ test cases:

| Invariant | Verification |
|-----------|-------------|
| All SQL queries parameterised | SLEEP not executed; injection payloads stored as literals |
| JWT must be HS256 + valid sig + integer exp | All 17 JWT attack variants blocked |
| API key checked with `hash_equals` | Timing difference < 5ms over 10 iterations |
| `Content-Length` overflow handled | 413 with correct headers, no PHP warning leak |
| Security headers on every response | CSP / XCTO / XFO / Referrer-Policy / Permissions-Policy confirmed |
| `Server:` / `X-Powered-By:` removed | Neither header present in Apache responses |
| Stack traces never in 500 body | Only generic `"The server encountered an unexpected condition."` |
| Path traversal blocked | All 15 encoding variants return 404 |
| `.env` / `.git` / backup files | All 404 in document root |
| CORS default: no allowed origins | `Access-Control-Allow-Origin` absent for arbitrary origins |

---

## 5. Running the Test Suite

Minimum viable repeat of the key checks (< 5 minutes):

```bash
TARGET=http://localhost:8299
APIKEY=pentest-key
SECRET=pentest-jwt-secret-32chars-min!!
CID=$(docker ps --filter "publish=8299" --format "{{.ID}}")

# 1. JWT alg:none
H=$(echo -n '{"typ":"JWT","alg":"none"}' | base64 -w0 | tr '+/' '-_' | tr -d '=')
P=$(echo -n '{"sub":"admin","exp":9999999999}' | base64 -w0 | tr '+/' '-_' | tr -d '=')
curl -si -H "Authorization: Bearer $H.$P." $TARGET/examples/protected | grep "HTTP/"
# expected: 401

# 2. SQL injection time-based
time curl -so /dev/null -X POST -H "Content-Type: application/json" \
  -d '{"title":"x'\'' AND SLEEP(3)--","body":"x"}' $TARGET/examples/notes
# expected: < 500ms total

# 3. Path traversal
curl -si "$TARGET/%2e%2e/%2e%2e/etc/passwd" | grep "HTTP/"
# expected: 404

# 4. Content-Length overflow
curl -si -X POST -H "Content-Length: 9999999999999" $TARGET/ | head -3
# expected: 413 Request Entity Too Large (not 200 + PHP warning)

# 5. API key timing
time (for i in $(seq 1 10); do
  curl -so /dev/null -H "X-NENE2-API-Key: a" $TARGET/machine/health
done)
# expected: similar timing to correct key (hash_equals)

# 6. .htaccess exposure (Apache only)
curl -si http://localhost:8200/.htaccess | grep "HTTP/"
# expected: 403

# 7. JWT exp required
NEXP=$(docker exec $CID php -r "
  require 'vendor/autoload.php';
  \$v = new Nene2\Auth\LocalBearerTokenVerifier('$SECRET');
  echo \$v->issue(['sub'=>'user1']);
")
curl -si -H "Authorization: Bearer $NEXP" $TARGET/examples/protected | grep "detail"
# expected: "Token must contain a numeric exp claim."
```

---

## Related

- [Pagination Boundary & Limit Injection](pagination-boundary-attack.md)
- [Webhook Signature Verification](webhook-signature-verification.md)
- [Add JWT Authentication](add-jwt-authentication.md)
- ADR 0011: Security Review Policy
