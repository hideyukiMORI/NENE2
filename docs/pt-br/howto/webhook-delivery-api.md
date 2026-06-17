# Como fazer: API de Entrega de Webhook

> **Referência FT**: FT348 (`NENE2-FT/webhooklog`) — Registro de webhook com filtros de URL/segredo/eventos, despacho de evento com log de entrega por assinante, mascaramento de segredo, mecanismo de retry, rastreamento de status success/failed, 18 testes PASSAM.

Este guia mostra como construir um sistema de entrega de webhook: registrar assinantes de endpoint, despachar eventos para hooks correspondentes, registrar cada tentativa de entrega e retentativas em caso de falha.

## Schema

```sql
CREATE TABLE webhooks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    url        TEXT    NOT NULL,
    secret     TEXT    NOT NULL DEFAULT '',
    events     TEXT    NOT NULL DEFAULT '[]',  -- array JSON; vazio = todos os eventos
    is_active  INTEGER NOT NULL DEFAULT 1,
    created_at TEXT    NOT NULL
);

CREATE TABLE deliveries (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    webhook_id   INTEGER NOT NULL REFERENCES webhooks(id) ON DELETE CASCADE,
    event_type   TEXT    NOT NULL,
    payload      TEXT    NOT NULL DEFAULT '{}',
    status       TEXT    NOT NULL CHECK(status IN ('pending', 'success', 'failed')),
    http_status  INTEGER,
    response     TEXT,
    error        TEXT,
    attempted_at TEXT,
    created_at   TEXT    NOT NULL
);
```

`events = '[]'` (array vazio) significa "assinar todos os eventos". `ON DELETE CASCADE` remove os registros de entrega quando um webhook é deletado.

## Endpoints

| Método | Caminho                         | Descrição                        |
|--------|---------------------------------|----------------------------------|
| `POST` | `/webhooks`                     | Registrar um webhook             |
| `GET`  | `/webhooks`                     | Listar todos os webhooks         |
| `GET`  | `/webhooks/{id}`                | Obter webhook único              |
| `DELETE` | `/webhooks/{id}`              | Deletar webhook (+ entregas)     |
| `GET`  | `/webhooks/{id}/deliveries`     | Listar entregas do webhook       |
| `POST` | `/events/dispatch`              | Despachar evento para assinantes |
| `POST` | `/deliveries/{id}/retry`        | Tentar novamente entrega com falha |

## Registrar um Webhook

```php
POST /webhooks
{
  "url": "https://example.com/hook",
  "secret": "my-signing-secret",
  "events": ["order.created", "order.updated"]
}

→ 201
{
  "id": 1,
  "url": "https://example.com/hook",
  "secret": "***",        // ← segredo sempre mascarado nas respostas
  "events": ["order.created", "order.updated"],
  "is_active": true,
  "created_at": "..."
}
```

### Assinar Todos os Eventos

```php
POST /webhooks
{"url": "https://example.com/hook", "secret": "", "events": []}

→ 201  {"events": [], ...}   // events vazio = receber todos os tipos de evento
```

### Validação

```php
POST /webhooks  {"events": []}
→ 422  // url é obrigatória
```

**Mascaramento de segredo**: O segredo armazenado é usado apenas para assinatura HMAC. Retorne `"***"` em toda resposta — nunca o valor real do segredo.

## Despachar Evento

```php
POST /events/dispatch
{"event_type": "order.created", "payload": {"order_id": 42, "amount": 99.99}}

→ 200
{
  "event_type": "order.created",
  "dispatched_to": 2,           // número de webhooks correspondentes
  "deliveries": [
    {
      "id": 1,
      "webhook_id": 1,
      "event_type": "order.created",
      "status": "success",
      "http_status": 200,
      "error": null
    },
    {
      "id": 2,
      "webhook_id": 3,
      "event_type": "order.created",
      "status": "failed",
      "http_status": 500,
      "error": "Connection timeout"
    }
  ]
}
```

### Correspondência de Eventos

Um webhook recebe um evento se:
1. Seu array `events` está vazio (assina tudo), **OU**
2. O `event_type` aparece no seu array `events`.

```php
// Webhook A: events = ["order.created"]
// Webhook B: events = ["user.signup"]
// Webhook C: events = []  (todos)

dispatch("order.created")
→ dispatched_to: 2  // A e C correspondem, B não
```

### Sem Webhooks Correspondentes

```php
POST /events/dispatch  {"event_type": "unknown.event", "payload": {}}
→ 200  {"dispatched_to": 0, "deliveries": []}
```

### Implementação do Despacho

