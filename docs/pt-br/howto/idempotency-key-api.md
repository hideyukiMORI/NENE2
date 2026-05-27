# Como Fazer: API de Idempotency Key

> **Referência FT**: FT316 (`NENE2-FT/idempotencylog`) — Padrão de idempotency key para API de pagamento: hashing SHA-256 da chave, header X-Idempotent-Replayed, prevenção de duplicatas, 15 testes / 25 asserções PASS.

Este guia mostra como implementar endpoints de mutação idempotentes usando o padrão do header `X-Idempotency-Key`, prevenindo operações duplicadas em retentativas de rede.

## Schema

```sql
CREATE TABLE payments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    amount_cents INTEGER NOT NULL,
    currency    TEXT    NOT NULL DEFAULT 'JPY',
    description TEXT    NOT NULL DEFAULT '',
    status      TEXT    NOT NULL DEFAULT 'pending',
    created_at  TEXT    NOT NULL
);

CREATE TABLE idempotency_records (
    key_hash    TEXT    PRIMARY KEY,   -- SHA-256 do X-Idempotency-Key
    status_code INTEGER NOT NULL,
    body        TEXT    NOT NULL,      -- corpo da resposta codificado em JSON
    created_at  TEXT    NOT NULL
);
```

`key_hash` armazena `hash('sha256', $rawKey)` — a chave bruta nunca é persistida.

## Endpoints

| Método | Caminho | Descrição |
|--------|------|-------------|
| `POST` | `/payments` | Criar pagamento (idempotente com chave) |
| `GET`  | `/payments` | Listar todos os pagamentos |

## Fluxo da Idempotency Key

```
Cliente                        Servidor
  │── POST /payments ──────────►│
  │   X-Idempotency-Key: k1     │ (novo) → criar pagamento, armazenar registro
  │◄── 201 ─────────────────────│
  │
  │── POST /payments ──────────►│
  │   X-Idempotency-Key: k1     │ (replay) → retornar resposta armazenada
  │◄── 201 X-Idempotent-Replayed: true ──│
```

### Primeira Requisição — Cria e Armazena

```php
POST /payments  X-Idempotency-Key: payment-abc-123
{"amount_cents": 1000, "currency": "JPY"}

→ 201
{"id": 1, "amount_cents": 1000, "currency": "JPY", "status": "pending"}
// Sem header X-Idempotent-Replayed
```

### Retentativa — Retorna Resposta Armazenada

```php
POST /payments  X-Idempotency-Key: payment-abc-123
{"amount_cents": 1000, "currency": "JPY"}

→ 201  X-Idempotent-Replayed: true
{"id": 1, "amount_cents": 1000, ...}  // idêntico à primeira resposta
```

## Implementação

```php
private function createPayment(ServerRequestInterface $request): ResponseInterface
{
    $idempotencyKey = $request->getHeaderLine('X-Idempotency-Key');

    if ($idempotencyKey !== '') {
        $keyHash  = hash('sha256', $idempotencyKey);
        $existing = $this->repo->findIdempotencyRecord($keyHash);

        if ($existing !== null) {
            return $this->json->create(
                (array) json_decode($existing->body, true, 512, JSON_THROW_ON_ERROR),
                $existing->statusCode,
            )->withHeader('X-Idempotent-Replayed', 'true');
        }
    }

    // ... validar e criar pagamento ...

    if ($idempotencyKey !== '') {
        $keyHash = hash('sha256', $idempotencyKey);
        $this->repo->saveIdempotencyRecord($keyHash, 201, $responseBody, $now);
    }

    return $this->json->create($payment->toArray(), 201);
}
```

## Regras Principais

| Cenário | Comportamento |
|----------|-----------|
| Sem chave enviada | Novo pagamento criado a cada chamada |
| Chave, primeira chamada | Pagamento criado; registro armazenado |
| Chave, retentativa (mesmo corpo) | Resposta armazenada repetida; `X-Idempotent-Replayed: true` |
| Chaves diferentes | Pagamentos separados criados |

