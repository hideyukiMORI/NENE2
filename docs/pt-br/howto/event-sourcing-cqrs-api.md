# Como Fazer: API de Event Sourcing & CQRS

> **Referência FT**: `NENE2-FT/eventstore` — Log de eventos append-only com números de sequência por aggregate, tipos de evento com lista de permissões, projeção de read-model reconstruída a partir de eventos, rastreamento de saldo, 17 testes PASS.

Este guia mostra como implementar event sourcing: armazenar cada mudança de estado como um evento imutável, computar o estado atual a partir do log de eventos e expor uma projeção de read-model.

## Schema

```sql
-- Log de eventos append-only — nunca faça UPDATE ou DELETE nas linhas
CREATE TABLE domain_events (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    aggregate_id   TEXT    NOT NULL,  -- ex.: "acc-001"
    aggregate_type TEXT    NOT NULL,  -- ex.: "account"
    event_type     TEXT    NOT NULL,  -- ex.: "MoneyDeposited"
    payload        TEXT    NOT NULL DEFAULT '{}',  -- JSON
    sequence       INTEGER NOT NULL,  -- contador por aggregate, começa em 1
    occurred_at    TEXT    NOT NULL,
    UNIQUE(aggregate_id, sequence)
);

-- Read-model: estado atual da conta reconstruído a partir de eventos
CREATE TABLE account_projections (
    account_id    TEXT    PRIMARY KEY,
    owner         TEXT    NOT NULL,
    balance_cents INTEGER NOT NULL DEFAULT 0,
    is_open       INTEGER NOT NULL DEFAULT 1,
    last_sequence INTEGER NOT NULL DEFAULT 0,
    updated_at    TEXT    NOT NULL
);
```

`UNIQUE(aggregate_id, sequence)` previne inserção de eventos duplicados. A projeção é sempre derivada do log de eventos — pode ser reconstruída a qualquer momento por replay.

## Tipos de evento (lista de permissões)

```php
const ALLOWED_EVENTS = [
    'AccountOpened',
    'MoneyDeposited',
    'MoneyWithdrawn',
    'AccountClosed',
];
```

Tipos de evento desconhecidos retornam 422. Apenas acrescente eventos que têm handlers explícitos na lógica de projeção.

## Endpoints

| Método | Caminho                       | Descrição                          |
|--------|-------------------------------|------------------------------------|
| `POST` | `/accounts`                   | Abrir conta (emite AccountOpened)  |
| `GET`  | `/accounts`                   | Listar todas as projeções de conta |
| `GET`  | `/accounts/{id}`              | Obter projeção de conta (404 se não encontrada) |
| `POST` | `/accounts/{id}/events`       | Acrescentar evento à conta         |
| `GET`  | `/accounts/{id}/events`       | Listar log de eventos da conta     |

## Abrir conta

```php
POST /accounts
{"account_id": "acc-001", "owner": "Alice"}

→ 201
{
  "event_type": "AccountOpened",
  "aggregate_id": "acc-001",
  "sequence": 1,
  "payload": {"owner": "Alice"},
  "occurred_at": "..."
}
```

Abrir uma conta cria um evento `AccountOpened` (sequence=1) e inicializa a projeção.

## Acrescentar eventos

```php
POST /accounts/acc-001/events
{"event_type": "MoneyDeposited", "payload": {"amount_cents": 50000}}

→ 201
{
  "event_type": "MoneyDeposited",
  "aggregate_id": "acc-001",
  "sequence": 2,         // ← incrementa por aggregate
  "payload": {"amount_cents": 50000},
  "occurred_at": "..."
}
```

Cada conta tem um **contador de sequência independente**. `acc-001` e `acc-002` ambas começam em 1.

```php
// Tipo de evento inválido → 422
POST /accounts/acc-001/events  {"event_type": "UnknownEvent"}
→ 422

// Conta inexistente → 404
POST /accounts/nonexistent/events  {"event_type": "MoneyDeposited", "payload": {"amount_cents": 1000}}
→ 404
```

## Projeção de read-model

```php
GET /accounts/acc-001

→ 200
{
  "account_id": "acc-001",
  "owner": "Alice",
  "balance_cents": 60000,   // 50000 depositados + 10000 depositados
  "is_open": true,
  "last_sequence": 3
}

// Evento AccountClosed aplicado
GET /accounts/acc-001  // após AccountClosed acrescentado
→ 200  {"is_open": false, "last_sequence": 4}
```

