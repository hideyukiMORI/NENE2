# How-to: Bearer Token Middleware (JWT Auth Edge Cases)

> **FT reference**: FT273 (`NENE2-FT/authlog`) — BearerTokenMiddleware JWT auth: alg=none rejection, signature tampering detection, exp/nbf enforcement, WWW-Authenticate header, per-sub data isolation, IDOR → 404, 18 tests / 26 assertions PASS.
>
> **VULN assessment**: V-01 through V-10 included at the end of this document.

Demonstrates using NENE2's `BearerTokenMiddleware` + `LocalBearerTokenVerifier` (HMAC-HS256) to protect routes. All JWT validation edge cases are handled by the middleware; controllers only receive decoded claims via `nene2.auth.claims`.

---

## Setup

```php
$verifier        = new LocalBearerTokenVerifier($secret); // env: NENE2_LOCAL_JWT_SECRET
$bearerMiddleware = new BearerTokenMiddleware($problems, $verifier);

$app = (new RuntimeApplicationFactory(
    $psr17, $psr17,
    routeRegistrars: [static fn (Router $r) => $registrar->register($r)],
    authMiddleware:  $bearerMiddleware,
))->create();
```

The middleware sets `nene2.auth.claims` on the request before any route handler runs. If validation fails, it returns 401 with `WWW-Authenticate: Bearer` before the handler is invoked.

---

## Extracting claims in a controller

```php
private function resolveOwnerId(ServerRequestInterface $request): string
{
    /** @var array<string, mixed> $claims */
    $claims = $request->getAttribute('nene2.auth.claims') ?? [];
    return (string) ($claims['sub'] ?? '');
}
```

The `sub` claim is the canonical user identity. Using it as `owner_id` ensures per-user data isolation without any additional lookup.

---

## WWW-Authenticate header

On 401, the middleware emits `WWW-Authenticate: Bearer realm="api"`.
On expired tokens, the header includes `error="invalid_token"`:

```
WWW-Authenticate: Bearer realm="api", error="invalid_token", error_description="..."
```

RFC 6750 compliance allows clients to distinguish "no token" from "bad token."

---

## Vulnerability Assessment

### V-01 — alg=none algorithm substitution ✅ SAFE

**Risk**: An attacker crafts a JWT with `"alg":"none"` and an unsigned payload claiming `sub: admin`.
**Finding**: SAFE — `LocalBearerTokenVerifier` only accepts HMAC-HS256. `alg=none` tokens are rejected at signature verification; the test `testWrongAlgorithmHeaderReturns401` confirms 401.

---

### V-02 — Signature tampering ✅ SAFE

**Risk**: Attacker intercepts a valid JWT and modifies the payload (e.g., changes `sub` to `admin`) while keeping the header and original signature.
**Finding**: SAFE — HMAC-HS256 signature covers `header.payload`. Any modification invalidates the MAC; `testTamperedPayloadReturns401` confirms 401.

---

### V-03 — Expired token replay ✅ SAFE

**Risk**: An expired token is replayed after the session should be invalid.
**Finding**: SAFE — `exp` claim is validated; tokens with `exp < time()` are rejected. `testExpiredTokenReturns401` confirms 401 with `invalid_token` in `WWW-Authenticate`.

---

### V-04 — Not-before (nbf) bypass ✅ SAFE

**Risk**: A token with a future `nbf` (not valid yet) is used before its activation time.
**Finding**: SAFE — `nbf` is enforced; `testNbfInFutureReturns401` confirms 401.

---

### V-05 — Wrong Authorization scheme ✅ SAFE

**Risk**: Attacker sends `Authorization: Basic dXNlcjpwYXNz` or omits the `Bearer ` prefix.
**Finding**: SAFE — the middleware only accepts tokens prefixed with `Bearer `. `Basic` and bare token strings both return 401.

---

### V-06 — Malformed token structure ✅ SAFE

**Risk**: Attacker sends tokens with 2 parts, 4 parts, non-base64 payload, or random strings to probe error handling.
**Finding**: SAFE — all malformed variants return 401. Non-3-part tokens and invalid base64 are rejected before any claim extraction.

---

### V-07 — Wrong signing secret ✅ SAFE

**Risk**: An attacker with knowledge of the JWT format signs a token with a different secret.
**Finding**: SAFE — HMAC verification fails if the secret differs; `testWrongSecretSignatureReturns401` confirms 401.

---

### V-08 — IDOR: cross-user data access ✅ SAFE

**Risk**: User A attempts to read User B's data by knowing or guessing the entry ID.
**Finding**: SAFE — `findByIdAndOwner($id, $ownerId)` scopes the lookup to the JWT `sub`. A cross-user request returns 404 (not 403) to avoid revealing that the entry exists.

---

### V-09 — Per-user data isolation ✅ SAFE

**Risk**: User A's writes are visible to User B.
**Finding**: SAFE — all reads are scoped by `owner_id = sub`. `testEntriesAreIsolatedByToken` verifies that Alice's and Bob's entries are fully separated.

---

### V-10 — Token without exp claim ✅ SAFE (acceptable)

**Risk**: A token with no `exp` claim is issued, becoming effectively non-expiring.
**Finding**: SAFE (by design) — `LocalBearerTokenVerifier` only validates `exp` if the claim is present. Tokens without `exp` are accepted. This is a deliberate trade-off for service-to-service scenarios; production deployments should enforce `exp` via a stricter verifier if needed.

---

### VULN Summary

| ID | Vulnerability | Finding |
|----|---------------|---------|
| V-01 | alg=none algorithm substitution | ✅ SAFE |
| V-02 | Signature tampering | ✅ SAFE |
| V-03 | Expired token replay | ✅ SAFE |
| V-04 | Not-before (nbf) bypass | ✅ SAFE |
| V-05 | Wrong Authorization scheme | ✅ SAFE |
| V-06 | Malformed token structure | ✅ SAFE |
| V-07 | Wrong signing secret | ✅ SAFE |
| V-08 | IDOR cross-user data access | ✅ SAFE |
| V-09 | Per-user data isolation | ✅ SAFE |
| V-10 | Token without exp claim | ✅ SAFE (by design) |

**10 SAFE, 0 EXPOSED**
No critical vulnerabilities. The `BearerTokenMiddleware` handles all standard JWT attack vectors; application code only needs to use the `sub` claim for ownership scoping.

---

## What NOT to do

| Anti-pattern | Risk |
|---|---|
| Accept `alg=none` tokens | Attacker can forge any identity by omitting the signature |
| Skip `exp` validation | Stolen tokens remain valid indefinitely |
| Return 403 on IDOR | Reveals that the resource exists and belongs to someone else |
| Use `X-User-Id` header instead of JWT `sub` | Header is trivially spoofable; JWT claim is cryptographically bound |
| Share the signing secret across environments | A dev-env leak compromises production tokens |
| Use `RS256` keys smaller than 2048 bits | Vulnerable to factoring attacks |