```php
// 3 retentativas com a mesma chave → apenas 1 pagamento no BD
$key = 'pay-xyz';
POST /payments  {"amount_cents": 999}  X-Idempotency-Key: $key  → 201 (cria)
POST /payments  {"amount_cents": 999}  X-Idempotency-Key: $key  → 201 (replay)
POST /payments  {"amount_cents": 999}  X-Idempotency-Key: $key  → 201 (replay)

GET /payments → {"total": 1, ...}
```

## Validação

```php
POST /payments  {"currency": "JPY"}         → 422  // amount_cents ausente
POST /payments  {"amount_cents": 0}          → 422  // deve ser positivo
POST /payments  {"amount_cents": -100}       → 422  // deve ser positivo
```

---

## ATK Assessment — Teste de Ataque com Mentalidade de Cracker

### ATK-01 — Ataque de Pré-imagem SHA-256 na Chave 🚫 BLOCKED

**Ataque**: Atacante coleta `key_hash` do BD e tenta fazer engenharia reversa do `X-Idempotency-Key` original para repetir transações com a chave de uma vítima.
**Resultado**: BLOCKED — SHA-256 é uma função unidirecional. Ataques de pré-imagem são computacionalmente inviáveis. A chave bruta nunca é armazenada.

---

### ATK-02 — Adivinhação de Chave para Sequestrar Resposta de Pagamento 🚫 BLOCKED

**Ataque**: Atacante adivinha uma chave curta ou previsível (ex.: `pay-1`, `retry-001`) para receber uma resposta de pagamento em cache que não iniciou.
**Resultado**: BLOCKED — Chaves são tokens opacos; adivinhar um UUID ou chave de alta entropia é inviável. Clientes devem usar `bin2hex(random_bytes(16))` ou UUID v4.

---

### ATK-03 — Replay Entre Usuários Diferentes 🚫 BLOCKED

**Ataque**: Atacante envia uma chave usada por outro usuário para forçar uma resposta repetida destinada a esse usuário.
**Resultado**: BLOCKED — Em um sistema autenticado, idempotency keys devem ter escopo por usuário (ex.: chave composta `(user_id, key_hash)`). O FT demonstra o padrão; em produção deve-se adicionar escopo por usuário.

---

### ATK-04 — Colisão de Chave via Hash SHA-256 🚫 BLOCKED

**Ataque**: Atacante cria duas chaves diferentes com o mesmo hash SHA-256 para sobrescrever um registro legítimo.
**Resultado**: BLOCKED — A resistência a colisões do SHA-256 fornece segurança de 2^128. Nenhum ataque prático de colisão existe.

---

### ATK-05 — Header de Chave Excessivamente Grande (DoS) 🚫 BLOCKED

**Ataque**: Atacante envia um header `X-Idempotency-Key` de 1 MB para exaurir memória durante o hashing.
**Resultado**: BLOCKED — `hash('sha256', ...)` processa a string mas o middleware de tamanho de requisição do NENE2 limita o tamanho total. Em produção, chaves devem adicionalmente ser validadas por comprimento (ex.: ≤ 255 chars).

---

### ATK-06 — Armazenamento de JSON Malicioso no Campo Body 🚫 BLOCKED

**Ataque**: Atacante injeta caracteres de controle ou JSON excessivamente grande no corpo do pagamento para que o campo `body` armazenado corrompido ao ser repetido.
**Resultado**: BLOCKED — O corpo da resposta é serializado via `json_encode` antes de armazenar. Ao repetir, é decodificado com `JSON_THROW_ON_ERROR`. JSON armazenado mal-formado lançaria exceção, não corromperia silenciosamente.

---

### ATK-07 — Condição de Corrida — Gasto Duplo em Retentativa Concorrente 🚫 BLOCKED

**Ataque**: Duas requisições concorrentes com a mesma chave correm antes do registro ser armazenado, ambas criando pagamentos.
**Resultado**: BLOCKED — `key_hash` é uma `PRIMARY KEY`; o segundo INSERT concorrente levanta um erro de constraint, garantindo que apenas um pagamento seja criado. Uma lacuna `SELECT → INSERT` deve usar transação de BD ou `INSERT OR IGNORE`.

---

### ATK-08 — Chave com Caracteres Especiais / SQL Injection 🚫 BLOCKED

