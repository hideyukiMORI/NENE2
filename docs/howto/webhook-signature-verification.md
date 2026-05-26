# How-to: Webhook Signature Verification with HMAC-SHA256

> **FT reference**: FT260 (`NENE2-FT/hmaclog`) — Webhook signature verification: HMAC-SHA256, timing-safe comparison, replay attack prevention
> **ATK**: FT260 — cracker-mindset attack test (ATK-01 through ATK-12)

Demonstrates how to verify incoming webhook requests using a Stripe-style HMAC-SHA256 signature.
The signature header binds a timestamp to the request body, preventing both forgery and replay attacks.
`hash_equals()` is used for constant-time comparison to prevent timing attacks.

---

## Routes

| Method | Path              | Description                             |
|--------|-------------------|-----------------------------------------|
| `POST` | `/webhook`        | Receive and verify a signed webhook     |
| `GET`  | `/webhook/events` | List received webhook events            |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS webhook_events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type   TEXT NOT NULL,
    payload      TEXT NOT NULL,
    delivered_at TEXT NOT NULL
);
```

Events are stored only after signature verification passes. A rejected webhook is never persisted.

---

## Signature format (Stripe-style)

```
X-Webhook-Signature: t=<unix-timestamp>,v1=<hmac-hex>
```

**Signed payload**: `"<timestamp>.<raw-body>"`

The timestamp is included in the HMAC computation. This means:
- A valid signature is only valid for the body it was computed over (body tampering breaks the sig).
- A valid signature is only valid at the moment it was generated (replaying an old, valid signature
  fails the timestamp check even if the HMAC is correct).

---

## Verifier

```php
final class WebhookVerifier
{
    private const int TOLERANCE_SECONDS = 300;

    public function __construct(private readonly string $secret) {}

    public function verify(ServerRequestInterface $request, string $rawBody): void
    {
        $header = $request->getHeaderLine('X-Webhook-Signature');
        if ($header === '') {
            throw new SignatureException('Missing X-Webhook-Signature header.');
        }

        ['timestamp' => $timestamp, 'signature' => $receivedSig] = $this->parseHeader($header);

        $this->checkTimestamp($timestamp);

        $expectedSig = $this->computeSignature($timestamp, $rawBody);

        // CRITICAL: hash_equals is constant-time; === is NOT
        if (!hash_equals($expectedSig, $receivedSig)) {
            throw new SignatureException('Signature mismatch.');
        }
    }

    public function sign(string $rawBody, int $timestamp): string
    {
        return "t={$timestamp},v1={$this->computeSignature($timestamp, $rawBody)}";
    }

