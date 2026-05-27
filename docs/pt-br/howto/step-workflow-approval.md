# Como Fazer: Workflow em Etapas com Aprovação

> **Referência FT**: FT247 (`NENE2-FT/stepflowlog`) — API de Aprovação de Workflow em Etapas

Demonstra um sistema de workflow em dois níveis onde uma definição de workflow reutilizável mantém uma lista ordenada de etapas, e uma execução de workflow é uma instância dessa definição progredindo pelas etapas via ações de aprovar/rejeitar. Cada ação é registrada em um log de histórico de auditoria.

---

## Rotas

| Método | Caminho                   | Descrição                                                     |
|--------|---------------------------|---------------------------------------------------------------|
| `POST` | `/workflows`              | Definir um novo workflow                                      |
| `GET`  | `/workflows/{id}`         | Obter workflow com suas etapas                                |
| `POST` | `/workflows/{id}/steps`   | Adicionar uma etapa ao workflow (auto-ordenada)               |
| `POST` | `/runs`                   | Iniciar uma execução de um workflow (falha se não tiver etapas) |
| `GET`  | `/runs/{id}`              | Obter status da execução com histórico de ações               |
| `POST` | `/runs/{id}/approve`      | Aprovar etapa atual (avança para próxima etapa ou completa)   |
| `POST` | `/runs/{id}/reject`       | Rejeitar etapa atual (termina execução como rejeitada)        |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS workflows (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL UNIQUE,
    description TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS workflow_steps (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    workflow_id INTEGER NOT NULL REFERENCES workflows(id) ON DELETE CASCADE,
    name        TEXT    NOT NULL,
    step_order  INTEGER NOT NULL,
    UNIQUE(workflow_id, step_order)
);

CREATE TABLE IF NOT EXISTS workflow_runs (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    workflow_id     INTEGER NOT NULL REFERENCES workflows(id),
    title           TEXT    NOT NULL,
    status          TEXT    NOT NULL DEFAULT 'pending'
                        CHECK(status IN ('pending', 'in_progress', 'completed', 'rejected')),
    current_step_id INTEGER REFERENCES workflow_steps(id),
    created_at      TEXT    NOT NULL,
    updated_at      TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS workflow_actions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id     INTEGER NOT NULL REFERENCES workflow_runs(id) ON DELETE CASCADE,
    step_id    INTEGER NOT NULL REFERENCES workflow_steps(id),
    action     TEXT    NOT NULL CHECK(action IN ('approve', 'reject')),
    actor      TEXT    NOT NULL,
    comment    TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL
);
```

`UNIQUE(workflow_id, step_order)` previne ordenação duplicada dentro de um workflow. `current_step_id` é nullable — `NULL` significa que a execução está `completed` ou `rejected` (sem etapa ativa). `action` tem um `CHECK` no nível do banco para `approve`/`reject`.

---

## Auto-ordenação de Etapas

Ao adicionar uma etapa, o controller computa automaticamente o próximo `step_order`:

```php
$existingSteps = $this->repo->findSteps($id);
$maxOrder      = 0;
foreach ($existingSteps as $s) {
    if ((int) $s['step_order'] > $maxOrder) {
        $maxOrder = (int) $s['step_order'];
    }
}
$stepOrder = $maxOrder + 1;
$stepId    = $this->repo->addStep($id, $name, $stepOrder);
```

`step_order` começa em `1` e incrementa em `1` para cada nova etapa. A restrição `UNIQUE` previne que duas etapas compartilhem a mesma ordem. Etapas são sempre retornadas em ordem:

```php
$this->db->fetchAll(
    'SELECT * FROM workflow_steps WHERE workflow_id = ? ORDER BY step_order ASC',
    [$workflowId],
);
```

---

## Iniciando uma Execução: Inicialização da Primeira Etapa

Uma execução é inicializada com a primeira etapa do workflow como `current_step_id`:

```php
$steps = $this->repo->findSteps($workflowId);
if ($steps === []) {
    return $this->json->create(['error' => 'Workflow não tem etapas'], 409);
}

$firstStep = $steps[0];
$runId = $this->repo->createRun($workflowId, $title, (int) $firstStep['id'], $now);
```

`409 Conflict` é retornado quando o workflow não tem etapas — uma execução não pode progredir por um workflow sem etapas. A primeira etapa (menor `step_order`) se torna a etapa ativa.

---

## `approve`: Avançar para Próxima Etapa ou Completar

`current_step_id` é definido como `null` na rejeição — nenhuma etapa ativa permanece. A execução é terminal: chamadas adicionais de `approve`/`reject` retornam `409` porque `status !== 'in_progress'`.

---

## Histórico de Ações: JOIN com Nome da Etapa

A resposta de execução inclui o histórico completo de ações:

```php
$run     = $this->repo->findRun($id);
$actions = $this->repo->findActions($id);
return $this->json->create(array_merge($run, ['history' => $actions]));
```

Ações são buscadas com um `JOIN` para enriquecer cada linha com o nome da etapa:

```php
$this->db->fetchAll(
    'SELECT wa.*, ws.name AS step_name FROM workflow_actions wa
     JOIN workflow_steps ws ON wa.step_id = ws.id
     WHERE wa.run_id = ? ORDER BY wa.id ASC',
    [$runId],
);
```

`ORDER BY wa.id ASC` preserva a ordem de inserção cronológica para a trilha de auditoria.

---

## Máquina de Estado de Execução

```
                 POST /runs
                     │
                     ▼
               in_progress  ──approve (última etapa)──► completed
                     │
               approve (não é última etapa)
                     │
                     ▼
               in_progress (próxima etapa)
                     │
                  reject
                     │
                     ▼
                 rejected
```

Os estados `completed` e `rejected` são terminais — nenhuma transição de estado adicional é permitida. Qualquer `approve`/`reject` em uma execução terminal retorna `409 Conflict`.

---

## `findRun` com `current_step_name` via `LEFT JOIN`

A execução é buscada com um `LEFT JOIN` para incluir o nome da etapa atual:

```php
$this->db->fetchOne(
    'SELECT wr.*, ws.name AS current_step_name, ws.step_order AS current_step_order
     FROM workflow_runs wr
     LEFT JOIN workflow_steps ws ON wr.current_step_id = ws.id
     WHERE wr.id = ?',
    [$id],
);
```

`LEFT JOIN` (não `INNER JOIN`) — quando `current_step_id` é `null` (execução completa/rejeitada), as colunas `ws.*` são `null` em vez de fazer a linha desaparecer.

---

## Howtos Relacionados

- [`approval-workflow.md`](approval-workflow.md) — padrão de aprovação com estados pending/approved/rejected
- [`state-machine-audit-log.md`](state-machine-audit-log.md) — registro de transição de estado e InvalidTransitionException
- [`multi-step-workflow.md`](multi-step-workflow.md) — padrão de formulário/processo multi-etapa sequencial
- [`audit-trail.md`](audit-trail.md) — padrões de registro de eventos somente adição