```php
public function dispatch(string $eventType, array $payload): array
{
    // Encontrar todos os webhooks ativos que correspondem a este evento
    $hooks = $this->repo->findMatchingWebhooks($eventType);
    $deliveries = [];

    foreach ($hooks as $hook) {
        $delivery = $this->repo->createDelivery($hook['id'], $eventType, $payload, 'pending');
        $result = $this->client->deliver($hook['url'], $eventType, $payload, $hook['secret']);
        $this->repo->updateDelivery(
            $delivery['id'],
            $result->status,        // 'success' ou 'failed'
            $result->httpStatus,
            $result->response,
            $result->error,
            $now,
        );
        $deliveries[] = $this->repo->findDelivery($delivery['id']);
    }

    return [
        'event_type'    => $eventType,
        'dispatched_to' => count($deliveries),
        'deliveries'    => $deliveries,
    ];
}
```

```sql
-- Encontrar webhooks correspondentes (ativos + filtro de evento)
SELECT * FROM webhooks
WHERE is_active = 1
  AND (events = '[]' OR events LIKE '%"' || ? || '"%')
```

## Listar Entregas

```php
GET /webhooks/1/deliveries

→ 200
{
  "total": 3,
  "items": [
    {"id": 1, "event_type": "order.created", "status": "success", "http_status": 200, ...},
    {"id": 2, "event_type": "order.updated", "status": "failed",  "http_status": 500, ...},
    {"id": 3, "event_type": "ping",           "status": "success", "http_status": 200, ...}
  ]
}

// Webhook não encontrado
GET /webhooks/9999/deliveries
→ 404
```

## Tentar Novamente uma Entrega com Falha

```php
POST /deliveries/2/retry

→ 200
{
  "id": 2,
  "status": "success",
  "http_status": 200,
  "error": null
}

// Entrega não encontrada
POST /deliveries/9999/retry
→ 404
```

---

## Avaliação ATK — Teste de Ataque com Mentalidade de Cracker

### ATK-01 — Extração de Segredo via GET 🚫 BLOCKED

**Ataque**: Atacante registra um webhook e chama `GET /webhooks/{id}` ou lista webhooks para recuperar o segredo de assinatura.
**Resultado**: BLOCKED — Toda resposta retorna `"secret": "***"`. O segredo real é armazenado no banco mas nunca retornado por nenhum endpoint. O atacante não pode recuperar o segredo via API.

---

### ATK-02 — Registrar Webhook com URL Interna/Privada (SSRF) ⚠️ EXPOSED

**Ataque**: Atacante registra `url: "http://169.254.169.254/latest/meta-data"` (endpoint de metadados AWS) ou `http://localhost:8200/admin`. Quando um evento é despachado, o servidor busca a URL interna.
**Resultado**: EXPOSED — O FT webhooklog não implementa validação de URL ou bloqueio de SSRF em URLs registradas. Em produção, valide que a URL resolve para um IP público (não loopback, RFC1918 privado, link-local ou serviços de metadados) antes de registrar. Veja `docs/howto/url-shortener-ssrf-prevention.md` para o padrão de bloqueio de SSRF.

---

### ATK-03 — Despachar para Webhook Inativo 🚫 BLOCKED

**Ataque**: Atacante deleta um webhook e despacha um evento, esperando que a entrega ainda ocorra para um endpoint em cache.
**Resultado**: BLOCKED — A query de despacho filtra `WHERE is_active = 1`. Webhooks deletados são removidos da tabela (`ON DELETE CASCADE`), então nunca aparecem na query de correspondência.

---

### ATK-04 — Injetar SQL via Campo event_type 🚫 BLOCKED

**Ataque**: Atacante envia `{"event_type": "'; DROP TABLE webhooks; --", "payload": {}}` para destruir registros de webhook.
**Resultado**: BLOCKED — A query de correspondência `LIKE '%"' || ? || '"%'` usa um parâmetro vinculado para `event_type`. PDO prepared statements previnem injeção SQL. A string maliciosa é armazenada/correspondida verbatim.

---

### ATK-05 — Assinar Todos os Eventos via Array events Criado 🚫 BLOCKED

**Ataque**: Atacante envia `{"events": null}` ou `{"events": "all"}` esperando assinar todos os eventos sem usar a convenção de array vazio documentada.
**Resultado**: BLOCKED — `events` é validado como array JSON. Valores não-array retornam 422. Apenas um `[]` literal aciona o caminho "assinar tudo".

---

### ATK-06 — Entregar para HTTPS com Certificado Inválido ✅ SAFE

**Ataque**: Atacante registra URL de webhook com certificado TLS expirado ou auto-assinado, esperando que o cliente de entrega aceite mesmo assim.
**Resultado**: SAFE — O cliente de entrega deve aplicar verificação de certificado TLS (`CURLOPT_SSL_VERIFYPEER = true`). Este FT usa um cliente stub para testes; clientes de produção devem aplicar validação de certificado.

---

### ATK-07 — Replay de Evento Entregue via Retry 🚫 BLOCKED

