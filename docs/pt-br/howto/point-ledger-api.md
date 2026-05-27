# Como Fazer: API de Razão de Pontos

> **Referência FT**: FT300 (`NENE2-FT/pointlog`) — API de razão de pontos: transações de ganho/gasto/ajuste/expiração, rastreamento de saldo, prevenção de saldo negativo (CHECK balance_after >= 0), ajuste apenas para admin, idempotência por reference_id, limites MAX_EARN=10000 / MAX_ADJUST=100000, ATK-01~12 todos BLOCKED, 30 testes / 66 assertivas PASS.

Este guia mostra como construir uma razão de pontos de fidelidade onde usuários ganham e gastam pontos, admins ajustam saldos e reference IDs previnem transações duplicadas.

## Schema

```sql
CREATE TABLE users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    role       TEXT    NOT NULL DEFAULT 'user',
    created_at TEXT    NOT NULL,
    CHECK (role IN ('user', 'admin'))
);

CREATE TABLE point_transactions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    type         TEXT    NOT NULL,
    amount       INTEGER NOT NULL,
    balance_after INTEGER NOT NULL,
    description  TEXT    NOT NULL,
    reference_id TEXT,
    created_at   TEXT    NOT NULL,
    CHECK (type IN ('earn', 'spend', 'adjust', 'expire')),
    CHECK (amount > 0),
    CHECK (balance_after >= 0),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

Três restrições CHECK como defesa em profundidade:
- `amount > 0` — sem transações de zero ou negativas no nível do banco
- `balance_after >= 0` — saldo nunca pode ficar negativo no armazenamento
- `type IN (...)` — apenas tipos de transação conhecidos são aceitos

## Endpoints

| Método | Caminho | Auth | Descrição |
|--------|---------|------|-----------|
| `GET` | `/users/{userId}/points` | `X-User-Id` | Obter saldo atual |
| `GET` | `/users/{userId}/points/history` | `X-User-Id` | Obter histórico de transações |
| `POST` | `/users/{userId}/points/earn` | `X-User-Id` (próprio) | Ganhar pontos |
| `POST` | `/users/{userId}/points/spend` | `X-User-Id` (próprio) | Gastar pontos |
| `POST` | `/users/{userId}/points/adjust` | `X-User-Id` (admin) | Ajuste de admin |

## Autenticação e Autorização

```php
private function requireUserId(ServerRequestInterface $request): ?int
{
    $val = $request->getHeaderLine('X-User-Id');
    return $val !== '' ? (int) $val : null;
}

private function isAdmin(ServerRequestInterface $request): bool
{
    return $request->getHeaderLine('X-User-Role') === 'admin';
}
```

Todo handler chama `requireUserId()` primeiro:

```php
$actorId = $this->requireUserId($request);
if ($actorId === null) {
    return $this->responseFactory->create(['error' => 'authentication required'], 401);
}
```

Acesso entre usuários é então verificado para earn/spend:

```php
$targetUserId = (int) $this->routeParam($request, 'userId');
if ($targetUserId !== $actorId && !$this->isAdmin($request)) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

Admins podem ver o saldo ou histórico de qualquer usuário. Não-admins só podem acessar o próprio.

## Validação Estrita de Inteiro

```php
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : null;
if ($amount === null || $amount <= 0) {
    return $this->responseFactory->create(['error' => 'amount must be a positive integer'], 422);
}
```

`is_int()` rejeita:
- Floats: `10.5` — rejeitado (422)
- Strings: `"100"` — rejeitado (422)
- Booleanos: `true` — rejeitado (422)
- Zero: `0` — rejeitado (amount <= 0)
- Negativos: `-500` — rejeitado (amount <= 0)

## Limites de Transação

```php
private const int MAX_EARN_PER_TRANSACTION  = 10000;
private const int MAX_ADJUST_PER_TRANSACTION = 100000;
```

```php
if ($amount > self::MAX_EARN_PER_TRANSACTION) {
    return $this->responseFactory->create([
        'error' => 'amount exceeds maximum per transaction',
        'max'   => self::MAX_EARN_PER_TRANSACTION,
    ], 422);
}
```

Ganho é limitado a 10.000 por transação. Ajuste de admin é limitado a 100.000 (maior porque é uma operação de correção privilegiada).

## Prevenção de Saldo Negativo

```php
$balance = $this->repository->getBalance($targetUserId);
if ($balance < $amount) {
    return $this->responseFactory->create([
        'error'    => 'insufficient points',
        'balance'  => $balance,
        'required' => $amount,
    ], 422);
}
```

Verifique o saldo atual antes de deduzir. Retornar o saldo atual e o valor necessário no erro ajuda os clientes a exibir uma mensagem significativa aos usuários.

