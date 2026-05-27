# Como Fazer: API de Rastreamento de Orçamento

> **Referência FT**: FT244 (`NENE2-FT/budgetlog`) — API de Rastreamento de Orçamento
> **ATK**: FT244 — teste de ataque com mentalidade de cracker (ATK-01 a ATK-12)

Demonstra uma API de rastreamento de orçamento com múltiplas contas, tipos de transação `income`/`expense`/`transfer`,
`TransferFundsUseCase` com verificação de saldo dentro de uma transação de banco de dados,
listagem de transações com múltiplos filtros usando `QueryStringParser` e agregação por categoria.

---

## Rotas

| Método | Caminho                              | Descrição                                            |
|--------|--------------------------------------|------------------------------------------------------|
| `GET`  | `/accounts`                          | Listar todas as contas                               |
| `POST` | `/accounts`                          | Criar uma conta (saldo inicial opcional)             |
| `GET`  | `/accounts/{id}`                     | Obter uma conta única                                |
| `POST` | `/accounts/{id}/transactions`        | Registrar transação de receita ou despesa            |
| `GET`  | `/accounts/{id}/transactions`        | Listar transações (filtrável, paginado)              |
| `GET`  | `/accounts/{id}/summary`             | Saldo + receita/despesa por categoria                |
| `POST` | `/transfers`                         | Transferir fundos entre duas contas                  |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS accounts (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    name    TEXT    NOT NULL,
    balance INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS transactions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id  INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    amount      INTEGER NOT NULL,
    type        TEXT    NOT NULL CHECK(type IN ('income','expense','transfer')),
    category    TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    recurring   INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL
);
```

`balance` e `amount` são armazenados como inteiros (menor unidade de moeda, ex.: centavos).
`type` é restrito por `CHECK(type IN ('income','expense','transfer'))` no nível do banco de dados.
`recurring` é armazenado como `INTEGER` (`0`/`1`), mapeado para um `bool` PHP.

---

## Allowlist de tipo de transação

O controller valida `type` contra uma allowlist explícita:

```php
if (!in_array($type, ['income', 'expense'], true)) {
    $errors[] = new ValidationError('type', 'Type must be income or expense.', 'invalid_value');
}
```

Apenas `income` e `expense` são aceitos da API. O tipo `transfer` é definido
internamente por `TransferFundsUseCase` — os chamadores não podem injetá-lo diretamente através de
`POST /accounts/{id}/transactions`.

---

## Atualização de saldo: padrão ler-então-atualizar

`POST /accounts/{id}/transactions` atualiza o saldo da conta após registrar a
transação:

```php
$delta = $type === 'income' ? $amount : -$amount;
$this->accounts->updateBalance($id, $account->balance + $delta);
```

O saldo é lido primeiro (`findById`), o delta aplicado no PHP e depois gravado de volta
(`updateBalance`). Isso **não é atômico** — requisições concorrentes podem criar uma condição
de corrida (veja ATK-09).

---

## TransferFundsUseCase: verificação de saldo + transação DB

As transferências são envolvidas em uma transação DB para garantir consistência:

```php
public function execute(int $fromId, int $toId, int $amount, string $description): void
{
    if ($amount <= 0) {
        throw new ValidationException([
            new ValidationError('amount', 'Amount must be greater than zero.', 'out_of_range'),
        ]);
    }

    $this->txManager->transactional(function (DatabaseQueryExecutorInterface $tx) use ($fromId, $toId, $amount, $description): void {
        // Instanciar repos dentro do callback com o executor de transação
        $accounts     = new SqliteAccountRepository($tx);
        $transactions = new SqliteTransactionRepository($tx);

        $from = $accounts->findById($fromId);
        $to   = $accounts->findById($toId);

        if ($from === null) {
            throw new ValidationException([new ValidationError('from_account_id', 'Source account not found.', 'not_found')]);
        }
        if ($to === null) {
            throw new ValidationException([new ValidationError('to_account_id', 'Destination account not found.', 'not_found')]);
        }
        if ($from->balance < $amount) {
            throw new ValidationException([new ValidationError('amount', 'Insufficient balance.', 'insufficient_balance')]);
        }

        $accounts->updateBalance($fromId, $from->balance - $amount);
        $accounts->updateBalance($toId, $to->balance + $amount);

        $transactions->create($fromId, $amount, 'transfer', 'transfer', $description, false, $now);
        $transactions->create($toId, $amount, 'transfer', 'transfer', $description, false, $now);
    });
}
```

Os repositórios são instanciados **dentro** do closure de transação com o executor `$tx`
— isso garante que todas as leituras e escritas compartilhem a mesma conexão e limite de transação.
Se qualquer etapa lançar exceção, toda a transação reverte.

A guarda de mesma conta está no controller:
```php
if ($fromId === $toId && $fromId > 0) {
    $errors[] = new ValidationError('to_account_id', 'Cannot transfer to the same account.', 'invalid_value');
}
```

---

## Listagem de transações com múltiplos filtros

`GET /accounts/{id}/transactions` suporta múltiplos filtros simultâneos:

```php
$category  = QueryStringParser::string($req, 'category');
$minAmount = QueryStringParser::int($req, 'min_amount');
$maxAmount = QueryStringParser::int($req, 'max_amount');
$recurring = QueryStringParser::bool($req, 'recurring');
```

`QueryStringParser::int()` retorna `null` quando o parâmetro está ausente — sem filtro.
`QueryStringParser::bool()` retorna `null` quando ausente, `true` para `"true"/"1"`, `false`
para `"false"/"0"`.

O repositório constrói a cláusula `WHERE` dinamicamente:

```php
if ($category !== null)  { $where[] = 'category = ?'; $params[] = $category; }
if ($minAmount !== null) { $where[] = 'amount >= ?';  $params[] = $minAmount; }
if ($maxAmount !== null) { $where[] = 'amount <= ?';  $params[] = $maxAmount; }
if ($recurring !== null) { $where[] = 'recurring = ?'; $params[] = (int) $recurring; }
```

---

## Agregação de resumo por categoria

`GET /accounts/{id}/summary` retorna saldo e totais agrupados por categoria:

```php
return $this->json->create([
    'balance'             => $account->balance,
    'income_by_category'  => $incomeByCategory,
    'expense_by_category' => $expenseByCategory,
]);
```

O repositório usa `GROUP BY category` com `SUM(amount)`:

```sql
SELECT category, SUM(amount) AS total
FROM transactions
WHERE account_id = ? AND type = ?
GROUP BY category
ORDER BY total DESC
```

---

## ATK — Teste de ataque com mentalidade de cracker (FT244)

### ATK-01 — Sem autenticação: contas e transações são públicas

**Ataque**: Listar todas as contas sem credenciais.

```bash
curl -s http://localhost:8080/accounts
curl -s http://localhost:8080/accounts/1/transactions
```

**Observado**: Ambos os endpoints retornam dados sem autenticação. Qualquer chamador pode
enumerar todas as contas e seus saldos.

**Veredicto**: **EXPOSED** — adicione autenticação (chave de API, JWT ou sessão) a todos
os endpoints. As contas devem ter escopo por usuário.

---

### ATK-02 — Criar conta com saldo inicial negativo

**Ataque**: Contornar a verificação de saldo negativo.

```json
{"name": "Attack", "initial_balance": -99999}
```

**Observado**: `$initialBalance < 0` dispara → `422 Unprocessable Entity` com
erro `out_of_range`.

**Veredicto**: **BLOCKED** — guarda explícita rejeita saldos iniciais negativos.

---

### ATK-03 — Despesa leva o saldo da conta a negativo

**Ataque**: Registrar uma despesa maior que o saldo da conta via transação direta.

```bash
# Conta tem saldo 100
curl -X POST /accounts/1/transactions \
  -d '{"amount": 99999, "type": "expense", "category": "food"}'
