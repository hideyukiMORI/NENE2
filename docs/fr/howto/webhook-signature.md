# Vérification de signature de webhook

Lorsque vous recevez des webhooks de services externes (Stripe, GitHub, etc.), vérifiez toujours la signature avant de traiter le payload. Cela prouve que le webhook provient de l'émetteur attendu et que le corps n'a pas été altéré.

## Format de l'en-tête de signature

Utiliser le format compatible Stripe :

```
X-Webhook-Signature: t=<unix-timestamp>,v1=<hmac-sha256-hex>
```

Le payload signé est `"<timestamp>.<rawBody>"` — le timestamp est inclus dans le contenu signé, pas seulement dans l'en-tête. Cela lie le timestamp au corps : si un attaquant modifie le timestamp pour contourner le contrôle d'expiration, la signature devient invalide.

## La règle critique : utiliser `hash_equals()`, jamais `===`

```php
// ❌ Vulnérable aux attaques temporelles
if ($expectedSig === $receivedSig) { ... }

// ✅ Comparaison à temps constant — utiliser ceci
if (!hash_equals($expectedSig, $receivedSig)) {
    throw new SignatureException('Signature mismatch.');
}
```

**Pourquoi `===` est dangereux :** Le `===` de PHP court-circuite au premier caractère non correspondant. Un attaquant qui peut faire des milliers de requêtes et mesurer les temps de réponse peut apprendre combien de caractères de la signature attendue correspond à sa tentative — un octet à la fois — et forcer brutalement le secret. C'est une attaque temporelle.

`hash_equals()` compare toujours tous les caractères quelle que soit la divergence des chaînes, donc le temps de réponse ne révèle rien sur le secret. Elle est présente en PHP depuis la version 5.6.

Ce bug est indétectable par PHPStan, les outils d'analyse statique ou les tests standard. La révision de code est le seul filtre.

## Implémentation

```php
final class WebhookVerifier
{
    private const int TOLERANCE_SECONDS = 300; // fenêtre de rejeu de 5 minutes

    public function __construct(private readonly string $secret) {}

    /**
     * @throws SignatureException si manquante, malformée, expirée ou non correspondante
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

        if (!hash_equals($expectedSig, $receivedSig)) { // ← temps constant
            throw new SignatureException('Signature mismatch.');
        }
    }

    /** Générer la valeur d'en-tête pour les webhooks sortants (et les tests). */
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

## Intégration dans le contrôleur

Lire le corps brut avant tout parsing JSON — la signature est calculée sur les octets bruts :

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
            401,          // 401 : l'identité de l'émetteur n'a pas pu être vérifiée
            $e->getMessage(),
        );
    }

    // Sûr de parser après la vérification
    $body = json_decode($rawBody, true);
    // ...
}
```

Retourner 401 (pas 403) : l'identité de l'émetteur n'a pas pu être vérifiée, ce qui est un échec d'authentification, pas d'autorisation.

## Gestion du secret

Stocker le secret de webhook dans une variable d'environnement, jamais dans le code :

```php
// Dans le chargeur de config
$secret = (string) getenv('WEBHOOK_SECRET');
if ($secret === '') {
    throw new \RuntimeException('WEBHOOK_SECRET environment variable is required.');
}

$verifier = new WebhookVerifier($secret);
```

## Prévention des attaques de rejeu

La fenêtre de timestamp de 5 minutes (`TOLERANCE_SECONDS = 300`) signifie :

- Un attaquant qui intercepte un webhook valide ne peut pas le rejouer plus de 5 minutes plus tard.
- La dérive d'horloge réelle entre émetteur et destinataire (généralement < 30 secondes) est tolérée.
- `abs(time() - $timestamp)` gère les timestamps passés et futurs, donc une légère dérive d'horloge dans l'une ou l'autre direction est acceptée.

## Rotation du secret

Lors d'une rotation de secret, accepter les signatures des anciens et nouveaux secrets pendant une période de transition :

```php
final class RotatingWebhookVerifier
{
    /** @param list<string> $secrets Secret actuel en premier, secret précédent en second */
    public function __construct(private readonly array $secrets) {}

    public function verify(ServerRequestInterface $request, string $rawBody): void
    {
        $header = $request->getHeaderLine('X-Webhook-Signature');
        foreach ($this->secrets as $secret) {
            $verifier = new WebhookVerifier($secret);
            try {
                $verifier->verify($request, $rawBody);
                return; // la première correspondance gagne
            } catch (SignatureException) {
                // essayer le secret suivant
            }
        }
        throw new SignatureException('Signature mismatch with all known secrets.');
    }
}
```

## Tests

La méthode `sign()` sur `WebhookVerifier` facilite la construction de requêtes de test signées :

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

// Tester la prévention de rejeu
public function testExpiredTimestampReturns401(): void
{
    $res = $this->signedPost(['event_type' => 'order.created'], time() - 301);
    $this->assertSame(401, $res->getStatusCode());
}

// Tester le corps altéré
public function testTamperedPayloadReturns401(): void
{
    $original  = '{"event_type":"order.created","amount":100}';
    $sigHeader = (new WebhookVerifier('test-secret'))->sign($original, time());
    $tampered  = '{"event_type":"order.created","amount":9999}';

    $res = $this->postWithHeader($tampered, $sigHeader);
    $this->assertSame(401, $res->getStatusCode());
}
```

## Checklist de révision de code

- [ ] `hash_equals()` est utilisé pour la comparaison de signature, pas `===` ni `==`
- [ ] Le timestamp est inclus dans le payload signé (`"<t>.<body>"`), pas seulement dans l'en-tête
- [ ] La fenêtre de timestamp est appliquée (`abs(time() - $timestamp) > TOLERANCE`)
- [ ] Le corps brut est lu avant le parsing ; la signature est vérifiée contre les octets bruts
- [ ] Le secret de webhook provient des variables d'environnement, pas codé en dur
- [ ] 401 est retourné pour les échecs de signature (pas 403 ni 400)
- [ ] Les messages d'erreur n'exposent pas le secret ni la valeur de signature attendue
- [ ] Les tests couvrent : signature valide, mauvais secret, corps altéré, timestamp expiré, en-tête manquant
