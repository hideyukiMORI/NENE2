# Como Fazer: Ledger de Event Sourcing

> **Referência FT**: FT310 (`NENE2-FT/eventsourcelog`) — Ledger de conta por event sourcing: log de eventos imutável (append-only), `replayBalance()` faz replay de todos os eventos para computar o saldo atual, eventos de depósito/saque nunca deletados, validação estrita de valor com `is_int()`, valor máximo 1.000.000.000, contas separadas não compartilham saldo, 17 testes / 24 asserções PASS.

Este guia mostra como implementar um ledger de conta usando event sourcing: o saldo atual não é armazenado diretamente — é derivado pelo replay de todos os eventos passados.

## Schema

```sql
CREATE TABLE accounts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    owner      TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE events (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    aggregate_id INTEGER NOT NULL,
    event_type   TEXT    NOT NULL,
    payload      TEXT    NOT NULL,  -- JSON: { "amount": 100 }
    occurred_at  TEXT    NOT NULL,
    FOREIGN KEY (aggregate_id) REFERENCES accounts(id)
);
```

`events` é append-only. Não há `UPDATE` ou `DELETE` em eventos. Cada depósito ou saque acrescenta uma nova linha.

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/accounts` | Criar conta |
| `GET` | `/accounts/{id}/balance` | Obter saldo atual (replay) |
| `POST` | `/accounts/{id}/deposit` | Acrescentar evento de depósito |
| `POST` | `/accounts/{id}/withdraw` | Acrescentar evento de saque |
| `GET` | `/accounts/{id}/events` | Listar todos os eventos |

## Saldo — Replay a partir de eventos

```php
public function replayBalance(int $aggregateId): int
{
    $events  = $this->findEventsByAggregateId($aggregateId);
    $balance = 0;

    foreach ($events as $event) {
        $amount = isset($event->payload['amount']) ? (int) $event->payload['amount'] : 0;

        if ($event->eventType === DomainEvent::TYPE_DEPOSITED) {
            $balance += $amount;
        } elseif ($event->eventType === DomainEvent::TYPE_WITHDRAWN) {
            $balance -= $amount;
        }
    }

    return $balance;
}
```

O saldo não é armazenado em nenhum lugar — é computado fazendo replay de todos os eventos. Novas contas começam em 0 (sem eventos). O log de eventos é a fonte da verdade.

## Validação de depósito

```php
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : 0;
if ($amount <= 0 || $amount > 1_000_000_000) {
    return $this->problems->create($request, 'validation-failed',
        'amount must be a positive integer not exceeding 1000000000.', 422, '');
}
// Acrescentar evento
$this->repo->appendEvent($id, 'AccountDeposited', ['amount' => $amount], date('c'));
```

- `is_int()` rejeita floats, strings, null
- `> 0` rejeita zero e negativos
- `<= 1_000_000_000` limita o valor de transação única

## Saque — Verificar saldo do replay primeiro

```php
$balance = $this->repo->replayBalance($id);
if ($amount > $balance) {
    return $this->problems->create($request, 'validation-failed',
        'insufficient funds.', 422, '');
}
$this->repo->appendEvent($id, 'AccountWithdrawn', ['amount' => $amount], date('c'));
```

Faça o replay do saldo antes de aceitar um saque. A verificação de saldo negativo acontece na camada de aplicação — não em constraints do DB (a linha de evento não tem noção de "saldo após").

## Isolamento de conta

Cada conta tem seu próprio `aggregate_id`. `replayBalance()` filtra por `aggregate_id`, portanto:
- Depósitos da Conta 1 não afetam o saldo da Conta 2
- Listas de eventos são por conta (sem contaminação cruzada)

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Armazenar coluna `balance` em vez de fazer replay | Estado mutável pode ficar fora de sincronia; eventos são a verdade |
| Permitir deleção de eventos | Deletar eventos torna a computação de saldo incorreta retroativamente |
| Aceitar `amount` float | Valores fracionários no payload do evento corrompem o replay de inteiros |
| Sem verificação de saldo negativo no nível da aplicação | Saldo negativo possível já que eventos não têm constraint de saldo |
| Tabela de eventos compartilhada sem filtro `aggregate_id` | Todas as contas compartilham o mesmo fluxo de eventos |
| Fazer replay de todos os eventos de todas as contas para um saldo | Full table scan em vez de filtrado por conta |