```

**Observado**: O handler `createTransaction` lê o saldo e depois subtrai sem
verificar suficiência. `100 - 99999 = -99899` — o saldo é gravado como inteiro negativo.

**Veredicto**: **EXPOSED** — `POST /accounts/{id}/transactions` não impõe uma
restrição de saldo não negativo. Somente `POST /transfers` (via `TransferFundsUseCase`)
verifica `if ($from->balance < $amount)`. Adicione uma verificação de suficiência de saldo em
`createTransaction` para transações de despesa.

---

### ATK-04 — SQL injection via category ou description

**Ataque**: Incorporar metacaracteres SQL em `category` ou `description`.

```json
{"amount": 1, "type": "income", "category": "'; DROP TABLE transactions; --"}
```

**Observado**: Todos os valores são vinculados como valores parametrizados `?`. Sem concatenação
de string com SQL. O payload de injeção é armazenado como texto literal.

**Veredicto**: **BLOCKED** — consultas parametrizadas previnem SQL injection.

---

### ATK-05 — Valor float: truncamento com cast `(int)`

**Ataque**: Enviar um valor de ponto flutuante.

```json
{"amount": 1.9, "type": "income", "category": "x"}
```

**Observado**: `(int) $body['amount']` trunca `1.9` para `1`. O valor `1.9`
é silenciosamente aceito e armazenado como `1`. Um chamador esperando que `1.9` seja rejeitado
(ou arredondado para `2`) seria surpreendido.

**Veredicto**: **PARCIALMENTE BLOCKED** — floats não inteiros são aceitos e silenciosamente
truncados. Use `is_int($body['amount'])` para rejeitar tipos não inteiros explicitamente,
retornando um `422` para `1.9`.

---

### ATK-06 — Valor zero ou negativo

**Ataque**: Enviar `amount: 0` ou `amount: -100`.

```json
{"amount": 0, "type": "income", "category": "x"}
{"amount": -100, "type": "income", "category": "x"}
```

**Observado**: `$amount <= 0` dispara para ambos → `422 Unprocessable Entity`.

**Veredicto**: **BLOCKED** — guarda explícita rejeita valores zero e negativos.

---

### ATK-07 — Transferência para a mesma conta

**Ataque**: Transferir fundos de uma conta para ela mesma.

```json
{"from_account_id": 1, "to_account_id": 1, "amount": 100}
```

**Observado**: `$fromId === $toId && $fromId > 0` dispara → `422 Unprocessable Entity`
com erro `invalid_value` em `to_account_id`.

**Veredicto**: **BLOCKED** — transferência para a mesma conta é explicitamente rejeitada.

---

### ATK-08 — Transferência com fundos insuficientes

**Ataque**: Transferir mais do que o saldo da conta de origem.

```json
{"from_account_id": 1, "to_account_id": 2, "amount": 99999}
```

**Observado**: Dentro da transação, `$from->balance < $amount` dispara →
`ValidationException` com `insufficient_balance` → transação reverte → `422`.
Nenhum saldo muda.

**Veredicto**: **BLOCKED** — `TransferFundsUseCase` verifica o saldo dentro da
transação DB. O rollback garante atomicidade.

---

### ATK-09 — Condição de corrida na transação de despesa direta

**Ataque**: Enviar duas requisições de despesa concorrentes que ambas passam pela verificação de saldo
(não há verificação) mas juntas excedem o saldo.

**Observado**: `createTransaction` usa o padrão ler-então-atualizar sem uma transação:
1. Thread A lê `balance = 100`
2. Thread B lê `balance = 100`
3. Thread A registra despesa de 80 → grava `balance = 20`
4. Thread B registra despesa de 80 → grava `balance = 20` (deveria ser -60)

A coluna `balance` termina em `20` em vez do correto `-60` — mas mais criticamente,
a restrição de negócio (saldo não negativo) nunca é aplicada para transações diretas,
permitindo que esse caminho contorne até mesmo o ler-então-atualizar.

**Veredicto**: **EXPOSED** — o caminho `createTransaction` não tem guarda de saldo nem
envoltório de transação. Corrija: (1) adicionando `if ($type === 'expense' && $account->balance < $amount) → 422`,
e (2) envolvendo o ler-então-atualizar em uma transação DB.

---

### ATK-10 — Acessar transações de outra conta (sem propriedade)

**Ataque**: Ler transações pertencentes à conta de um usuário diferente.

```bash
curl -s http://localhost:8080/accounts/2/transactions
```

**Observado**: O endpoint retorna todas as transações da conta 2 sem nenhuma
verificação de propriedade. Como não há autenticação, qualquer chamador pode ler qualquer conta.

**Veredicto**: **EXPOSED** (mesma raiz do ATK-01). As contas devem ter escopo para um
usuário autenticado — `WHERE account_id = ? AND owner_id = ?`.

---

### ATK-11 — Campo `recurring`: coerção truthy

**Ataque**: Enviar valores não booleanos para `recurring`.

```json
{"amount": 1, "type": "income", "category": "x", "recurring": "yes"}
{"amount": 1, "type": "income", "category": "x", "recurring": 1}
{"amount": 1, "type": "income", "category": "x", "recurring": 0}
```

**Observado**: `(bool) $body['recurring']` coerce `"yes"` → `true`, `1` → `true`,
`0` → `false`. Qualquer string truthy define `recurring = true`. Sem verificação estrita
`is_bool()`.

**Veredicto**: **PARCIALMENTE BLOCKED** — tipos não booleanos são silenciosamente coercidos. Use
`is_bool($body['recurring'])` para aplicação estrita de tipo, retornando `422` para
entrada não booleana.

---

### ATK-12 — ID de conta não numérico no caminho

**Ataque**: Passar um ID de string no parâmetro de caminho.

```
GET /accounts/abc/transactions
GET /accounts/1.5/transactions
```

**Observado**: `(int) 'abc'` = `0`, `(int) '1.5'` = `1`.
- `abc` → `findById(0)` → retorna `null` → `404 Not Found`.
- `1.5` → `findById(1)` → se conta 1 existe, retorna silenciosamente.

**Veredicto**: **PARCIALMENTE BLOCKED** — strings não numéricas mapeiam para 404. Strings
float são silenciosamente truncadas. Adicione validação `ctype_digit()` para verificação
estrita de parâmetros de caminho.

---

## Resumo ATK

| # | Vetor de ataque | Veredicto |
|---|-----------------|-----------|
| ATK-01 | Sem autenticação (todos os endpoints públicos) | EXPOSED |
| ATK-02 | Saldo inicial negativo | BLOCKED |
| ATK-03 | Despesa leva saldo a negativo | EXPOSED |
| ATK-04 | SQL injection via category/description | BLOCKED |
| ATK-05 | Float silenciosamente truncado | PARCIALMENTE BLOCKED |
| ATK-06 | Valor zero ou negativo | BLOCKED |
| ATK-07 | Transferência para a mesma conta | BLOCKED |
| ATK-08 | Transferência com fundos insuficientes | BLOCKED |
| ATK-09 | Condição de corrida na despesa direta | EXPOSED |
| ATK-10 | Acesso a dados entre contas (sem propriedade) | EXPOSED |
| ATK-11 | Coerção não booleana em `recurring` | PARCIALMENTE BLOCKED |
| ATK-12 | ID de conta não numérico | PARCIALMENTE BLOCKED |

**Vulnerabilidades reais para corrigir antes da produção**:
1. **ATK-01 / ATK-10** — Adicionar autenticação e propriedade de conta por usuário
2. **ATK-03 / ATK-09** — Adicionar verificação de suficiência de saldo + transação DB em `createTransaction`
3. **ATK-05** — Substituir cast `(int)` por verificação `is_int()` para aplicação estrita de tipo
4. **ATK-11** — Substituir cast `(bool)` por verificação `is_bool()`
5. **ATK-12** — Adicionar guarda `ctype_digit()` para parâmetros de ID no caminho

---

## Howtos relacionados

- [`credit-ledger.md`](credit-ledger.md) — ledger somente de acréscimo com direção ±1 e InsufficientCreditsException
- [`multi-currency-wallet.md`](multi-currency-wallet.md) — gerenciamento de saldo em múltiplas moedas
- [`transactions.md`](transactions.md) — padrões com DatabaseTransactionManagerInterface
- [`note-management-ownership.md`](note-management-ownership.md) — propriedade de recursos por usuário com prevenção IDOR
