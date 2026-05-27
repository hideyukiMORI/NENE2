# Como Fazer: API de Workflow com Máquina de Estado

> **Referência FT**: FT349 (`NENE2-FT/workflowlog`) — Instâncias de workflow com máquina de estado, mapa de transições hardcoded, `allowed_next` nas respostas, log de histórico de transições, aplicação de estado terminal, filtro de estado na listagem, 13 testes PASS.

Este guia mostra como construir um motor de workflow usando uma máquina de estado: definir transições de estado permitidas, criar instâncias de workflow, conduzi-las pelos estados com atribuição de ator e registrar o histórico completo de transições.

## Schema

```sql
CREATE TABLE instances (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    workflow      TEXT    NOT NULL,          -- ex.: "order"
    current_state TEXT    NOT NULL,
    context       TEXT    NOT NULL DEFAULT '{}',  -- JSON
    created_at    TEXT    NOT NULL,
    updated_at    TEXT    NOT NULL
);

CREATE TABLE transitions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id INTEGER NOT NULL REFERENCES instances(id) ON DELETE CASCADE,
    from_state  TEXT    NOT NULL,
    to_state    TEXT    NOT NULL,
    actor       TEXT    NOT NULL,
    note        TEXT    NOT NULL DEFAULT '',
    occurred_at TEXT    NOT NULL
);
```

## Definição de Workflow — "order"

```
draft ──┬──► submitted ──┬──► approved ──► fulfilled (terminal)
        │                ├──► rejected   (terminal)
        └──► cancelled   └──► cancelled  (terminal)
        (terminal)
```

| Estado Atual | Próximos Estados Permitidos           |
|--------------|---------------------------------------|
| `draft`      | `submitted`, `cancelled`              |
| `submitted`  | `approved`, `cancelled`, `rejected`   |
| `approved`   | `fulfilled`                           |
| `fulfilled`  | _(terminal — nenhum)_                 |
| `cancelled`  | _(terminal — nenhum)_                 |
| `rejected`   | _(terminal — nenhum)_                 |

## Endpoints

| Método | Caminho                                           | Descrição                       |
|--------|---------------------------------------------------|---------------------------------|
| `POST` | `/workflows/{workflow}/instances`                 | Criar instância de workflow     |
| `GET`  | `/workflows/{workflow}/instances`                 | Listar instâncias               |
| `GET`  | `/workflows/{workflow}/instances/{id}`            | Obter instância com histórico   |
| `POST` | `/workflows/{workflow}/instances/{id}/transition` | Conduzir transição de estado    |

## Criar Instância

```php
POST /workflows/order/instances
{"context": {"order_ref": "ORD-001", "amount": 99.99}}

→ 201
{
  "id": 1,
  "workflow": "order",
  "current_state": "draft",
  "context": {"order_ref": "ORD-001", "amount": 99.99},
  "allowed_next": ["submitted", "cancelled"],  // ← próximos estados válidos
  "created_at": "...",
  "updated_at": "..."
}
```

`allowed_next` é computado a partir do mapa de transições — sempre reflete o estado atual.

### Workflow Desconhecido → 404

```php
POST /workflows/unknown/instances  {}
→ 404  // workflow não definido
```

## Listar Instâncias

```php
// Todas as instâncias do workflow "order"
GET /workflows/order/instances
→ 200  {"instances": [{...}, {...}]}

// Filtrar por estado atual
GET /workflows/order/instances?state=draft
→ 200  {"instances": [{...}]}  // apenas instâncias em draft
```

## Obter Instância (com Histórico)

```php
GET /workflows/order/instances/1

→ 200
{
  "id": 1,
  "workflow": "order",
  "current_state": "approved",
  "context": {...},
  "allowed_next": ["fulfilled"],
  "history": [
    {
      "from_state": "draft",
      "to_state": "submitted",
      "actor": "alice",
      "occurred_at": "..."
    },
    {
      "from_state": "submitted",
      "to_state": "approved",
      "actor": "manager",
      "occurred_at": "..."
    }
  ],
  ...
}
```

`history` é sempre ordenado cronologicamente (ASC por `occurred_at`). O endpoint de listagem omite `history` por performance.

## Conduzir Transições

