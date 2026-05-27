# Como Fazer: Gateway de Webhook de Entrada

> **Referência FT**: FT317 (`NENE2-FT/inboundlog`) — Gateway de webhook de entrada com verificação de assinatura HMAC-SHA256 por fonte, idempotência de event_id duplicado, segredo nunca exposto em respostas, 17 testes / 18 asserções PASS.

Este guia mostra como construir um receptor de webhook de entrada multi-fonte que valida a autenticidade da requisição antes de processar.

## Schema

```sql
CREATE TABLE sources (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    secret     TEXT    NOT NULL,   -- segredo compartilhado para HMAC
    active     INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL
);

CREATE TABLE webhook_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id   INTEGER NOT NULL REFERENCES sources(id),
    event_id    TEXT    NOT NULL,  -- chave de dedup fornecida pelo provedor
    event_type  TEXT    NOT NULL,
    payload     TEXT    NOT NULL,  -- corpo JSON bruto
    received_at TEXT    NOT NULL,
    UNIQUE(source_id, event_id)
);
```

## Endpoints

| Método | Caminho | Descrição |
|--------|------|-------------|
| `POST` | `/sources` | Registrar uma nova fonte de webhook |
| `POST` | `/sources/{id}/receive` | Receber evento de webhook |
| `GET`  | `/sources/{id}/events` | Listar eventos de uma fonte |
| `GET`  | `/events/{id}` | Obter evento único |

## Registro de Fonte

```php
POST /sources
{"name": "stripe", "secret": "whsec_abc123..."}

→ 201
{"id": 1, "name": "stripe", "active": true, "created_at": "..."}
// secret NUNCA é retornado
```

```php
POST /sources  {"secret": "abc"}   → 422  // name obrigatório
POST /sources  {"name": "github"}  → 422  // secret obrigatório
```

## Verificação de Assinatura HMAC-SHA256

Cada webhook de entrada deve incluir um header `X-Webhook-Signature` com o HMAC-SHA256 do corpo bruto:

```
X-Webhook-Signature: sha256=<hex_digest>
```

```php
private function verifySignature(string $body, string $header, string $secret): bool
{
    if (!str_starts_with($header, 'sha256=')) {
        return false;
    }
    $expected = hash_hmac('sha256', $body, $secret);
    return hash_equals($expected, substr($header, 7));  // comparação em tempo constante
}
```

**Importante**: use `hash_equals()` — não `===` — para prevenir ataques de temporização.

## Recebimento de Eventos

```php
// Remetente (ex.: Stripe) calcula:
$sig = 'sha256=' . hash_hmac('sha256', $rawBody, $sharedSecret);

POST /sources/1/receive
X-Webhook-Signature: sha256=<digest>
Content-Type: application/json

{"event_id": "evt-001", "event_type": "payment.succeeded", "data": {...}}

→ 201  {"id": 5, "event_type": "payment.succeeded", "status": "processed"}
```

### Casos de Erro

```php
// Assinatura errada ou ausente
POST /sources/1/receive  (assinatura incorreta)  → 401 Unauthorized

// Fonte não encontrada
POST /sources/9999/receive          → 404 Not Found

// event_id ausente no payload
POST /sources/1/receive  {"event_type": "x"}  → 422
```

## Idempotência de Evento Duplicado

Retentativas do provedor são comuns — a deduplicação por `event_id` previne processamento duplo:

```php
// Primeira entrega
POST /sources/1/receive  {"event_id": "evt-dup", "event_type": "order.created"}
→ 201  {"status": "processed", "id": 5}

// Retentativa (mesmo event_id)
POST /sources/1/receive  {"event_id": "evt-dup", "event_type": "order.created"}
→ 200  {"status": "already_processed", "id": 5}
```

`UNIQUE(source_id, event_id)` no BD reforça isso na camada de armazenamento.

## Consultando Eventos

```php
GET /sources/1/events
→ 200  {"events": [...], "count": 2}

GET /events/5
→ 200  {"id": 5, "source_id": 1, "event_type": "payment.succeeded", ...}

GET /events/9999
→ 404
```

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Retornar `secret` na resposta da fonte | Vaza a chave de assinatura para qualquer cliente que possa ler a resposta da API |
| Usar `===` em vez de `hash_equals()` para assinatura | Ataque de temporização revela HMAC byte a byte |
| Sem dedup de `event_id` | Retentativas do provedor causam processamento duplo (cobranças duplas, emails duplicados) |
| Verificar assinatura após parse do JSON | Atacante pode craftar um corpo que passa o parse JSON mas falha no HMAC; sempre verifique bytes brutos primeiro |
| Segredo global único para todas as fontes | Comprometimento de uma integração expõe todas |