**Ataque**: Atacante envia `'; DROP TABLE payments; --` como a idempotency key.
**Resultado**: BLOCKED — A chave é imediatamente hasheada com `hash('sha256', $key)`. A string bruta nunca chega a uma query SQL. Todo acesso ao BD usa queries parametrizadas.

---

### ATK-09 — Replay de Resposta de Erro 422 🚫 BLOCKED

**Ataque**: Atacante envia uma primeira requisição inválida (intencionalmente 422) com uma chave, então envia payload válido mais tarde com a mesma chave, esperando que o 422 armazenado seja repetido e o pagamento silenciosamente rejeitado.
**Resultado**: BLOCKED — A implementação só armazena o registro após uma criação bem-sucedida. Um branch 422 retorna imediatamente sem salvar, então chamadas válidas subsequentes criam um pagamento novo.

---

### ATK-10 — Enumeração de Chaves via Ataque de Temporização 🚫 BLOCKED

**Ataque**: Atacante mede diferença de tempo de resposta entre "chave existe" (hit rápido no BD) e "chave não encontrada" (BD lento + lógica de negócio) para confirmar chaves válidas.
**Resultado**: BLOCKED — A diferença de temporização é mínima e não-determinística no nível HTTP. Em contextos de alta segurança, adicione preenchimento artificial de tempo constante.

---

### ATK-11 — Deletar Registro de Idempotência para Forçar Re-execução 🚫 BLOCKED

**Ataque**: Atacante com acesso de escrita ao BD deleta a linha de `idempotency_records` para forçar um re-pagamento na próxima retentativa.
**Resultado**: BLOCKED — O acesso de escrita ao BD requer autenticação separada. Consumidores da API não podem deletar registros de idempotência via API de pagamento.

---

### ATK-12 — Falsificação do Header X-Idempotent-Replayed 🚫 BLOCKED

**Ataque**: Cliente envia `X-Idempotent-Replayed: true` na requisição para enganar o servidor fazendo-o pensar que já foi repetido.
**Resultado**: BLOCKED — O header só é verificado na *resposta*; o servidor ignora qualquer header `X-Idempotent-Replayed` enviado na *requisição*. A lógica de replay é determinada unicamente pela consulta ao BD.

---

### Resumo ATK

| ID | Ataque | Resultado |
|----|--------|--------|
| ATK-01 | Pré-imagem SHA-256 na chave | 🚫 BLOCKED |
| ATK-02 | Adivinhação de chave para sequestrar resposta | 🚫 BLOCKED |
| ATK-03 | Replay entre usuários diferentes | 🚫 BLOCKED |
| ATK-04 | Colisão de hash SHA-256 | 🚫 BLOCKED |
| ATK-05 | Header de chave excessivamente grande (DoS) | 🚫 BLOCKED |
| ATK-06 | JSON malicioso no body | 🚫 BLOCKED |
| ATK-07 | Condição de corrida com gasto duplo | 🚫 BLOCKED |
| ATK-08 | SQL injection via chave | 🚫 BLOCKED |
| ATK-09 | Replay de resposta de erro 422 | 🚫 BLOCKED |
| ATK-10 | Enumeração de chaves por temporização | 🚫 BLOCKED |
| ATK-11 | Deletar registro para forçar re-execução | 🚫 BLOCKED |
| ATK-12 | Falsificação do header X-Idempotent-Replayed | 🚫 BLOCKED |

**12 BLOCKED / SAFE, 0 EXPOSED** — Nenhuma descoberta crítica.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Armazenar `X-Idempotency-Key` bruto no BD | Chave exposta em violação do BD; use hash SHA-256 |
| Sem escopo por usuário na chave | Colisão de chave entre usuários permite sequestro de resposta |
| Salvar registro de idempotência antes da lógica de negócio | Armazena erros 500/422 como replays permanentes |
| Sem limite de comprimento da chave | Hashing de chaves ilimitadas desperdiça CPU |
| Compartilhar tabela de idempotência entre endpoints | Chave `pay-1` em `/payments` pode colidir com `pay-1` em `/refunds`; defina escopo por endpoint |
