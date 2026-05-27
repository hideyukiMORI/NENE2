# Como Adicionar um Fluxo de Trabalho em Múltiplas Etapas

Modele fluxos de aprovação sequencial onde cada etapa deve ser aprovada antes de avançar para a próxima.

## Schema

```sql
CREATE TABLE workflows (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE, description TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);
CREATE TABLE workflow_steps (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workflow_id INTEGER NOT NULL REFERENCES workflows(id) ON DELETE CASCADE,
    name TEXT NOT NULL, step_order INTEGER NOT NULL,
    UNIQUE(workflow_id, step_order)
);
CREATE TABLE workflow_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workflow_id INTEGER NOT NULL REFERENCES workflows(id),
    title TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','in_progress','completed','rejected')),
    current_step_id INTEGER REFERENCES workflow_steps(id),
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE TABLE workflow_actions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id INTEGER NOT NULL REFERENCES workflow_runs(id) ON DELETE CASCADE,
    step_id INTEGER NOT NULL REFERENCES workflow_steps(id),
    action TEXT NOT NULL CHECK(action IN ('approve','reject')),
    actor TEXT NOT NULL, comment TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);
```

## Rotas

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/workflows` | Definir um fluxo de trabalho |
| `GET` | `/workflows/{id}` | Obter fluxo de trabalho + etapas |
| `POST` | `/workflows/{id}/steps` | Adicionar uma etapa (ordenada automaticamente) |
| `POST` | `/runs` | Iniciar uma nova execução (começa na etapa 1) |
| `GET` | `/runs/{id}` | Obter estado da execução + histórico completo de ações |
| `POST` | `/runs/{id}/approve` | Aprovar etapa atual |
| `POST` | `/runs/{id}/reject` | Rejeitar → encerra a execução |

## Máquina de Estados

```
in_progress --approve (mais etapas)--> in_progress (próxima etapa)
in_progress --approve (etapa final)--> completed
in_progress --reject (qualquer etapa)---> rejected
```

Execuções completadas e rejeitadas retornam 409 em novas tentativas de aprovar/rejeitar.

## Ordenação Automática de Etapas

Somente adição: cada nova etapa recebe `max(step_order) + 1`:

```php
$existingSteps = $this->repo->findSteps($workflowId);
$maxOrder      = 0;
foreach ($existingSteps as $s) {
    $maxOrder = max($maxOrder, (int) $s['step_order']);
}
$stepOrder = $maxOrder + 1;
$this->repo->addStep($workflowId, $name, $stepOrder);
```

## Avançar ou Completar na Aprovação

```php
// Registrar ação primeiro, depois transicionar
$this->repo->recordAction($runId, $currentStepId, 'approve', $actor, $comment, $now);

$nextStep = $this->repo->findNextStep($workflowId, $currentStepOrder);
if ($nextStep !== null) {
    $this->repo->updateRun($runId, 'in_progress', (int) $nextStep['id'], $now);
} else {
    $this->repo->updateRun($runId, 'completed', null, $now);
}
```

Encontre a próxima etapa com uma única query SQL:

```sql
SELECT * FROM workflow_steps
WHERE workflow_id = ? AND step_order > ?
ORDER BY step_order ASC LIMIT 1
```

## Proteger Execuções Completadas/Rejeitadas

```php
if ((string) $run['status'] !== 'in_progress') {
    return $this->json->create(['error' => 'Run is not in progress'], 409);
}
```

## Join de Histórico

Retorne o histórico completo de ações com nomes de etapas na resposta `GET /runs/{id}`:

```sql
SELECT wa.*, ws.name AS step_name
FROM workflow_actions wa
JOIN workflow_steps ws ON wa.step_id = ws.id
WHERE wa.run_id = ?
ORDER BY wa.id ASC
```

## Decisões Principais de Design

- **Etapas somente de adição**: `step_order` é monotônico; sem reordenação após a criação.
- **Rejeitar encerra imediatamente**: qualquer rejeição de etapa encerra a execução (sem aprovação parcial).
- **`current_step_id = NULL`** em execuções completadas/rejeitadas — use `status` para distinguir.
- **Iniciar execução requer pelo menos uma etapa**: retorne 409 se o fluxo de trabalho não tiver etapas.