```php
GET /accounts/nonexistent
→ 404
```

## Log de eventos

```php
GET /accounts/acc-001/events

→ 200
{
  "total": 3,
  "items": [
    {"event_type": "AccountOpened",  "sequence": 1, ...},
    {"event_type": "MoneyDeposited", "sequence": 2, "payload": {"amount_cents": 50000}, ...},
    {"event_type": "MoneyWithdrawn", "sequence": 3, "payload": {"amount_cents": 30000}, ...}
  ]
}
```

Ordenado por `sequence ASC` — ordem cronológica.

```php
// Conta desconhecida → lista vazia (não 404)
GET /accounts/nonexistent/events
→ 200  {"total": 0, "items": []}
```

## Implementação

### Geração de sequência

```php
public function nextSequence(string $aggregateId): int
{
    $row = $this->db->fetchOne(
        'SELECT MAX(sequence) AS seq FROM domain_events WHERE aggregate_id = ?',
        [$aggregateId],
    );
    return (int) ($row['seq'] ?? 0) + 1;
}
```

### Acrescentar evento + atualizar projeção (transação)

```php
public function appendEvent(string $aggregateId, string $eventType, array $payload): array
{
    $sequence = $this->nextSequence($aggregateId);
    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

    $this->tx->begin();
    try {
        // Acrescentar ao log imutável
        $id = $this->db->insert(
            'INSERT INTO domain_events (aggregate_id, aggregate_type, event_type, payload, sequence, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$aggregateId, 'account', $eventType, json_encode($payload), $sequence, $now->format('Y-m-d H:i:s')],
        );

        // Atualizar projeção
        $this->applyProjection($aggregateId, $eventType, $payload, $sequence, $now);

        $this->tx->commit();
    } catch (\Throwable $e) {
        $this->tx->rollback();
        throw $e;
    }

    return $this->db->fetchOne('SELECT * FROM domain_events WHERE id = ?', [$id]);
}
```

### Lógica de projeção

```php
private function applyProjection(
    string $aggregateId,
    string $eventType,
    array $payload,
    int $sequence,
    \DateTimeImmutable $now,
): void {
    $ts = $now->format('Y-m-d H:i:s');
    match ($eventType) {
        'AccountOpened' => $this->db->execute(
            'INSERT INTO account_projections (account_id, owner, balance_cents, is_open, last_sequence, updated_at)
             VALUES (?, ?, 0, 1, ?, ?)',
            [$aggregateId, $payload['owner'] ?? '', $sequence, $ts],
        ),
        'MoneyDeposited' => $this->db->execute(
            'UPDATE account_projections SET balance_cents = balance_cents + ?, last_sequence = ?, updated_at = ?
             WHERE account_id = ?',
            [$payload['amount_cents'], $sequence, $ts, $aggregateId],
        ),
        'MoneyWithdrawn' => $this->db->execute(
            'UPDATE account_projections SET balance_cents = balance_cents - ?, last_sequence = ?, updated_at = ?
             WHERE account_id = ?',
            [$payload['amount_cents'], $sequence, $ts, $aggregateId],
        ),
        'AccountClosed' => $this->db->execute(
            'UPDATE account_projections SET is_open = 0, last_sequence = ?, updated_at = ? WHERE account_id = ?',
            [$sequence, $ts, $aggregateId],
        ),
        default => null,
    };
}
```

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| UPDATE ou DELETE linhas em `domain_events` | Destrói a trilha de auditoria; projeções ficam inconsistentes com o histórico |
| Sem `UNIQUE(aggregate_id, sequence)` | Eventos duplicados corrompem a projeção no replay |
| Armazenar saldo computado em `domain_events` | Estado derivado pertence à projeção, não ao log de eventos |
| Permitir tipos de evento arbitrários | Lógica de projeção sem handler → no-op silencioso ou crash |
| Acrescentar evento sem atualizar projeção na mesma transação | Janela de inconsistência entre log de eventos e read model |
| Sem contador de sequência por aggregate | Não é possível detectar lacunas de replay ou conflitos de escrita concorrentes |