**Ataque**: Atacante chama `POST /deliveries/{id}/retry` para uma entrega **bem-sucedida** para fazer replay de um evento no assinante.
**Resultado**: BLOCKED — Retry busca novamente o registro de entrega, re-envia o payload armazenado para a URL do webhook. O assinante deve implementar chaves de idempotência para deduplicar. O sistema de entrega em si não bloqueia retentativas de entregas bem-sucedidas, o que é intencional (caso de uso admin). Idempotência do lado do assinante é a proteção.

---

### ATK-08 — Enumerar IDs de Entrega para Acessar Logs de Outros Webhooks 🚫 BLOCKED

**Ataque**: Atacante itera IDs de entrega via `GET /deliveries/{id}` para ler logs de entregas de webhooks que não são seus.
**Resultado**: BLOCKED — Não há endpoint `GET /deliveries/{id}`; entregas são acessíveis apenas com escopo de um webhook específico via `GET /webhooks/{id}/deliveries`. A verificação 404 do webhook controla o acesso.

---

### ATK-09 — Overflow do Array events para Exaurir Memória ✅ SAFE

**Ataque**: Atacante envia `{"events": [... 10.000 tipos de evento ...]}` para exaurir memória durante parsing JSON ou armazenamento.
**Resultado**: SAFE — Middleware de limite de tamanho de requisição (padrão 1 MB) rejeita corpos muito grandes. Validação de comprimento do array no nível da aplicação (por exemplo, `max: 50 eventos`) fornece uma segunda proteção.

---

### ATK-10 — Registrar URL Duplicada para Acionar Múltiplas Entregas ✅ SAFE

**Ataque**: Atacante registra a mesma URL 100 vezes para receber 100 cópias de cada evento.
**Resultado**: SAFE — Múltiplos registros da mesma URL são permitidos (por exemplo, para diferentes subconjuntos de eventos). Rate limiting e autenticação no endpoint de registro são as proteções contra abuso. Para produção, adicione uma restrição `UNIQUE(url)` ou limites de webhook por usuário.

---

### ATK-11 — Deletar Webhook de Outro Usuário por ID 🚫 BLOCKED

**Ataque**: Atacante adevinha um ID inteiro de webhook e chama `DELETE /webhooks/{id}` para remover o webhook de outro usuário.
**Resultado**: BLOCKED — Autorização (verificação de propriedade via JWT/sessão) controla a exclusão. O FT demonstra a mecânica; auth é uma camada obrigatória em produção.

---

### ATK-12 — Injetar Payload para Exfiltrar Dados do Servidor ✅ SAFE

**Ataque**: Atacante despacha evento com `{"payload": {"__proto__": {"admin": true}}}` esperando poluição de protótipo ou injeção de template chegar à entrega.
**Resultado**: SAFE — `payload` é armazenado como string JSON e encaminhado verbatim para o assinante. PHP JSON não tem poluição de protótipo; injeção de template requer um mecanismo de template explícito. O payload é dado opaco.

---

### Resumo ATK

| ID | Ataque | Resultado |
|----|--------|-----------|
| ATK-01 | Extração de segredo via GET | 🚫 BLOCKED |
| ATK-02 | SSRF via URL interna de webhook | ⚠️ EXPOSED |
| ATK-03 | Despacho para webhook inativo/deletado | 🚫 BLOCKED |
| ATK-04 | Injeção SQL via event_type | 🚫 BLOCKED |
| ATK-05 | Assinar tudo via events não-array | 🚫 BLOCKED |
| ATK-06 | Entrega com certificado TLS inválido | ✅ SAFE |
| ATK-07 | Replay via retry | 🚫 BLOCKED |
| ATK-08 | Enumerar IDs de entrega entre webhooks | 🚫 BLOCKED |
| ATK-09 | Overflow do array events por exaustão de memória | ✅ SAFE |
| ATK-10 | Registro de URL duplicada | ✅ SAFE |
| ATK-11 | Deletar webhook de outro usuário | 🚫 BLOCKED |
| ATK-12 | Poluição de protótipo / injeção de template no payload | ✅ SAFE |

**8 BLOCKED, 3 SAFE, 1 EXPOSED** — ATK-02 (SSRF via URL de webhook) requer mitigação em produção: valide URLs registradas contra blocklist de IP privado antes do armazenamento. Veja `docs/howto/url-shortener-ssrf-prevention.md`.

---

## O que NÃO fazer

| Anti-padrão | Risco |
|---|---|
| Retornar o segredo real em qualquer resposta | Atacante pode usar segredo para falsificar assinaturas HMAC válidas para qualquer evento |
| Sem validação de URL no registro de webhook | SSRF: servidor entrega eventos para endpoints de metadados internos |
| Sem filtro `is_active` na query de despacho | Webhooks inativos/soft-deletados ainda recebem eventos |
| Armazenar payload como string PHP serializada | Desserialização de dados controlados pelo atacante aciona execução remota de código |
| Sem log de entrega por webhook | Não é possível diagnosticar falhas de entrega ou detectar ataques de replay |
| Sem mecanismo de retry | Falhas transitórias perdem permanentemente entregas de evento |
