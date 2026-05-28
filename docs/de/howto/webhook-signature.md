# Webhook-Signaturverifikation

Beim Empfangen von Webhooks von externen Diensten (Stripe, GitHub etc.) immer die Signatur verifizieren, bevor das Payload verarbeitet wird. Das beweist, dass der Webhook vom erwarteten Sender kam und der Body nicht manipuliert wurde.

## Signatur-Header-Format

Das Stripe-kompatible Format verwenden:

```
X-Webhook-Signature: t=<unix-timestamp>,v1=<hmac-sha256-hex>
```

Das signierte Payload ist `"<timestamp>.<rawBody>"` — der Timestamp ist im signierten Inhalt enthalten, nicht nur im Header. Das bindet den Timestamp an den Body: wenn ein Angreifer den Timestamp ändert, um die Ablaufprüfung zu umgehen, wird die Signatur ungültig.

## Die kritische Regel: `hash_equals()` verwenden, niemals `===`

```php
// ❌ Anfällig für Timing-Angriffe
if ($expectedSig === $receivedSig) { ... }

// ✅ Konstantzeit-Vergleich — das verwenden
if (!hash_equals($expectedSig, $receivedSig)) {
    throw new SignatureException('Signature mismatch.');
}
```

**Warum `===` gefährlich ist:** PHPs `===` führt einen Short-Circuit beim ersten nicht übereinstimmenden Zeichen durch. Ein Angreifer, der Tausende von Anfragen stellen und Antwortzeiten messen kann, kann lernen, wie viele Zeichen seiner erwarteten Signatur mit seiner Schätzung übereinstimmen — ein Byte nach dem anderen — und das Secret brute-forcen. Das ist ein Timing-Angriff.

`hash_equals()` vergleicht immer alle Zeichen unabhängig davon, wo Strings divergieren, sodass die Antwortzeit nichts über das Secret verrät. Es ist seit PHP 5.6 verfügbar.

Dieser Bug ist von PHPStan, statischen Analysetools oder Standard-Tests nicht erkennbar. Code-Review ist das einzige Gate.

## Implementierung

```php
final class WebhookVerifier
{
    private const int TOLERANCE_SECONDS = 300; // 5-Minuten-Replay-Fenster

    public function __construct(private readonly string $secret) {}

    /**
     * @throws SignatureException wenn fehlend, malformiert, abgelaufen oder nicht übereinstimmend
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

        if (!hash_equals($expectedSig, $receivedSig)) { // ← Konstantzeit
            throw new SignatureException('Signature mismatch.');
        }
    }

    /** Generiert den Header-Wert für ausgehende Webhooks (und Tests). */
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

## Controller-Integration

Raw-Body vor jedem JSON-Parsing lesen — die Signatur wird über die rohen Bytes berechnet:

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
            401,          // 401: Sender-Identität konnte nicht verifiziert werden
            $e->getMessage(),
        );
    }

    // Nach Verifikation sicher zu parsen
    $body = json_decode($rawBody, true);
    // ...
}
```

401 zurückgeben (nicht 403): die Identität des Senders konnte nicht verifiziert werden, was ein Authentifizierungsfehler ist, kein Autorisierungsfehler.

## Secret-Verwaltung

Das Webhook-Secret in einer Umgebungsvariable speichern, niemals im Code:

```php
// Im Config-Loader
$secret = (string) getenv('WEBHOOK_SECRET');
if ($secret === '') {
    throw new \RuntimeException('WEBHOOK_SECRET environment variable is required.');
}

$verifier = new WebhookVerifier($secret);
```

## Replay-Angriff-Prävention

Das 5-Minuten-Timestamp-Fenster (`TOLERANCE_SECONDS = 300`) bedeutet:

- Ein Angreifer, der einen gültigen Webhook abfängt, kann ihn nicht mehr als 5 Minuten später wiederholen.
- Reale Taktverschiebung zwischen Sender und Empfänger (normalerweise < 30 Sekunden) wird toleriert.
- `abs(time() - $timestamp)` behandelt sowohl vergangene als auch zukünftige Timestamps, sodass geringfügige Taktdrift in beide Richtungen akzeptiert wird.

## Secret-Rotation

Während einer Secret-Rotation Signaturen sowohl vom alten als auch vom neuen Secret für einen Übergangszeitraum akzeptieren:

```php
final class RotatingWebhookVerifier
{
    /** @param list<string> $secrets Aktuelles Secret zuerst, vorheriges Secret zuletzt */
    public function __construct(private readonly array $secrets) {}

    public function verify(ServerRequestInterface $request, string $rawBody): void
    {
        $header = $request->getHeaderLine('X-Webhook-Signature');
        foreach ($this->secrets as $secret) {
            $verifier = new WebhookVerifier($secret);
            try {
                $verifier->verify($request, $rawBody);
                return; // erste Übereinstimmung gewinnt
            } catch (SignatureException) {
                // nächstes Secret versuchen
            }
        }
        throw new SignatureException('Signature mismatch with all known secrets.');
    }
}
```

## Tests

Die `sign()`-Methode auf `WebhookVerifier` macht es einfach, signierte Testanfragen zu erstellen:

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

// Replay-Prävention testen
public function testExpiredTimestampReturns401(): void
{
    $res = $this->signedPost(['event_type' => 'order.created'], time() - 301);
    $this->assertSame(401, $res->getStatusCode());
}

// Manipulierten Body testen
public function testTamperedPayloadReturns401(): void
{
    $original  = '{"event_type":"order.created","amount":100}';
    $sigHeader = (new WebhookVerifier('test-secret'))->sign($original, time());
    $tampered  = '{"event_type":"order.created","amount":9999}';

    $res = $this->postWithHeader($tampered, $sigHeader);
    $this->assertSame(401, $res->getStatusCode());
}
```

## Code-Review-Checkliste

- [ ] `hash_equals()` wird für den Signaturvergleich verwendet, nicht `===` oder `==`
- [ ] Timestamp ist im signierten Payload enthalten (`"<t>.<body>"`), nicht nur im Header
- [ ] Timestamp-Fenster wird durchgesetzt (`abs(time() - $timestamp) > TOLERANCE`)
- [ ] Raw-Body wird vor dem Parsing gelesen; Signatur wird gegen die rohen Bytes verifiziert
- [ ] Webhook-Secret kommt aus Umgebungsvariablen, nicht hardcodiert
- [ ] 401 wird bei Signaturfehlern zurückgegeben (nicht 403 oder 400)
- [ ] Fehlermeldungen geben das Secret oder den erwarteten Signaturwert nicht preis
- [ ] Tests decken ab: gültige Signatur, falsches Secret, manipulierter Body, abgelaufener Timestamp, fehlender Header
