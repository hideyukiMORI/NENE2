# Como Fazer: API de Ledger de Créditos

> **Referência FT**: FT234 (`NENE2-FT/creditslog`) — API de Ledger de Créditos

Demonstra um ledger de créditos append-only onde os saldos nunca são armazenados diretamente —
eles são calculados no momento da consulta como `SUM(amount * direction)`. Suporta ganho de
créditos, gasto de créditos com guarda de overdraft, ganho idempotente via chave única e
histórico de transações filtrável.

---

## Rotas

| Método | Caminho                                   | Descrição                                       |
|--------|-------------------------------------------|-------------------------------------------------|
| `POST` | `/users/{userId}/credits/earn`            | Ganhar créditos (adicionar ao saldo)            |
| `POST` | `/users/{userId}/credits/spend`           | Gastar créditos (deduzir do saldo, 409 em overdraft) |
| `GET`  | `/users/{userId}/credits/balance`         | Obter saldo atual                               |
| `GET`  | `/users/{userId}/credits/transactions`    | Listar histórico de transações (opcional `?type=`) |

---

## Modelo de ledger: `direction` em vez de valor com sinal

Em vez de armazenar valores positivos e negativos, cada transação armazena um
`amount` positivo e uma `direction` com sinal (`+1` para ganho, `-1` para gasto):

```sql
CREATE TABLE IF NOT EXISTS credit_transactions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         TEXT    NOT NULL,
    type            TEXT    NOT NULL CHECK(type IN ('earn', 'spend', 'adjust')),
    amount          INTEGER NOT NULL CHECK(amount > 0),
    direction       INTEGER NOT NULL CHECK(direction IN (1, -1)),
    description     TEXT    NOT NULL DEFAULT '',
    idempotency_key TEXT    UNIQUE,
    created_at      TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_credit_transactions_user ON credit_transactions (user_id);
```

Benefícios do padrão de coluna `direction`:
- `CHECK(amount > 0)` garante que o valor bruto seja sempre positivo — sem bugs de dupla-negação
  acidental na inserção.
- `CHECK(direction IN (1, -1))` restringe o multiplicador a dois valores válidos.
- A fórmula de saldo é uniforme: `SUM(amount * direction)` — sem ramificação condicional
  na agregação.
- Um tipo `adjust` está disponível para correções manuais (ex.: reembolsos, concessões admin)
  usando qualquer direção.

---

## Cálculo do saldo

O saldo é calculado no momento da leitura — nenhuma coluna `balance` é atualizada:

```php
public function balance(string $userId): int
{
    $row = $this->db->fetchOne(
        'SELECT COALESCE(SUM(amount * direction), 0) AS bal FROM credit_transactions WHERE user_id = ?',
        [$userId],
    );

    return (int) ($row['bal'] ?? 0);
}
```

`COALESCE(..., 0)` lida com o caso onde um usuário não tem transações — `SUM` de um
conjunto vazio retorna `NULL` em SQL, que de qualquer forma seria convertido para `0`,
mas `COALESCE` torna a intenção explícita.

O índice em `user_id` garante que a agregação `SUM` escaneie apenas as linhas desse usuário.
Para ledgers grandes, uma coluna de saldo em cache com optimistic locking ou snapshots de
event sourcing vale a pena considerar (consulte `add-optimistic-locking.md`).

---

## Ganho com chave de idempotência opcional

Fornecer `idempotency_key` torna a operação de ganho segura para retry — uma chave duplicada
retorna a transação original em vez de inserir uma nova:

```php
public function earn(string $userId, int $amount, string $description, ?string $idempotencyKey): CreditTransaction
{
    $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    if ($idempotencyKey !== null) {
        try {
            $id = $this->db->insert(
                'INSERT INTO credit_transactions (user_id, type, amount, direction, description, idempotency_key, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$userId, 'earn', $amount, 1, $description, $idempotencyKey, $now],
            );
        } catch (DatabaseConstraintException) {
            // Chave já usada — retornar a transação original
            $row = $this->db->fetchOne(
                'SELECT * FROM credit_transactions WHERE idempotency_key = ?',
                [$idempotencyKey],
            );
            assert($row !== null);

            return $this->hydrate($row);
        }
    } else {
        $id = $this->db->insert(
            'INSERT INTO credit_transactions (user_id, type, amount, direction, description, idempotency_key, created_at) VALUES (?, ?, ?, NULL, ?)',
            [$userId, 'earn', $amount, 1, $description, $now],
        );
    }

    $row = $this->db->fetchOne('SELECT * FROM credit_transactions WHERE id = ?', [$id]);
    assert($row !== null);

    return $this->hydrate($row);
}
```

