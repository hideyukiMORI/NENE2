# Event Sourcing (Básico)

Persista o estado como uma sequência imutável de eventos de domínio. Derive o estado atual fazendo replay do fluxo de eventos.

## Visão geral

Event sourcing armazena **o que aconteceu** (eventos) em vez de **o que é** (estado atual). O saldo de uma conta não é armazenado; é computado fazendo replay de todos os eventos de depósito e saque. Eventos são imutáveis — nunca são atualizados ou deletados.

## Schema do banco de dados

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
    payload      TEXT    NOT NULL,  -- JSON
    occurred_at  TEXT    NOT NULL,
    FOREIGN KEY (aggregate_id) REFERENCES accounts(id)
);
```

`accounts` é o aggregate root. `events` é o log de eventos append-only. Não há coluna `balance` — ela é sempre computada a partir dos eventos.

## Tipos de evento

Defina tipos de evento como constantes para prevenir erros de digitação e habilitar análise estática:

```php
public const string TYPE_ACCOUNT_CREATED = 'account_created';
public const string TYPE_DEPOSITED       = 'deposited';
public const string TYPE_WITHDRAWN       = 'withdrawn';
```

## Acrescentando eventos

Eventos são sempre inseridos, nunca atualizados. A API não tem endpoint que modifica ou deleta eventos:

```php
public function appendEvent(int $aggregateId, string $eventType, array $payload, string $now): DomainEvent
{
    $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

    $this->executor->execute(
        'INSERT INTO events (aggregate_id, event_type, payload, occurred_at) VALUES (?, ?, ?, ?)',
        [$aggregateId, $eventType, $payloadJson, $now],
    );
    ...
}
```

## Fazendo replay do estado

Carregue eventos em ordem de inserção e aplique-os ao estado atual:

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

`ORDER BY id ASC` garante a ordem de replay. `ORDER BY occurred_at ASC` é frágil — dois eventos com o mesmo timestamp teriam ordem indefinida.

## Validação de valor

Valide valores estritamente antes de acrescentar eventos:

```php
$amount = isset($body['amount']) && is_int($body['amount']) ? $body['amount'] : 0;

if ($amount <= 0 || $amount > 1_000_000_000) {
    return 422;
}
```

- `is_int()` rejeita valores float (ex.: `1.9`) que o PHP caso contrário truncaria silenciosamente para `1`
- O limite superior previne overflow de inteiros ao somar múltiplos depósitos grandes
- Rejeite na camada da API — não deixe valores inválidos chegar ao log de eventos

## Fundos insuficientes

Verifique o saldo antes de acrescentar um evento de saque:

```php
$balance = $this->repo->replayBalance($id);

if ($amount > $balance) {
    return $this->problems->create($request, 'insufficient-funds', 'Insufficient funds.', 422, '');
}

$event = $this->repo->appendEvent($id, DomainEvent::TYPE_WITHDRAWN, ['amount' => $amount], $now);
```

A verificação de saldo acontece no handler (não no repositório) porque é uma regra de negócio, não uma constraint de integridade de dados.

## Isolamento de eventos

Eventos são escopados ao seu aggregate por `aggregate_id`. Fazer replay dos eventos da Conta A nunca toca a Conta B:

```sql
SELECT * FROM events WHERE aggregate_id = ? ORDER BY id ASC
```

## Propriedades de segurança

| Propriedade | Implementação |
|---|---|
| Imutabilidade de eventos | Sem endpoint DELETE/UPDATE em eventos |
| Intervalo de valor | 1–1.000.000.000 (int) — rejeita floats e valores de overflow |
| Fundos insuficientes | Saldo feito replay antes do saque; 422 se insuficiente |
| Isolamento entre contas | Todas as consultas filtram por aggregate_id |
| Injeção de payload | Payload sempre `['amount' => int]`; sem chaves controladas pelo usuário |
| Injeção de tipo de evento | Tipo de evento sempre de constantes; sem event_type controlado pelo usuário |

## Resumo de rotas

| Método | Caminho | Descrição |
|---|---|---|
| `POST` | `/accounts` | Criar conta |
| `POST` | `/accounts/{id}/deposit` | Acrescentar evento de depósito |
| `POST` | `/accounts/{id}/withdraw` | Acrescentar evento de saque (verificação de saldo) |
| `GET` | `/accounts/{id}/balance` | Replay do saldo a partir de eventos |
| `GET` | `/accounts/{id}/events` | Listar todos os eventos da conta |
