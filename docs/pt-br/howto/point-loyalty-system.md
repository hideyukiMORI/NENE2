# Sistema de Pontos e Fidelidade

Guia de implementação de sistema de fidelidade com concessão/consumo de pontos, gerenciamento de saldo e ajuste pelo admin.
Explica transações idempotentes (reference_id), proteção de limite inferior de saldo e RBAC de admin.

## Visão Geral

- Usuários podem ganhar (earn) e gastar (spend) pontos
- Apenas admins podem ajustar diretamente os pontos (adjust: add/subtract)
- O saldo é gerenciado acumulando `balance_after` das transações
- Transações idempotentes via `reference_id` (prevenção de concessão/consumo duplo)
- Prevenção de concessão em massa fraudulenta com limite de valor (MAX_EARN_PER_TRANSACTION) em vez de max_uses

## Endpoints

| Método | Caminho | Descrição | Permissão |
|---|---|---|---|
| `GET` | `/users/{userId}/points` | Obter saldo | Próprio ou admin |
| `GET` | `/users/{userId}/points/history` | Obter histórico | Próprio ou admin |
| `POST` | `/users/{userId}/points/earn` | Conceder pontos | Próprio ou admin |
| `POST` | `/users/{userId}/points/spend` | Consumir pontos | Próprio ou admin |
| `POST` | `/users/{userId}/points/adjust` | Ajustar pontos | Apenas admin |

## Design do Banco de Dados

```sql
CREATE TABLE point_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    type TEXT NOT NULL CHECK (type IN ('earn', 'spend', 'adjust', 'expire')),
    amount INTEGER NOT NULL CHECK (amount > 0),
    balance_after INTEGER NOT NULL CHECK (balance_after >= 0),
    description TEXT NOT NULL,
    reference_id TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

A restrição CHECK `balance_after >= 0` previne saldo negativo no nível do banco.
A restrição CHECK `amount > 0` rejeita transações de valor zero ou negativo no nível do banco.

## Cálculo do Saldo

```php
public function getBalance(int $userId): int
{
    $row = $this->executor->fetchOne(
        'SELECT balance_after FROM point_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        [$userId]
    );
    return $row !== null ? (int) $row['balance_after'] : 0;
}
```

`balance_after` da transação mais recente é o saldo atual.
Sem manter o saldo em tabela separada — o histórico de transações é a única fonte de verdade (SSOT).

## Transações Idempotentes (reference_id)

```php
if ($referenceId !== null) {
    $existing = $this->repository->findByReferenceId($targetUserId, $referenceId);
    if ($existing !== null) {
        return $this->responseFactory->create($this->formatTransaction($existing), 200);
    }
}
// ... processar nova transação
```

Se o `reference_id` já existir, retorna a transação existente (200).
Previne crédito duplo e débito duplo.

## Verificação de Saldo ao Consumir Pontos

```php
$balance = $this->repository->getBalance($targetUserId);
if ($balance < $amount) {
    return $this->responseFactory->create([
        'error' => 'insufficient points',
        'balance' => $balance,
        'required' => $amount,
    ], 422);
}
$balanceAfter = $balance - $amount;  // sempre >= 0
```

Verificação de saldo na camada de aplicação, com defesa dupla pela restrição CHECK do banco.

## Ajuste Admin (adjust)

```php
// adjust_type: 'add' (padrão) ou 'subtract'
if ($adjustType === 'subtract') {
    if ($balance < $amount) { return 422 'insufficient points for adjustment'; }
    $balanceAfter = $balance - $amount;
} else {
    $balanceAfter = $balance + $amount;
}
$this->repository->addTransaction($userId, 'adjust', $amount, $balanceAfter, $description, null, $now);
```

## Controle de Limite (MAX_EARN_PER_TRANSACTION)

```php
private const int MAX_EARN_PER_TRANSACTION = 10000;

if ($amount > self::MAX_EARN_PER_TRANSACTION) {
    return $this->responseFactory->create([
        'error' => 'amount exceeds maximum per transaction',
        'max' => self::MAX_EARN_PER_TRANSACTION,
    ], 422);
}
```

Previne ataques de concessão fraudulenta de grandes quantidades de pontos em uma única transação.

## Controle de Acesso

Próprio saldo e histórico são acessíveis apenas pelo próprio usuário. Admin pode visualizar e operar em todos os usuários.

```php
if ($targetUserId !== $actorId && !$this->isAdmin($request)) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

earn/spend só podem operar nos próprios pontos (usuário não pode aumentar os pontos de outra pessoa).

## Exemplos de Resposta

### POST /users/2/points/earn
```json
{
  "id": 1,
  "user_id": 2,
  "type": "earn",
  "amount": 100,
  "balance_after": 100,
  "description": "Purchase reward",
  "reference_id": "order-123",
  "created_at": "2026-05-21T..."
}
```

### GET /users/2/points/history
```json
{
  "user_id": 2,
  "balance": 70,
  "transactions": [
    {"id": 2, "type": "spend", "amount": 30, "balance_after": 70, ...},
    {"id": 1, "type": "earn", "amount": 100, "balance_after": 100, ...}
  ]
}
```