```php
// Transição válida
POST /workflows/order/instances/1/transition
{"to_state": "submitted", "actor": "alice"}

→ 200
{
  "current_state": "submitted",
  "allowed_next": ["approved", "cancelled", "rejected"],
  "history": [
    {"from_state": "draft", "to_state": "submitted", "actor": "alice", ...}
  ]
}
```

### Caminho Feliz Completo

```php
POST .../transition  {"to_state": "submitted", "actor": "alice"}     → submitted
POST .../transition  {"to_state": "approved",  "actor": "manager"}   → approved
POST .../transition  {"to_state": "fulfilled", "actor": "warehouse"}  → fulfilled
```

### Transição Inválida → 409

```php
// draft não pode transicionar para approved (apenas submitted ou cancelled)
POST .../transition  {"to_state": "approved", "actor": "alice"}
→ 409
{
  "type": "https://nene2.dev/problems/invalid-transition",
  "detail": "Transição de 'draft' para 'approved' não é permitida"
}
```

### Estado Terminal → 409

```php
// cancelled é terminal — nenhuma transição permitida
POST .../transition  {"to_state": "draft", "actor": "alice"}
→ 409  // "cancelled" não tem transições permitidas
```

## Implementação

### WorkflowDefinition — Mapa de Transições

```php
final class WorkflowDefinition
{
    /** @var array<string, array<string, list<string>>> */
    private static array $transitions = [
        'order' => [
            'draft'     => ['submitted', 'cancelled'],
            'submitted' => ['approved', 'cancelled', 'rejected'],
            'approved'  => ['fulfilled'],
            'fulfilled' => [],     // terminal
            'cancelled' => [],     // terminal
            'rejected'  => [],     // terminal
        ],
    ];

    /** @return list<string> */
    public static function allowedTransitions(string $workflow, string $fromState): array
    {
        return self::$transitions[$workflow][$fromState] ?? [];
    }

    public static function isValidWorkflow(string $workflow): bool
    {
        return isset(self::$transitions[$workflow]);
    }

    public static function initialState(string $workflow): string
    {
        return match ($workflow) {
            'order' => 'draft',
            default => throw new \InvalidArgumentException("Workflow desconhecido: {$workflow}"),
        };
    }
}
```

### Handler de Transição

```php
public function transition(int $id, string $toState, string $actor): ?WorkflowInstance
{
    $instance = $this->repo->findByIdOrNull($id);
    if ($instance === null) {
        return null;  // → 404
    }

    $allowed = WorkflowDefinition::allowedTransitions(
        $instance->workflow,
        $instance->currentState,
    );

    if (!in_array($toState, $allowed, true)) {
        return false;  // → 409 inválido ou terminal
    }

    // Atômico: atualizar instância + inserir log de transição
    $this->db->execute(
        'UPDATE instances SET current_state = ?, updated_at = ? WHERE id = ?',
        [$toState, $now, $id],
    );
    $this->db->execute(
        'INSERT INTO transitions (instance_id, from_state, to_state, actor, occurred_at) VALUES (?, ?, ?, ?, ?)',
        [$id, $instance->currentState, $toState, $actor, $now],
    );

    return $this->hydrateInstanceWithHistory($id);
}
```

`allowed_next` é sempre computado a partir do mapa de transições, nunca armazenado — permanece consistente com `current_state`.

---

## O Que NÃO Fazer

| Antipadrão | Risco |
|---|---|
| Armazenar `allowed_next` no banco | Dados obsoletos se o mapa de transições mudar; sempre computar a partir do estado atual |
| Permitir `to_state` de formato livre sem verificação de allowlist | Atacante pode definir estado para qualquer valor, contornando a lógica do workflow |
| Pular log de transição | Sem trilha de auditoria; não é possível reconstruir histórico do workflow ou depurar instâncias travadas |
| Retornar estados terminais em `allowed_next` | Engana os chamadores; estados terminais sempre têm `allowed_next` vazio |
| Retornar 404 para transição inválida | 404 esconde a distinção entre "instância não encontrada" e "transição não permitida"; use 409 para o último caso |
| Sem campo `workflow` na tabela de instâncias | Não é possível distinguir instâncias de diferentes tipos de workflow; sem possibilidade de query cruzada de workflows |
