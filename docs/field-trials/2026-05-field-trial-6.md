# Field Trial 6 — JWT End-to-End (v0.5.0)

## Date

2026-05-17

## Baseline

- NENE2 v0.5.0 (main branch)
- `BearerTokenMiddleware` + `LocalBearerTokenVerifier` (Phase 30, #287)
- `GET /examples/protected` endpoint wired when `NENE2_LOCAL_JWT_SECRET` is set
- Local MCP server via `tools/mcp-smoke.sh`
- App container: `docker compose up -d app`

## Goal

Validate that `BearerTokenMiddleware` + `LocalBearerTokenVerifier` work end-to-end:

- Issue a local JWT using `LocalBearerTokenVerifier::issue()`
- Call `GET /examples/protected` with and without the token
- Attempt to reach the protected endpoint via the MCP server
- Record friction and open follow-up Issues

---

## Steps Taken

### 1. Start app container

```bash
docker compose up -d app
curl -sf http://localhost:8085/health  # → {"status":"ok","service":"NENE2"}
```

### 2. Verify endpoint is 404 without secret

Without `NENE2_LOCAL_JWT_SECRET` set, the middleware is not wired and the route is not registered:

```bash
curl -si http://localhost:8085/examples/protected | head -1
# HTTP/1.1 404 Not Found
```

This is correct behavior — the endpoint is only activated when the secret is configured.

**Note**: A 404 gives no hint about why the route is absent. A developer who forgot to set the env var gets no actionable feedback.

### 3. Issue a JWT

```php
$v = new LocalBearerTokenVerifier('field-trial-6-secret-key-minimum-32ch');
$token = $v->issue(['sub' => 'ft6-user', 'scope' => 'read:system', 'exp' => time() + 3600]);
// eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJmdDYtdXNlciIsInNjb3Bl...
```

Token issuance requires writing PHP. There is no CLI command or HTTP helper.

### 4. Call endpoint without token → 401

```
GET /examples/protected
→ 401 Problem Details
→ WWW-Authenticate: Bearer realm="NENE2", error="missing_token", ...
→ Content-Type: application/problem+json; charset=utf-8
```

Result matches RFC 6750 and the ADR 0008 specification.

### 5. Call endpoint with wrong scheme → 401

```
GET /examples/protected
Authorization: Basic dXNlcjpwYXNz
→ 401 Problem Details, error="invalid_token"
```

Correct — scheme check fires before token verification.

### 6. Call endpoint with tampered token → 401

```
GET /examples/protected
Authorization: Bearer tampered.payload.sig
→ 401 Problem Details, error="invalid_token"
→ detail: "Token signature is invalid."
```

Correct — HMAC verification catches the tampered signature.

### 7. Call endpoint with valid token → 200

```
GET /examples/protected
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
→ 200
→ { "message": "Welcome, authenticated user.", "claims": { "sub": "ft6-user", "scope": "read:system", "exp": ... } }
```

Claims are forwarded from the verified JWT payload into the response. Public routes (`/health`, `/examples/ping`) are unaffected.

### 8. MCP tools/list — getProtected absent

```bash
bash tools/mcp-smoke.sh
```

`getProtected` does not appear in the tool list. This is expected: the endpoint is not registered in `docs/mcp/tools.json`, and the MCP HTTP client has no mechanism to carry Bearer tokens.

Attempting to call a hypothetical `getProtected` tool through the MCP server would fail at two levels:

1. The tool is not in the catalog.
2. Even if added, `NativeLocalMcpHttpClient` sends no `Authorization` header.

---

## Results

| Scenario | Expected | Actual | Status |
|---|---|---|---|
| No secret → route not wired | 404 | 404 | Pass |
| No token → 401 Problem Details | 401 + `WWW-Authenticate: Bearer` | 401 ✓ | Pass |
| Wrong scheme → 401 | 401 + `invalid_token` | 401 ✓ | Pass |
| Tampered token → 401 | 401 + `invalid_token` | 401 ✓ | Pass |
| Valid token → 200 + claims | 200 + JWT payload | 200 ✓ | Pass |
| Public routes unaffected | 200 | 200 ✓ | Pass |
| MCP tool available | Tool in list | Not in list | Expected gap |

All Bearer token HTTP scenarios behave correctly. MCP integration is not yet supported for Bearer-protected endpoints.

---

## Friction Observed

### F-1: No CLI or helper for issuing test tokens

Issuing a token requires writing PHP inline or creating a script. There is no `composer jwt:issue` command or `GET /examples/token` helper endpoint. During development this adds friction when switching environments or rotating the secret.

**Candidate fix**: Add a `tools/issue-jwt.php` script or a `composer jwt:issue` Composer script that reads `NENE2_LOCAL_JWT_SECRET` and prints a signed token with configurable claims.

### F-2: NENE2_LOCAL_JWT_SECRET absent from .env.example

The variable is documented in `docs/development/authentication-boundary.md` but missing from `.env.example`. A developer setting up from the example file would not know the variable exists.

**Candidate fix**: Add `NENE2_LOCAL_JWT_SECRET=` to `.env.example` with a comment. (Fixed directly in this report PR.)

### F-3: 404 when secret not set gives no hint

Without `NENE2_LOCAL_JWT_SECRET`, `/examples/protected` returns 404 with no message. There is no log line, no hint in the framework smoke endpoint, and no documentation pointer. A developer who mis-sets the variable name gets a silent 404.

**Candidate fix**: Consider a startup log line listing which optional features are enabled/disabled (JWT secret present/absent, machine API key present/absent). This would surface configuration gaps at boot time rather than at request time.

### F-4: MCP server cannot call Bearer-protected endpoints

`NativeLocalMcpHttpClient` sends only `Accept: application/json` and (for writes) `Content-Type: application/json`. There is no mechanism to attach a Bearer token. This means the MCP server cannot expose `GET /examples/protected` as a tool, even if it were added to `tools.json`.

**Candidate fix**: Add an optional `Authorization` header configuration to `LocalMcpHttpClientInterface` and its implementations. The secret or token value would be injected at startup from `NENE2_LOCAL_JWT_SECRET` or a pre-issued token.

---

## Follow-up Issues

- [ ] `tools/issue-jwt.php` または `composer jwt:issue` スクリプトを追加する (#292)
- [ ] MCP サーバーが Bearer トークンを送信できるよう `NativeLocalMcpHttpClient` を拡張する (#293)
- [ ] 起動ログに JWT secret の有無などオプション機能の有効/無効を出力する (#294)

## Conclusion

The `BearerTokenMiddleware` + `LocalBearerTokenVerifier` stack is correct and complete at the HTTP level. All RFC 6750 behaviors (missing token, wrong scheme, invalid signature, valid token) work as specified. The primary gaps are DX-level: no CLI token issuer, missing `.env.example` entry, and MCP server has no Bearer token transport mechanism.
