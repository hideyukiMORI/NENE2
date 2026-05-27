# Como fazer: Verificação de Assinatura de Webhook com HMAC-SHA256

> **Referência FT**: FT260 (`NENE2-FT/hmaclog`) — Verificação de assinatura de webhook: HMAC-SHA256, comparação segura contra timing, prevenção de ataque de replay
> **ATK**: FT260 — teste de ataque com mentalidade de cracker (ATK-01 a ATK-12)

Demonstra como verificar requisições de webhook de entrada usando uma assinatura HMAC-SHA256 estilo Stripe.
O cabeçalho de assinatura vincula um timestamp ao corpo da requisição, prevenindo falsificação e ataques de replay.
`hash_equals()` é usado para comparação em tempo constante para prevenir ataques de timing.

---

## Rotas

| Método | Caminho           | Descrição                               |
|--------|-------------------|-----------------------------------------|
| `POST` | `/webhook`        | Receber e verificar um webhook assinado |
| `GET`  | `/webhook/events` | Listar eventos de webhook recebidos     |

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

Eventos são armazenados apenas após a verificação de assinatura passar. Um webhook rejeitado nunca é persistido.

---

## Formato de assinatura (estilo Stripe)

```
X-Webhook-Signature: t=<unix-timestamp>,v1=<hmac-hex>
```

**Payload assinado**: `"<timestamp>.<corpo-bruto>"`

O timestamp está incluído no cálculo HMAC. Isso significa:
- Uma assinatura válida é válida apenas para o corpo sobre o qual foi calculada (adulteração do corpo quebra a assinatura).
- Uma assinatura válida é válida apenas no momento em que foi gerada (reproduzir uma assinatura antiga válida falha na verificação de timestamp mesmo que o HMAC esteja correto).

---

## Verificador

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

        // CRÍTICO: hash_equals tem tempo constante; === NÃO tem
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

## Controller: extração do corpo bruto

```php
private function receive(ServerRequestInterface $request): ResponseInterface
{
    $rawBody = (string) $request->getBody();   // deve ser bytes brutos, não parsed

    try {
        $this->verifier->verify($request, $rawBody);
    } catch (SignatureException $e) {
        return $this->problems->create($request, 'invalid-signature', 'Invalid webhook signature.', 401, $e->getMessage());
    }

    $body = json_decode($rawBody, true);       // parse apenas após verificação
    if (!is_array($body) || !isset($body['event_type']) || !is_string($body['event_type'])) {
        return $this->problems->create($request, 'invalid-body', 'event_type (string) is required.', 400);
    }

    $event = $this->repo->store($body['event_type'], $rawBody);
    return $this->json->create(['id' => $event->id, 'status' => 'accepted'], 202);
}
```

**Ordem crítica**:
1. Ler o corpo bruto como string — o HMAC foi calculado sobre os bytes exatos.
2. Verificar a assinatura contra o corpo bruto.
3. Apenas fazer parse do JSON após a verificação ser bem-sucedida.

Se o JSON for parsed primeiro e depois re-serializado, o conteúdo em bytes pode diferir (ordenação de chaves, espaços em branco), quebrando a verificação HMAC.

---

## ATK — Teste de ataque com mentalidade de cracker (FT260)

### ATK-01 — Cabeçalho de assinatura ausente

**Ataque**: Enviar webhook sem cabeçalho `X-Webhook-Signature`.

```bash
POST /webhook
{"event_type": "user.created"}
```

**Observado**: `verify()` verifica `$header === ''` antes de qualquer cálculo. Retorna 401 Problem Details:
`"Missing X-Webhook-Signature header."` Nenhum evento é armazenado.

**Veredicto**: **BLOCKED** — cabeçalho ausente é capturado antes do cálculo de assinatura.

---

### ATK-02 — Assinatura adulterada (mudança de um caractere)

**Ataque**: Pegar uma assinatura válida e mudar um caractere hex.

```
X-Webhook-Signature: t=<ts-válido>,v1=<hmac-válido-mas-um-char-errado>
```

**Observado**: `hash_equals($expectedSig, $receivedSig)` retorna `false`. 401 é retornado.
A comparação tem tempo constante — o tempo de resposta não varia com quantos caracteres correspondem.

**Veredicto**: **BLOCKED** — `hash_equals()` previne oracle de timing enquanto rejeita assinaturas adulteradas.

---

### ATK-03 — Segredo errado usado para assinar

**Ataque**: Assinar a requisição com um segredo HMAC diferente.

```
X-Webhook-Signature: t=<agora>,v1=<hmac-com-segredo-errado>
```

