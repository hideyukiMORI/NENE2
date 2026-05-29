---
title: "Webhook Signature Verification"
category: security
tags: [webhook, hmac, signature, timing-safe]
difficulty: intermediate
related: [webhook-signature-verification, inbound-webhook-receiver]
---

# Webhook Signature Verification

When receiving webhooks from external services (Stripe, GitHub, etc.), always verify the signature before processing the payload. This proves the webhook came from the expected sender and the body has not been tampered with.

## Signature Header Format

Use the Stripe-compatible format:

```
X-Webhook-Signature: t=<unix-timestamp>,v1=<hmac-sha256-hex>
```

The signed payload is `"<timestamp>.<rawBody>"` — the timestamp is included in the signed content, not just in the header. This ties the timestamp to the body: if an attacker changes the timestamp to bypass the expiry check, the signature becomes invalid.

## The Critical Rule: Use `hash_equals()`, Never `===`

```php
// ❌ Vulnerable to timing attacks
if ($expectedSig === $receivedSig) { ... }

// ✅ Constant-time comparison — use this
if (!hash_equals($expectedSig, $receivedSig)) {
    throw new SignatureException('Signature mismatch.');
}
```

**Why `===` is dangerous:** PHP's `===` short-circuits on the first mismatched character. An attacker who can make thousands of requests and measure response times can learn how many characters of the expected signature their guess matches — one byte at a time — and brute-force the secret. This is a timing attack.

`hash_equals()` always compares all characters regardless of where strings diverge, so response time reveals nothing about the secret. It has been in PHP since 5.6.

This bug is undetectable by PHPStan, static analysis tools, or standard tests. Code review is the only gate.

## Implementation

```php
final class WebhookVerifier
{
    private const int TOLERANCE_SECONDS = 300; // 5-minute replay window

    public function __construct(private readonly string $secret) {}

    /**
     * @throws SignatureException if missing, malformed, expired, or mismatched
     */
    public function verify(ServerRequestInterface $request, string $rawBody): void
    {
        $header = $request->getHeaderLine('X-Webhook-Signature');
        if ($header === '') {
            throw new SignatureException('Missing X-Webhook-Signature header.');
        }

        ['timestamp' => $timestamp, 'signature' => $receivedSig] = $this->parseHeader($header);
        $this->checkTimestamp($timestamp);

        $expectedSig = $this->computeSignature($timestamp, $rawBody);

        if (!hash_equals($expectedSig, $receivedSig)) { // ← constant-time
            throw new SignatureException('Signature mismatch.');
        }
    }

    /** Generate the header value for outgoing webhooks (and tests). */
    public function sign(string $rawBody, int $timestamp): string
    {
        return "t={$timestamp},v1={$this->computeSignature($timestamp, $rawBody)}";
    }

    /** @return array{timestamp: int, signature: string} */
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

    private function checkTimestamp(int $timestamp): void
    {
        $age = abs(time() - $timestamp);
        if ($age > self::TOLERANCE_SECONDS) {
            throw new SignatureException(
                sprintf('Webhook timestamp is %d seconds old (tolerance: %d).', $age, self::TOLERANCE_SECONDS),
            );
        }
    }

    private function computeSignature(int $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$rawBody}", $this->secret);
    }
}
```

## Controller Integration

Read the raw body before any JSON parsing — the signature is computed over the raw bytes:

```php
private function receive(ServerRequestInterface $request): ResponseInterface
{
    $rawBody = (string) $request->getBody();

    try {
        $this->verifier->verify($request, $rawBody);
    } catch (SignatureException $e) {
        return $this->problems->create(
            $request,
            'invalid-signature',
            'Invalid webhook signature.',
            401,          // 401: sender identity could not be verified
            $e->getMessage(),
        );
    }

    // Safe to parse after verification
    $body = json_decode($rawBody, true);
    // ...
}
```

Return 401 (not 403): the sender's identity could not be verified, which is an authentication failure, not an authorization failure.

## Secret Management

Store the webhook secret in an environment variable, never in code:

```php
// In config loader
$secret = (string) getenv('WEBHOOK_SECRET');
if ($secret === '') {
    throw new \RuntimeException('WEBHOOK_SECRET environment variable is required.');
}

$verifier = new WebhookVerifier($secret);
```

## Replay Attack Prevention

The 5-minute timestamp window (`TOLERANCE_SECONDS = 300`) means:

- An attacker who intercepts a valid webhook cannot replay it more than 5 minutes later.
- Real-world clock skew between sender and receiver (usually < 30 seconds) is tolerated.
- `abs(time() - $timestamp)` handles both past and future timestamps, so minor clock drift in either direction is accepted.

## Secret Rotation

During a secret rotation, accept signatures from both the old and new secrets for a transition period:

```php
final class RotatingWebhookVerifier
{
    /** @param list<string> $secrets Current secret first, previous secret second */
    public function __construct(private readonly array $secrets) {}

    public function verify(ServerRequestInterface $request, string $rawBody): void
    {
        $header = $request->getHeaderLine('X-Webhook-Signature');
        foreach ($this->secrets as $secret) {
            $verifier = new WebhookVerifier($secret);
            try {
                $verifier->verify($request, $rawBody);
                return; // first match wins
            } catch (SignatureException) {
                // try next secret
            }
        }
        throw new SignatureException('Signature mismatch with all known secrets.');
    }
}
```

## Testing

The `sign()` method on `WebhookVerifier` makes it easy to build signed test requests:

```php
private function signedPost(array $payload, int $timestamp): ResponseInterface
{
    $rawBody   = json_encode($payload, JSON_THROW_ON_ERROR);
    $verifier  = new WebhookVerifier('test-secret');
    $sigHeader = $verifier->sign($rawBody, $timestamp);

    $stream  = Stream::create($rawBody);
    $request = (new ServerRequest('POST', '/webhook'))
        ->withHeader('X-Webhook-Signature', $sigHeader)
        ->withBody($stream);
    return $this->app->handle($request);
}

// Test replay prevention
public function testExpiredTimestampReturns401(): void
{
    $res = $this->signedPost(['event_type' => 'order.created'], time() - 301);
    $this->assertSame(401, $res->getStatusCode());
}

// Test tampered body
public function testTamperedPayloadReturns401(): void
{
    $original  = '{"event_type":"order.created","amount":100}';
    $sigHeader = (new WebhookVerifier('test-secret'))->sign($original, time());
    $tampered  = '{"event_type":"order.created","amount":9999}';

    $res = $this->postWithHeader($tampered, $sigHeader);
    $this->assertSame(401, $res->getStatusCode());
}
```

## Code Review Checklist

- [ ] `hash_equals()` is used for signature comparison, not `===` or `==`
- [ ] Timestamp is included in the signed payload (`"<t>.<body>"`), not just in the header
- [ ] Timestamp window is enforced (`abs(time() - $timestamp) > TOLERANCE`)
- [ ] Raw body is read before parsing; signature is verified against the raw bytes
- [ ] Webhook secret comes from environment variables, not hardcoded
- [ ] 401 is returned for signature failures (not 403 or 400)
- [ ] Error messages do not expose the secret or expected signature value
- [ ] Tests cover: valid signature, wrong secret, tampered body, expired timestamp, missing header