## Ajuste de Admin

```php
private function handleAdjust(ServerRequestInterface $request): ResponseInterface
{
    $actorId = $this->requireUserId($request);
    if ($actorId === null) {
        return $this->responseFactory->create(['error' => 'authentication required'], 401);
    }
    if (!$this->isAdmin($request)) {
        return $this->responseFactory->create(['error' => 'admin role required'], 403);
    }
    // ...
    $adjustType = isset($body['adjust_type']) && $body['adjust_type'] === 'subtract' ? 'subtract' : 'add';
    // ...
}
```

O ajuste verifica `isAdmin()` **antes** de verificar o usuário alvo — um não-admin recebe 403 imediatamente independente do alvo. O campo `adjust_type` (`'add'` padrão / `'subtract'`) permite que admins concedam e deduzam pontos sem precisar de endpoints separados.

## Idempotência com reference_id

```php
if ($referenceId !== null) {
    $existing = $this->repository->findByReferenceId($targetUserId, $referenceId);
    if ($existing !== null) {
        return $this->responseFactory->create($this->formatTransaction($existing), 200);
    }
}
```

Quando um `reference_id` é fornecido:
- Primeira chamada → 201 Created com nova transação
- Chamada repetida com mesmo `reference_id` → 200 OK com a transação original (sem nova transação criada)

Isso previne créditos duplos em retentativas de rede. A busca de reference_id tem **escopo de usuário** (`findByReferenceId($targetUserId, ...)`) para que o mesmo reference_id possa ser usado por usuários diferentes sem conflito.

## Cálculo do Saldo

```php
// Repositório: somar todas as transações earn/adjust-add menos spend/adjust-subtract/expire
public function getBalance(int $userId): int
{
    // Normalmente: balance_after da transação mais recente, ou 0 se não houver nenhuma
    $row = $this->executor->fetchOne(
        'SELECT balance_after FROM point_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        [$userId]
    );
    return $row !== null ? (int) $row['balance_after'] : 0;
}
```

A coluna `balance_after` em cada transação armazena o saldo corrente. Obter o saldo atual é uma única query `ORDER BY id DESC LIMIT 1` — sem necessidade de agregação SUM.

## Formato da Resposta

```php
private function formatTransaction(array $t): array
{
    return [
        'id'           => isset($t['id'])           ? (int)    $t['id']           : null,
        'user_id'      => isset($t['user_id'])       ? (int)    $t['user_id']       : null,
        'type'         => $t['type']         ?? null,
        'amount'       => isset($t['amount'])        ? (int)    $t['amount']        : null,
        'balance_after'=> isset($t['balance_after']) ? (int)    $t['balance_after'] : null,
        'description'  => $t['description']  ?? null,
        'reference_id' => $t['reference_id'] ?? null,
        'created_at'   => $t['created_at']   ?? null,
    ];
}
```

---

## Avaliação ATK — Teste de Ataque com Mentalidade de Cracker

### ATK-01 — Acesso ao Saldo sem Autenticação 🚫 BLOCKED

**Ataque**: `GET /users/2/points` sem header `X-User-Id`.
**Resultado**: BLOCKED — `requireUserId()` retorna null → 401 imediatamente. Sem dados retornados.

---

### ATK-02 — Visualização do Saldo de Outro Usuário 🚫 BLOCKED

**Ataque**: `GET /users/2/points` com `X-User-Id: 3` (Alice tentando ler o saldo de Bob).
**Resultado**: BLOCKED — `$targetUserId (2) !== $actorId (3)` e não é admin → 403.

---

### ATK-03 — Auto-Concessão para Outro Usuário 🚫 BLOCKED

**Ataque**: `POST /users/3/points/earn` com `X-User-Id: 2` e `amount: 99999`.
**Resultado**: BLOCKED — ator (2) != alvo (3) e não é admin → 403. Saldo do alvo permanece 0.

---

### ATK-04 — Ganho com Valor Negativo 🚫 BLOCKED

**Ataque**: `POST /users/2/points/earn` com `amount: -500`.
**Resultado**: BLOCKED — verificação `$amount <= 0` → 422. Saldo inalterado.

---

### ATK-05 — Transação com Valor Zero 🚫 BLOCKED

**Ataque**: `POST /users/2/points/earn` com `amount: 0`, e separadamente `amount: 0` para spend.
**Resultado**: BLOCKED — ambos retornam 422 (`amount <= 0`). Sem transações de valor zero criadas.

---

### ATK-06 — Gasto com Saldo Negativo 🚫 BLOCKED

**Ataque**: Ganhar 100 pontos, depois tentar gastar 101.
**Resultado**: BLOCKED — `$balance (100) < $amount (101)` → 422 com `insufficient points`. Saldo permanece em 100. A restrição `CHECK (balance_after >= 0)` do banco fornece um backstop adicional.

