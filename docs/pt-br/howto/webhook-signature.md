# Verificação de Assinatura de Webhook

Ao receber webhooks de serviços externos (Stripe, GitHub, etc.), sempre verifique a assinatura antes de processar o payload. Isso prova que o webhook veio do remetente esperado e que o corpo não foi adulterado.

## Formato do Cabeçalho de Assinatura

Use o formato compatível com Stripe:

```
X-Webhook-Signature: t=<unix-timestamp>,v1=<hmac-sha256-hex>
```

O payload assinado é `"<timestamp>.<rawBody>"` — o timestamp está incluído no conteúdo assinado, não apenas no cabeçalho. Isso vincula o timestamp ao corpo: se um atacante muda o timestamp para contornar a verificação de expiração, a assinatura fica inválida.

## A Regra Crítica: Use `hash_equals()`, Nunca `===`

```php
// ❌ Vulnerável a ataques de timing
if ($expectedSig === $receivedSig) { ... }

// ✅ Comparação em tempo constante — use esta
if (!hash_equals($expectedSig, $receivedSig)) {
    throw new SignatureException('Signature mismatch.');
}
```

**Por que `===` é perigoso:** O `===` do PHP faz curto-circuito no primeiro caractere que não corresponde. Um atacante que pode fazer milhares de requisições e medir os tempos de resposta pode aprender quantos caracteres da assinatura esperada seu palpite corresponde — um byte por vez — e descobrir o segredo por força bruta. Isso é um ataque de timing.

`hash_equals()` sempre compara todos os caracteres independente de onde as strings divergem, então o tempo de resposta não revela nada sobre o segredo. Está disponível no PHP desde 5.6.

Esse bug é indetectável pelo PHPStan, ferramentas de análise estática ou testes padrão. A revisão de código é o único controle.

## Implementação

```php
final class WebhookVerifier
{
    private const int TOLERANCE_SECONDS = 300; // janela de replay de 5 minutos

    public function __construct(private readonly string $secret) {}

    /**
     * @throws SignatureException se ausente, malformado, expirado ou incompatível
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

        if (!hash_equals($expectedSig, $receivedSig)) { // ← tempo constante
            throw new SignatureException('Signature mismatch.');
        }
    }

    /** Gera o valor do cabeçalho para webhooks de saída (e testes). */
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

## Integração no Controller

Leia o corpo bruto antes de qualquer parsing JSON — a assinatura é calculada sobre os bytes brutos:

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
            401,          // 401: identidade do remetente não pôde ser verificada
            $e->getMessage(),
        );
    }

    // Seguro para parse após verificação
    $body = json_decode($rawBody, true);
    // ...
}
```

Retorne 401 (não 403): a identidade do remetente não pôde ser verificada, o que é uma falha de autenticação, não de autorização.

## Gerenciamento de Segredo

Armazene o segredo do webhook em uma variável de ambiente, nunca no código:

```php
// No config loader
$secret = (string) getenv('WEBHOOK_SECRET');
if ($secret === '') {
    throw new \RuntimeException('WEBHOOK_SECRET environment variable is required.');
}

$verifier = new WebhookVerifier($secret);
```

## Prevenção de Ataque de Replay

A janela de timestamp de 5 minutos (`TOLERANCE_SECONDS = 300`) significa:

- Um atacante que intercepta um webhook válido não pode reproduzi-lo mais de 5 minutos depois.
- Desvio de relógio real entre remetente e receptor (geralmente < 30 segundos) é tolerado.
- `abs(time() - $timestamp)` lida com timestamps passados e futuros, então leve desvio de relógio em qualquer direção é aceito.

## Rotação de Segredo

Durante uma rotação de segredo, aceite assinaturas dos segredos antigo e novo por um período de transição:

```php
final class RotatingWebhookVerifier
{
    /** @param list<string> $secrets Segredo atual primeiro, segredo anterior segundo */
    public function __construct(private readonly array $secrets) {}

    public function verify(ServerRequestInterface $request, string $rawBody): void
    {
        $header = $request->getHeaderLine('X-Webhook-Signature');
        foreach ($this->secrets as $secret) {
            $verifier = new WebhookVerifier($secret);
            try {
                $verifier->verify($request, $rawBody);
                return; // primeira correspondência vence
            } catch (SignatureException) {
                // tentar próximo segredo
            }
        }
        throw new SignatureException('Signature mismatch with all known secrets.');
    }
}
```

## Testes

O método `sign()` em `WebhookVerifier` facilita construir requisições de teste assinadas:

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

// Testar prevenção de replay
public function testExpiredTimestampReturns401(): void
{
    $res = $this->signedPost(['event_type' => 'order.created'], time() - 301);
    $this->assertSame(401, $res->getStatusCode());
}

// Testar corpo adulterado
public function testTamperedPayloadReturns401(): void
{
    $original  = '{"event_type":"order.created","amount":100}';
    $sigHeader = (new WebhookVerifier('test-secret'))->sign($original, time());
    $tampered  = '{"event_type":"order.created","amount":9999}';

    $res = $this->postWithHeader($tampered, $sigHeader);
    $this->assertSame(401, $res->getStatusCode());
}
```

## Checklist de Revisão de Código

- [ ] `hash_equals()` é usado para comparação de assinatura, não `===` ou `==`
- [ ] Timestamp está incluído no payload assinado (`"<t>.<body>"`), não apenas no cabeçalho
- [ ] Janela de timestamp é aplicada (`abs(time() - $timestamp) > TOLERANCE`)
- [ ] Corpo bruto é lido antes do parsing; assinatura é verificada contra bytes brutos
- [ ] Segredo do webhook vem de variáveis de ambiente, não hardcoded
- [ ] 401 é retornado para falhas de assinatura (não 403 ou 400)
- [ ] Mensagens de erro não expõem o segredo ou o valor de assinatura esperado
- [ ] Testes cobrem: assinatura válida, segredo errado, corpo adulterado, timestamp expirado, cabeçalho ausente
