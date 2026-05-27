# Guia de Implementação de Recebimento de Payment Webhook

## Visão Geral

Este guia explica como implementar uma API de recebimento de Payment Webhook com NENE2.
Oferece verificação de assinatura HMAC-SHA256, processamento idempotente (restrição UNIQUE de event_id) e guarda de transição de status.

---

## Schema do Banco de Dados

```sql
CREATE TABLE payments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    external_id TEXT    NOT NULL UNIQUE,
    amount      INTEGER NOT NULL,               -- Unidade mínima de moeda (iene, centavo)
    currency    TEXT    NOT NULL DEFAULT 'usd',
    status      TEXT    NOT NULL DEFAULT 'pending'
                        CHECK (status IN ('pending', 'succeeded', 'failed', 'refunded')),
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);

CREATE TABLE webhook_events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id     TEXT    NOT NULL UNIQUE,   -- Chave de idempotência
    event_type   TEXT    NOT NULL,
    payload      TEXT    NOT NULL,          -- JSON
    processed_at TEXT    NOT NULL
);
```

`webhook_events.event_id` é o núcleo do **processamento idempotente**. Mesmo que o mesmo event_id seja recebido duas vezes, é processado apenas uma vez.

---

## Design dos Endpoints

| Método | Caminho | Descrição |
|---|---|---|
| POST | `/webhooks/payment` | Receber e processar evento Webhook |
| GET | `/payments` | Listar pagamentos |
| GET | `/payments/{id}` | Detalhes do pagamento |

---

## Transições de Status

```
[criado] → pending → succeeded → refunded
                   ↘ failed
```

Gerenciado com tabela de transições:

```php
private const array VALID_TRANSITIONS = [
    'payment.succeeded' => ['from' => 'pending',   'to' => 'succeeded'],
    'payment.failed'    => ['from' => 'pending',   'to' => 'failed'],
    'payment.refunded'  => ['from' => 'succeeded', 'to' => 'refunded'],
];
```

Transições inválidas (ex.: failed → succeeded) retornam 409 Conflict.

---

## Pontos-Chave de Design

### Verificação de assinatura HMAC-SHA256

Verifica o corpo completo da requisição com HMAC-SHA256. Usa o header `X-Webhook-Signature: sha256=<hex>` compatível com Stripe:

```php
private function verifySignature(string $body, string $header): bool
{
    if (!str_starts_with($header, 'sha256=')) {
        return false;
    }
    $provided = substr($header, 7);
    $expected = hash_hmac('sha256', $body, $this->webhookSecret);
    return hash_equals($expected, $provided); // Prevenir ataque de timing
}
```

Use `hash_equals()` para comparação em tempo constante. `===` e `strcmp()` terminam antecipadamente e são vulneráveis.

### Processamento idempotente

Provedores de webhook fazem retentativas. Elimine duplicatas com `event_id`:

```php
// Verificar antes de processar
if ($this->repo->isEventProcessed($eventId)) {
    return $this->json->create(['status' => 'already_processed']);
}

// Registrar após o processamento
$this->repo->recordEvent($eventId, $eventType, $payload, $now);
```

### Ordem de processamento

```
1. Verificação de assinatura → 401
2. Verificação de event_id duplicado → 200 already_processed
3. Processamento por tipo de evento
4. Registrar em webhook_events
5. Retornar 200 processed
```

**Realizar a verificação de assinatura primeiro** impede que atacantes poluam a tabela de event_id.

### Retornar 200 para tipos de evento desconhecidos

Quando um provedor adiciona um novo tipo de evento, retornar 4xx causa retentativas.
Retorne silenciosamente 200 e registre tipos desconhecidos:

```php
// Tipo de evento desconhecido — reconhecer sem processar
return null; // → 200 processed
```

### Teste: gerar assinatura com SECRET injetado

```php
private const string SECRET = 'test-webhook-secret';

private function signedReq(string $path, array $body): ResponseInterface
{
    $rawBody = json_encode($body);
    $sig     = 'sha256=' . hash_hmac('sha256', $rawBody, self::SECRET);
    // ...
}
```

Passe o mesmo segredo para o aplicativo com `AppFactory::createSqlite($dbFile, self::SECRET)`.

---

## Exemplos de Payload de Evento

### payment.created

```json
{
  "event_id": "evt_001",
  "event_type": "payment.created",
  "data": {"id": "pay_abc", "amount": 5000, "currency": "jpy"}
}
```

### payment.succeeded

```json
{
  "event_id": "evt_002",
  "event_type": "payment.succeeded",
  "data": {"id": "pay_abc"}
}
```

### Resposta (sucesso)

```json
{"status": "processed", "event_type": "payment.succeeded"}
```

### Resposta (reenvio idempotente)

```json
{"status": "already_processed"}
```

---

## Referência de Implementação

`../NENE2-FT/paymentlog/` — FT163 Field Trial (18 testes, verificação de assinatura, processamento idempotente, guarda de transição)