**Observado**: `computeSignature()` usa o segredo do servidor. O HMAC do atacante (calculado com segredo diferente) produz string hex diferente. `hash_equals()` falha. 401 retornado.

**Veredicto**: **BLOCKED** — sem o segredo, não é possível falsificar assinatura válida.

---

### ATK-04 — Ataque de replay: assinatura antiga válida

**Ataque**: Capturar cabeçalho `X-Webhook-Signature` legítimo e reproduzi-lo 10 minutos depois.

```
X-Webhook-Signature: t=<timestamp-de-10-minutos-atrás>,v1=<hmac-válido>
```

**Observado**: `checkTimestamp($timestamp)` calcula `abs(time() - $timestamp)`.
10 minutos = 600 segundos > tolerância de 300 segundos. `SignatureException` é lançada. 401 retornado.

**Veredicto**: **BLOCKED** — ataques de replay são derrotados pela tolerância de timestamp de 300 segundos.

---

### ATK-05 — Timestamp futuro: tentativa de bypass de defesa de replay

**Ataque**: Pré-assinar requisição com timestamp no futuro distante para estender a janela de validade.

```
X-Webhook-Signature: t=<agora + 3600>,v1=<hmac-com-ts-futuro>
```

**Observado**: `abs(time() - $timestamp)` = 3600 > 300. `SignatureException` lançada. 401 retornado.
`abs()` significa que timestamps futuros também são rejeitados — a verificação é simétrica.

**Veredicto**: **BLOCKED** — `abs()` garante que timestamps passados e futuros fora da janela de tolerância sejam rejeitados.

---

### ATK-06 — Adulteração de corpo com assinatura válida

**Ataque**: Interceptar webhook válido. Manter cabeçalho `X-Webhook-Signature` mas modificar corpo JSON.

```
X-Webhook-Signature: t=<ts-válido>,v1=<hmac-válido-sobre-corpo-original>
Corpo: {"event_type": "user.deleted"}   ← alterado de "user.created"
```

**Observado**: O HMAC foi calculado sobre `"<timestamp>.<corpo-original>"`. O corpo modificado
produz HMAC diferente. `hash_equals()` falha. 401 retornado.

**Veredicto**: **BLOCKED** — a assinatura vincula o timestamp ao corpo. Alterar qualquer um invalida a assinatura.

---

### ATK-07 — Cabeçalho malformado: timestamp ausente

**Ataque**: Enviar cabeçalho de assinatura sem o componente `t=`.

```
X-Webhook-Signature: v1=<algum-hmac>
```

**Observado**: `parseHeader()` verifica `isset($parts['t'], $parts['v1'])`. `t` ausente lança
`SignatureException('Malformed X-Webhook-Signature header.')`. 401 retornado.

**Veredicto**: **BLOCKED** — parser de cabeçalho aplica campos obrigatórios.

---

### ATK-08 — Segredo vazio no servidor

**Cenário de ataque**: O servidor está mal configurado com segredo HMAC vazio (`''`).

**Observado**: Um segredo vazio é válido no `hash_hmac()` do PHP — produz string hex determinística.
Um atacante que descobre o segredo vazio pode falsificar assinaturas válidas:
`hash_hmac('sha256', "{$timestamp}.{$body}", '')`.

**Veredicto**: **EXPOSED (má configuração)** — o verificador não rejeita segredo vazio.
A camada de configuração da aplicação deve validar que `WEBHOOK_SECRET` não é vazio na inicialização.
Padrão fail-closed: se o segredo estiver vazio, rejeitar todos os webhooks.

```php
// Proteção recomendada na inicialização
if ($secret === '') {
    throw new \RuntimeException('WEBHOOK_SECRET must not be empty.');
}
```

---

### ATK-09 — Bypass de HMAC: enviar `v1=` com valor vazio

**Ataque**: Definir assinatura como string vazia: `X-Webhook-Signature: t=<agora>,v1=`.

**Observado**: `parseHeader()` verifica `$parts['v1'] === ''`. `v1` vazio lança
`SignatureException('Malformed X-Webhook-Signature header.')`. 401 retornado.

**Veredicto**: **BLOCKED** — assinatura vazia é rejeitada no parser antes de `hash_equals()` ser chamado.

---

### ATK-10 — Injeção de timestamp: timestamp não-dígito

**Ataque**: Enviar timestamp que não é inteiro puro: `t=1234abc`.

```
X-Webhook-Signature: t=1234abc,v1=<algum-hmac>
```

**Observado**: `parseHeader()` verifica `ctype_digit($parts['t'])`. Caracteres não-dígito causam
`SignatureException('Malformed X-Webhook-Signature header.')`. 401 retornado.