A constraint `UNIQUE` em `idempotency_key` torna o BD a autoridade — a
aplicação captura `DatabaseConstraintException` e re-busca a linha existente.
Isso evita uma condição de corrida SELECT-before-INSERT: dois retries concorrentes com
a mesma chave resultarão em exatamente um INSERT bem-sucedido.

---

## Gasto com guarda de overdraft

```php
public function spend(string $userId, int $amount, string $description): CreditTransaction
{
    $balance = $this->balance($userId);
    if ($balance < $amount) {
        throw new InsufficientCreditsException("Insufficient credits: balance={$balance}, requested={$amount}");
    }

    $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $id  = $this->db->insert(
        'INSERT INTO credit_transactions (user_id, type, amount, direction, description, idempotency_key, created_at) VALUES (?, ?, ?, ?, ?, NULL, ?)',
        [$userId, 'spend', $amount, -1, $description, $now],
    );
    // ...
}
```

O controller mapeia `InsufficientCreditsException` para `409 Conflict`:

```php
try {
    $tx = $this->repo->spend($userId, $amount, $description);
} catch (InsufficientCreditsException $e) {
    return $this->problems->create($request, 'insufficient-credits', 'Insufficient Credits', 409, $e->getMessage());
}
```

`409 Conflict` é preferível a `422 Unprocessable Entity` porque a requisição é
válida — é o estado do saldo que a impede. Um chamador que tentar novamente após ganhar
mais créditos terá sucesso.

> **Nota de concorrência**: a verificação de saldo e a inserção não são encapsuladas em uma transação.
> Duas requisições de gasto concorrentes podem ambas ler um saldo suficiente e ambas inserir,
> deixando o saldo negativo. Encapsule em uma transação com `SELECT ... FOR UPDATE`
> (MySQL/PostgreSQL) ou use escritas serializadas do SQLite para corretude sob concorrência.

---

## Validação de valor

```php
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : 0;

$errors = [];
if ($amount <= 0) {
    $errors[] = ['field' => 'amount', 'code' => 'invalid', 'message' => 'amount must be a positive integer.'];
}
```

Verificação estrita `is_int()` rejeita floats JSON (`1.5`) e strings (`"10"`). O
`CHECK(amount > 0)` no nível do BD age como reforço, mas rejeitar na camada de aplicação dá
uma resposta Problem Details estruturada em vez de um erro do BD.

---

## Histórico de transações com filtro por tipo

```php
private function transactions(ServerRequestInterface $request): ResponseInterface
{
    $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $userId = (string) ($params['userId'] ?? '');
    $q      = $request->getQueryParams();
    $type   = isset($q['type']) && is_string($q['type']) ? $q['type'] : null;

    $txs = $this->repo->listTransactions($userId, $type);

    return $this->json->create([
        'user_id'      => $userId,
        'transactions' => array_map(fn (CreditTransaction $t) => $t->toArray(), $txs),
    ]);
}
```

`?type=earn` ou `?type=spend` estreita a lista. Nenhuma validação é realizada no valor do tipo —
um tipo desconhecido (ex.: `?type=refund`) retorna uma lista vazia em vez de um erro,
o que é aceitável para um parâmetro de filtro.

---

## Notas de design do schema

| Coluna | Propósito |
|--------|-----------|
| `amount` | Sempre positivo; `CHECK(amount > 0)` aplica isso |
| `direction` | `+1` (ganho) ou `-1` (gasto); `CHECK(direction IN (1, -1))` |
| `type` | Rótulo legível: `earn`, `spend`, `adjust`; allowlist `CHECK` |
| `idempotency_key` | Chave `UNIQUE` opcional para operações de ganho seguras para retry |
| `description` | Memo de texto livre para a transação |

Sem coluna `balance` — o saldo atual é sempre derivado do ledger.

---

## Howtos relacionados

- [`idempotency.md`](idempotency.md) — padrões gerais de chave de idempotência
- [`multi-currency-wallet.md`](multi-currency-wallet.md) — gerenciamento de saldo multi-moeda
- [`point-loyalty-system.md`](point-loyalty-system.md) — ganho/resgate de pontos com níveis de fidelidade
- [`add-optimistic-locking.md`](add-optimistic-locking.md) — saldo em cache com guarda de versão
- [`transactions.md`](transactions.md) — encapsular verificação de saldo e inserção em uma transação