    private function computeSignature(int $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$rawBody}", $this->secret);
    }

    private function checkTimestamp(int $timestamp): void
    {
        $age = abs(time() - $timestamp);
        if ($age > self::TOLERANCE_SECONDS) {
            throw new SignatureException(
                sprintf('Webhook timestamp is %d seconds old (tolerance: %d).', $age, self::TOLERANCE_SECONDS),
            );
        }
    }

    private function parseHeader(string $header): array
    {
        $parts = [];
        foreach (explode(',', $header) as $chunk) {
            [$k, $v] = explode('=', $chunk, 2) + ['', ''];
            $parts[$k] = $v;
        }
        if (!isset($parts['t'], $parts['v1']) || !ctype_digit($parts['t']) || $parts['v1'] === '') {
            throw new SignatureException('Malformed X-Webhook-Signature header.');
        }
        return ['timestamp' => (int) $parts['t'], 'signature' => $parts['v1']];
    }
}
```

---

## Controller: raw body extraction

```php
private function receive(ServerRequestInterface $request): ResponseInterface
{
    $rawBody = (string) $request->getBody();   // must be raw bytes, not parsed

    try {
        $this->verifier->verify($request, $rawBody);
    } catch (SignatureException $e) {
        return $this->problems->create($request, 'invalid-signature', 'Invalid webhook signature.', 401, $e->getMessage());
    }

    $body = json_decode($rawBody, true);       // parse only after verification
    if (!is_array($body) || !isset($body['event_type']) || !is_string($body['event_type'])) {
        return $this->problems->create($request, 'invalid-body', 'event_type (string) is required.', 400);
    }

    $event = $this->repo->store($body['event_type'], $rawBody);
    return $this->json->create(['id' => $event->id, 'status' => 'accepted'], 202);
}
```

**Critical ordering**:
1. Read the raw body as a string — the HMAC was computed over the exact bytes.
2. Verify the signature against the raw body.
3. Only parse JSON after verification succeeds.

If JSON is parsed first and then re-serialized, the byte content may differ (key ordering,
whitespace), breaking the HMAC check.

---

## ATK — Cracker-mindset attack test (FT260)

### ATK-01 — Missing signature header

**Attack**: Send a webhook with no `X-Webhook-Signature` header.

```bash
POST /webhook
{"event_type": "user.created"}
```

**Observed**: `verify()` checks `$header === ''` before any computation. Returns 401 Problem Details:
`"Missing X-Webhook-Signature header."` No event is stored.

**Verdict**: **BLOCKED** — missing header is caught before signature computation.

---

### ATK-02 — Tampered signature (single-character change)

**Attack**: Take a valid signature and change one hex character.

```
X-Webhook-Signature: t=<valid-ts>,v1=<valid-hmac-but-one-char-wrong>
```

**Observed**: `hash_equals($expectedSig, $receivedSig)` returns `false`. 401 is returned.
The comparison is constant-time — response time does not vary with how many characters match.

**Verdict**: **BLOCKED** — `hash_equals()` prevents timing oracle while rejecting tampered sigs.

---

### ATK-03 — Wrong secret used to sign

**Attack**: Sign the request with a different HMAC secret.

```
X-Webhook-Signature: t=<now>,v1=<hmac-with-wrong-secret>
```

**Observed**: `computeSignature()` uses the server's secret. The attacker's HMAC (computed with
a different secret) produces a different hex string. `hash_equals()` fails. 401 returned.

**Verdict**: **BLOCKED** — without the secret, a valid signature cannot be forged.

---

### ATK-04 — Replay attack: valid old signature

**Attack**: Capture a legitimate `X-Webhook-Signature` header and replay it 10 minutes later.

```
X-Webhook-Signature: t=<timestamp-from-10-minutes-ago>,v1=<valid-hmac>
```

**Observed**: `checkTimestamp($timestamp)` computes `abs(time() - $timestamp)`.
10 minutes = 600 seconds > 300-second tolerance. `SignatureException` is thrown. 401 returned.

**Verdict**: **BLOCKED** — replay attacks are defeated by the 300-second timestamp tolerance.

---

### ATK-05 — Future timestamp: replay defence bypass attempt

**Attack**: Pre-sign a request with a far-future timestamp to extend the validity window.

```
X-Webhook-Signature: t=<now + 3600>,v1=<hmac-with-future-ts>
```

**Observed**: `abs(time() - $timestamp)` = 3600 > 300. `SignatureException` thrown. 401 returned.
`abs()` means future timestamps are also rejected — the check is symmetric.

**Verdict**: **BLOCKED** — `abs()` ensures both past and future timestamps outside the tolerance window are rejected.

---

### ATK-06 — Body tampering with a valid signature

**Attack**: Intercept a valid webhook. Keep the `X-Webhook-Signature` header but modify the JSON body.

```
X-Webhook-Signature: t=<valid-ts>,v1=<valid-hmac-over-original-body>
Body: {"event_type": "user.deleted"}   ← changed from "user.created"
```

**Observed**: The HMAC was computed over `"<timestamp>.<original-body>"`. The modified body
produces a different HMAC. `hash_equals()` fails. 401 returned.

**Verdict**: **BLOCKED** — the signature binds the timestamp to the body. Changing either invalidates the signature.

---

### ATK-07 — Malformed header: missing timestamp

**Attack**: Submit a signature header without the `t=` component.

```
X-Webhook-Signature: v1=<some-hmac>
```

**Observed**: `parseHeader()` checks `isset($parts['t'], $parts['v1'])`. Missing `t` throws
`SignatureException('Malformed X-Webhook-Signature header.')`. 401 returned.

**Verdict**: **BLOCKED** — header parser enforces required fields.

---

### ATK-08 — Empty secret on the server

**Attack scenario**: The server is misconfigured with an empty HMAC secret (`''`).

**Observed**: An empty secret is valid in PHP's `hash_hmac()` — it produces a deterministic
hex string. An attacker who discovers the empty secret can forge valid signatures:
`hash_hmac('sha256', "{$timestamp}.{$body}", '')`.

**Verdict**: **EXPOSED (misconfiguration)** — the verifier does not reject an empty secret.
The application configuration layer must validate that `WEBHOOK_SECRET` is non-empty at startup.
Fail-closed default: if the secret is empty, reject all webhooks.

```php
// Recommended startup guard
if ($secret === '') {
    throw new \RuntimeException('WEBHOOK_SECRET must not be empty.');
}
```

---

### ATK-09 — HMAC bypass: submit `v1=` with empty value

**Attack**: Set the signature to an empty string: `X-Webhook-Signature: t=<now>,v1=`.

**Observed**: `parseHeader()` checks `$parts['v1'] === ''`. An empty `v1` throws
`SignatureException('Malformed X-Webhook-Signature header.')`. 401 returned.

**Verdict**: **BLOCKED** — empty signature is rejected in the parser before `hash_equals()` is called.

---

### ATK-10 — Timestamp injection: non-digit timestamp

**Attack**: Submit a timestamp that is not a pure integer: `t=1234abc`.

```
X-Webhook-Signature: t=1234abc,v1=<some-hmac>
```

**Observed**: `parseHeader()` checks `ctype_digit($parts['t'])`. Non-digit characters cause
`SignatureException('Malformed X-Webhook-Signature header.')`. 401 returned.

**Verdict**: **BLOCKED** — `ctype_digit()` enforces that the timestamp is a pure integer string.

---

### ATK-11 — Header injection: comma in HMAC hex

**Attack**: Inject a comma into the `v1` value to confuse the parser.

```
X-Webhook-Signature: t=<now>,v1=abc,def
```

**Observed**: `parseHeader()` uses `explode('=', $chunk, 2)` with limit 2. The header is
split on `,` first (producing `['t=<now>', 'v1=abc', 'def']`), then each chunk is split on
`=` with limit 2. The `def` chunk becomes `['def', '']` and overwrites nothing critical.
The `v1` value is `abc`, which is not a valid HMAC hex. `hash_equals()` fails. 401 returned.

**Verdict**: **BLOCKED** — parser robustness + HMAC length check prevent injection manipulation.

---

### ATK-12 — Large body: payload size attack

**Attack**: Send a webhook with a multi-megabyte body.

**Observed**: The verifier computes `hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret)`.
`hash_hmac()` handles arbitrarily large inputs; the output is always 64 hex characters.
No explicit size limit is applied at the verifier level. A 100 MB body would be accepted if
the signature is valid and the timestamp is fresh.

**Verdict**: **EXPOSED** — no request size limit at the webhook endpoint. Add a request-size
middleware (e.g., 1 MB limit) upstream to prevent resource exhaustion. The verifier should
not be responsible for size limits — that is a concern for an outer middleware layer.

---

## ATK summary

| # | Attack vector | Verdict |
|---|---|---|
| ATK-01 | Missing signature header | BLOCKED |
| ATK-02 | Tampered signature (1 char) | BLOCKED |
| ATK-03 | Wrong secret used | BLOCKED |
| ATK-04 | Replay attack (old timestamp) | BLOCKED |
| ATK-05 | Future timestamp bypass | BLOCKED |
| ATK-06 | Body tampering | BLOCKED |
| ATK-07 | Malformed header (no timestamp) | BLOCKED |
| ATK-08 | Empty server secret (misconfiguration) | EXPOSED |
| ATK-09 | Empty `v1=` value | BLOCKED |
| ATK-10 | Non-digit timestamp | BLOCKED |
| ATK-11 | Header injection via comma | BLOCKED |
| ATK-12 | Large body / resource exhaustion | EXPOSED |

**Real vulnerabilities to fix before production**:
1. **ATK-08** — Fail-closed empty-secret guard at startup (`if ($secret === '') throw`)
2. **ATK-12** — Request-size middleware (e.g., 1 MB limit) upstream of the webhook route

---

## Design notes

### Why HMAC-SHA256 over a simple bearer token?

A bearer token only proves that the sender knows the token. HMAC-SHA256 proves that the sender
knows the secret AND that the body has not been modified — body integrity is built in.

### Why bind the timestamp to the HMAC payload?

If the signature were `HMAC(body)` only, an attacker who captures a valid request could replay it
indefinitely. By signing `"<timestamp>.<body>"`, each signature is only valid within the 300-second
window and for the exact body it was computed over.

### Why `hash_equals()` instead of `===`?

PHP's `===` is a short-circuit comparison: it stops as soon as two characters differ. An attacker
can measure the time taken to compare two strings and infer how many leading characters match,
enabling a timing oracle attack to brute-force the secret one byte at a time. `hash_equals()` runs
in constant time regardless of where the strings diverge.

---

## Related howtos

- [`pin-verification-lockout.md`](pin-verification-lockout.md) — `hash_equals()` and HMAC-SHA256 for PIN storage + lockout
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — cracker-mindset ATK assessment pattern
- [`fixed-window-rate-limiter.md`](fixed-window-rate-limiter.md) — rate limiting as a complement to signature verification