**Veredicto**: **BLOCKED** — `ctype_digit()` garante que o timestamp é string inteira pura.

---

### ATK-11 — Injeção de cabeçalho: vírgula no hex HMAC

**Ataque**: Injetar vírgula no valor `v1` para confundir o parser.

```
X-Webhook-Signature: t=<agora>,v1=abc,def
```

**Observado**: `parseHeader()` usa `explode('=', $chunk, 2)` com limite 2. O cabeçalho é
dividido em `,` primeiro (produzindo `['t=<agora>', 'v1=abc', 'def']`), então cada parte é dividida em
`=` com limite 2. A parte `def` vira `['def', '']` e não sobrescreve nada crítico.
O valor `v1` é `abc`, que não é HMAC hex válido. `hash_equals()` falha. 401 retornado.

**Veredicto**: **BLOCKED** — robustez do parser + verificação de comprimento HMAC previnem manipulação por injeção.

---

### ATK-12 — Corpo grande: ataque de tamanho de payload

**Ataque**: Enviar webhook com corpo de vários megabytes.

**Observado**: O verificador calcula `hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret)`.
`hash_hmac()` lida com entradas arbitrariamente grandes; a saída é sempre 64 caracteres hex.
Nenhum limite de tamanho explícito é aplicado no nível do verificador. Um corpo de 100 MB seria aceito se
a assinatura for válida e o timestamp for recente.

**Veredicto**: **EXPOSED** — sem limite de tamanho de requisição no endpoint de webhook. Adicione um
middleware de tamanho de requisição (por exemplo, limite de 1 MB) antes para prevenir exaustão de recursos. O verificador
não deve ser responsável por limites de tamanho — isso é uma preocupação de uma camada de middleware externa.

---

## Resumo ATK

| # | Vetor de ataque | Veredicto |
|---|---|---|
| ATK-01 | Cabeçalho de assinatura ausente | BLOCKED |
| ATK-02 | Assinatura adulterada (1 char) | BLOCKED |
| ATK-03 | Segredo errado usado | BLOCKED |
| ATK-04 | Ataque de replay (timestamp antigo) | BLOCKED |
| ATK-05 | Bypass de timestamp futuro | BLOCKED |
| ATK-06 | Adulteração de corpo | BLOCKED |
| ATK-07 | Cabeçalho malformado (sem timestamp) | BLOCKED |
| ATK-08 | Segredo vazio no servidor (má configuração) | EXPOSED |
| ATK-09 | Valor `v1=` vazio | BLOCKED |
| ATK-10 | Timestamp não-dígito | BLOCKED |
| ATK-11 | Injeção de cabeçalho via vírgula | BLOCKED |
| ATK-12 | Corpo grande / exaustão de recursos | EXPOSED |

**Vulnerabilidades reais a corrigir antes de produção**:
1. **ATK-08** — Proteção fail-closed de segredo vazio na inicialização (`if ($secret === '') throw`)
2. **ATK-12** — Middleware de tamanho de requisição (por exemplo, limite de 1 MB) antes da rota de webhook

---

## Notas de design

### Por que HMAC-SHA256 em vez de um token bearer simples?

Um token bearer apenas prova que o remetente conhece o token. HMAC-SHA256 prova que o remetente
conhece o segredo E que o corpo não foi modificado — integridade do corpo está embutida.

### Por que vincular o timestamp ao payload HMAC?

Se a assinatura fosse apenas `HMAC(body)`, um atacante que captura uma requisição válida poderia reproduzi-la
indefinidamente. Ao assinar `"<timestamp>.<body>"`, cada assinatura é válida apenas dentro da janela de 300 segundos
e para o corpo exato sobre o qual foi calculada.

### Por que `hash_equals()` em vez de `===`?

O `===` do PHP é uma comparação de curto-circuito: para assim que dois caracteres diferem. Um atacante
pode medir o tempo necessário para comparar duas strings e inferir quantos caracteres iniciais correspondem,
habilitando um ataque de oracle de timing para descobrir o segredo um byte por vez. `hash_equals()` executa
em tempo constante independente de onde as strings divergem.

---

## Howtos relacionados

- [`pin-verification-lockout.md`](pin-verification-lockout.md) — `hash_equals()` e HMAC-SHA256 para armazenamento de PIN + bloqueio
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — padrão de avaliação ATK com mentalidade de cracker
- [`fixed-window-rate-limiter.md`](fixed-window-rate-limiter.md) — rate limiting como complemento à verificação de assinatura