---

### ATK-07 — Usuário Regular Ajustando 🚫 BLOCKED

**Ataque**: `POST /users/2/points/adjust` com `X-User-Id: 2` (função não-admin).
**Resultado**: BLOCKED — verificação `isAdmin()` falha → 403. Saldo permanece 0.

---

### ATK-08 — Valor de Ganho Excessivo 🚫 BLOCKED

**Ataque**: `POST /users/2/points/earn` com `amount: 10001` (acima de MAX_EARN=10000).
**Resultado**: BLOCKED — `$amount > MAX_EARN_PER_TRANSACTION` → 422 com `max: 10000`. Saldo inalterado.

---

### ATK-09 — Crédito Duplo via Reutilização de reference_id 🚫 BLOCKED

**Ataque**: Ganhar 500 pontos com `reference_id: "order-999"`, depois repetir a mesma requisição.
**Resultado**: BLOCKED — segunda chamada encontra transação existente via `findByReferenceId()` → 200 com a mesma transação. Saldo permanece 500 (não 1000).

---

### ATK-10 — Débito Duplo via Reutilização de reference_id 🚫 BLOCKED

**Ataque**: Gastar 300 pontos com `reference_id: "redemption-777"`, depois repetir.
**Resultado**: BLOCKED — segunda chamada retorna a transação de gasto original (200). Saldo permanece 700 (não 400).

---

### ATK-11 — Injeção SQL em reference_id 🚫 BLOCKED

**Ataque**: `reference_id: "' OR '1'='1' --"` em uma requisição de ganho.
**Resultado**: BLOCKED — queries parametrizadas armazenam a string de injeção literalmente. Saldo é 100, não corrompido. `reference_id` na resposta corresponde à string injetada exatamente (armazenada como dado, não interpretada como SQL).

---

### ATK-12 — Valor Float 🚫 BLOCKED

**Ataque**: `POST /users/2/points/earn` com `amount: 10.5`.
**Resultado**: BLOCKED — `is_int(10.5)` é false → null → 422. Saldo inalterado.

---

### Resumo ATK

| ID | Ataque | Resultado |
|----|--------|-----------|
| ATK-01 | Acesso ao saldo sem autenticação | 🚫 BLOCKED |
| ATK-02 | Visualização do saldo de outro usuário | 🚫 BLOCKED |
| ATK-03 | Auto-concessão para outro usuário | 🚫 BLOCKED |
| ATK-04 | Ganho com valor negativo | 🚫 BLOCKED |
| ATK-05 | Transação com valor zero | 🚫 BLOCKED |
| ATK-06 | Gasto com saldo negativo | 🚫 BLOCKED |
| ATK-07 | Ajuste por usuário regular | 🚫 BLOCKED |
| ATK-08 | Valor de ganho excessivo (>MAX) | 🚫 BLOCKED |
| ATK-09 | Crédito duplo via reference_id | 🚫 BLOCKED |
| ATK-10 | Débito duplo via reference_id | 🚫 BLOCKED |
| ATK-11 | Injeção SQL em reference_id | 🚫 BLOCKED |
| ATK-12 | Valor float | 🚫 BLOCKED |

**12 BLOCKED, 0 EXPOSED**
Nenhum achado crítico. Cadeia de auth (401→403), validação de valor (is_int + >0 + cap), guarda de saldo negativo e idempotência de reference_id previnem todos os vetores de ataque conhecidos.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Sem verificação de `X-User-Id` (autenticação ignorada) | Acesso não autenticado a todos os saldos e transações |
| Ganho entre usuários sem verificação de admin | Qualquer usuário ganha pontos na conta de qualquer outro usuário |
| `$amount > 0` sem `is_int()` | Float `10.5` passa; pontos fracionários corrompem a integridade da razão |
| Sem limite MAX_EARN | Atacante ganha pontos INT_MAX em uma requisição |
| Sem verificação de saldo antes do gasto | Saldo fica negativo; CHECK do banco é último recurso, não guarda principal |
| Sem idempotência de `reference_id` | Retentativa de rede dobra créditos ou cobranças |
| Espaço de `reference_id` compartilhado entre usuários | `order-1` do Usuário A impede o Usuário B de usar a mesma referência |
| `getBalance()` via agregação SUM em tabelas grandes | Scan completo da tabela por requisição; usar total corrente `balance_after` |
| Ajuste de admin sem verificação de função primeiro | Não-admin submete ajuste grande; verificar função antes de qualquer lógica de negócio |
| Retornar 200 em duplicata sem mesmo corpo de transação | Cliente não consegue verificar idempotência; deve retornar a transação original |
