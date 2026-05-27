# Como Fazer: API de Atualização de Status em Lote

> **Referência FT**: FT85 (`NENE2-FT/bulkupdatelog`) — API de Atualização de Status em Lote
> **VULN**: FT231 — avaliação de segurança / vulnerabilidades (V-01 a V-10)

Demonstra dois padrões para mutação de status em lote: atualizações por item (cada item recebe seu
próprio status alvo) e atualização em lote homogênea (todos os itens recebem o mesmo status). Ambos
suportam sucesso parcial — a resposta reporta quais IDs tiveram sucesso e quais falharam.

---

## Rotas

| Método  | Caminho           | Descrição                                              |
|---------|-------------------|--------------------------------------------------------|
| `POST`  | `/tasks`          | Criar uma tarefa                                       |
| `GET`   | `/tasks`          | Listar todas as tarefas                                |
| `PATCH` | `/tasks/status`   | Atualização de status em lote por item (status alvos mistos) |
| `PATCH` | `/tasks/done`     | Marcar um conjunto de IDs como concluídos (status alvo único) |

---

## Atualização em lote por item (`PATCH /tasks/status`)

Cada item de atualização especifica seu próprio status alvo:

```json
{
  "updates": [
    {"id": 1, "status": "done"},
    {"id": 2, "status": "cancelled"},
    {"id": 3, "status": "in_progress"}
  ]
}
```

O repositório processa cada item individualmente, acumulando sucessos e falhas:

```php
public function bulkUpdateStatus(array $items, string $now): BulkUpdateResult
{
    $updatedIds = [];
    $failed     = [];

    foreach ($items as $item) {
        $itemArr = is_array($item) ? $item : [];
        $id      = isset($itemArr['id']) && is_int($itemArr['id']) ? $itemArr['id'] : null;
        $status  = isset($itemArr['status']) && is_string($itemArr['status'])
            ? TaskStatus::tryFrom($itemArr['status'])
            : null;

        if ($id === null) {
            $failed[] = ['id' => 0, 'error' => 'id must be an integer'];
            continue;
        }

        if ($status === null) {
            $failed[] = ['id' => $id, 'error' => 'invalid status value'];
            continue;
        }

        $affected = $this->executor->execute(
            'UPDATE tasks SET status = ?, updated_at = ? WHERE id = ?',
            [$status->value, $now, $id],
        );

        if ($affected === 0) {
            $failed[] = ['id' => $id, 'error' => 'task not found'];
        } else {
            $updatedIds[] = $id;
        }
    }

    return new BulkUpdateResult($updatedIds, $failed);
}
```

### Estrutura de resposta

```json
{
  "updated": [1, 3],
  "failed": [
    {"id": 2, "error": "task not found"}
  ]
}
```

O status HTTP é sempre `200 OK` — mesmo quando todos os itens falham. O chamador deve inspecionar
`failed` para detectar erros por item.

---

## Atualização em lote homogênea (`PATCH /tasks/done`)

Todos os IDs movem para o mesmo status alvo em um único `UPDATE ... WHERE id IN (?)`:

```php
// Corpo: {"ids": [1, 2, 3]}
$ids = isset($body['ids']) && is_array($body['ids'])
    ? array_values(array_filter($body['ids'], static fn (mixed $v): bool => is_int($v)))
    : [];

if ($ids === []) {
    return $this->json->create(['error' => 'ids array is required and must not be empty'], 422);
}
```

Valores não inteiros são silenciosamente filtrados via `array_filter(..., is_int(...))`. Após
a filtragem, se o resultado estiver vazio, um 422 é retornado.

```php
public function bulkSetStatus(array $ids, TaskStatus $status, string $now): array
{
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $this->executor->execute(
        "UPDATE tasks SET status = ?, updated_at = ? WHERE id IN ({$placeholders})",
        [$status->value, $now, ...$ids],
    );

    // Retornar IDs que existem e agora têm o status alvo
    $rows = $this->executor->fetchAll(
        "SELECT id FROM tasks WHERE id IN ({$placeholders}) AND status = ?",
        [...$ids, $status->value],
    );

    return array_map(static fn (array $r): int => (int) $r['id'], $rows);
}
```

`implode(',', array_fill(0, count($ids), '?'))` gera o número correto de placeholders `?`
— seguro, parametrizado.

---

## Allowlist de status (enum com suporte)

`TaskStatus` é uma enum de string com suporte com quatro cases:

```php
enum TaskStatus: string
{
    case Pending    = 'pending';
    case InProgress = 'in_progress';
    case Done       = 'done';
    case Cancelled  = 'cancelled';
}
```

`TaskStatus::tryFrom($string)` retorna `null` para valores de status desconhecidos, que o
handler de lote mapeia para uma falha por item. O schema adiciona `CHECK(status IN (...))` como
proteção no nível do banco de dados.

---

## Schema

```sql
CREATE TABLE tasks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    status     TEXT NOT NULL DEFAULT 'pending'
                             CHECK(status IN ('pending', 'in_progress', 'done', 'cancelled')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
```

---

## VULN — Avaliação de segurança (FT231)

### V-01 — Sem autenticação em nenhum endpoint

**Ataque**: Cancelar todas as tarefas em lote sem credenciais.

```json
{"updates": [{"id": 1, "status": "cancelled"}, {"id": 2, "status": "cancelled"}]}
```

**Observado**: `200 OK` — nenhum token exigido.

**Veredicto**: **EXPOSED** (por design para demo FT85). Adicione autenticação e autorização
em produção. Restrinja mutações em lote ao proprietário da tarefa ou a uma função admin.

---

### V-02 — DoS de atualização em massa (array enorme)

**Ataque**: Enviar um array `updates` com milhares de itens para esgotar CPU ou memória.

```python
{"updates": [{"id": i, "status": "done"} for i in range(100_000)]}
```

**Observado**: Processado em loop — cada item executa uma consulta `UPDATE`. Para 100.000
itens, isso executa 100.000 declarações SQL individuais em um loop sem limite de tamanho de lote.

**Veredicto**: **EXPOSED** — adicione um limite máximo de tamanho de lote:
```php
$maxBatchSize = 500;
if (count($updates) > $maxBatchSize) {
    return $this->json->create(['error' => "Batch size must not exceed {$maxBatchSize} items."], 422);
}
```

---

### V-03 — SQL injection via cláusula `IN`

**Ataque**: Tentar injetar SQL através do array `ids` usado em `IN (?)`.

```json
{"ids": ["1; DROP TABLE tasks; --", 1, 2]}
```

**Observado**: A string `"1; DROP TABLE tasks; --"` é rejeitada pelo filtro `is_int()` em
`array_filter()`. Apenas inteiros chegam à cláusula `IN`. O padrão `implode` + `array_fill`
gera o número correto de placeholders `?` — sem concatenação de string de dados do usuário.

**Veredicto**: **BLOCKED** — filtro `is_int()` + cláusula `IN` parametrizada previne injeção.

---

### V-04 — IDs não inteiros em atualizações por item

**Ataque**: Enviar valores `id` não inteiros no array `updates`.

```json
{"updates": [{"id": "1", "status": "done"}, {"id": null, "status": "done"}]}
```

**Observado**: Ambos os itens são adicionados a `$failed` com `'error' => 'id must be an integer'`.
`is_int()` rejeita strings e `null`.

**Veredicto**: **BLOCKED** — verificação de tipo `is_int()` estrita por item.

---

### V-05 — Valor de status inválido

**Ataque**: Enviar uma string de status desconhecida no array `updates`.

```json
{"updates": [{"id": 1, "status": "hacked"}]}
```

**Observado**: Item adicionado a `$failed` com `'error' => 'invalid status value'`. 
`TaskStatus::tryFrom("hacked")` retorna `null`.

**Veredicto**: **BLOCKED** — enum com suporte `tryFrom()` rejeita valores desconhecidos.

---

### V-06 — Array vazio

**Ataque**: Enviar um array `updates` ou `ids` vazio.

```json
{"updates": []}
{"ids": []}
```

**Observado**: Ambos retornam `422 Unprocessable Entity` com uma mensagem de erro.

**Veredicto**: **BLOCKED** — verificação de array vazio antes do processamento.

---

### V-07 — IDs duplicados no mesmo lote

**Ataque**: Incluir o mesmo `id` múltiplas vezes em uma requisição.

```json
{"updates": [{"id": 1, "status": "done"}, {"id": 1, "status": "cancelled"}]}
```

**Observado**: Ambas as atualizações têm sucesso. O segundo UPDATE sobrescreve o primeiro — a tarefa
termina como `cancelled`. Nenhuma deduplicação ocorre.

**Veredicto**: **ACEITO POR DESIGN** — semântica de última escrita vence é consistente para
gerenciamento simples de tarefas. Se conflitos devem ser rejeitados, desduplique `ids` antes
do processamento e retorne um erro em duplicatas.

---

### V-08 — IDs negativos e zero

**Ataque**: Enviar IDs `0` ou `-1`.

```json
{"ids": [0, -1]}
```

**Observado**: `is_int(0)` = true, `is_int(-1)` = true — ambos passam pelo filtro.
O UPDATE é executado com `WHERE id IN (0, -1)`, que não corresponde a nenhuma linha. Resposta:
`{"requested": 2, "updated": 0, "ids": []}`.

**Veredicto**: **BLOCKED** na prática (nenhuma linha afetada). Nenhum erro é retornado para
IDs inexistentes — isso é consistente com o padrão de sucesso parcial. Adicione uma guarda
de inteiro positivo se IDs negativos devem ser rejeitados com 422.

---

### V-09 — Atualização em lote ignora silenciosamente tarefas inexistentes

**Ataque**: Incluir IDs que não existem no banco de dados.

```json
{"ids": [99999, 100000]}
```

**Observado**: `{"requested": 2, "updated": 0, "ids": []}` — sem erro, sem indicação
de que as tarefas não existem.

**Veredicto**: **ACEITO POR DESIGN** — modelo de sucesso parcial. Documente esse comportamento
na especificação da API. Se os chamadores precisam distinguir "tarefa inexistente" de "tarefa já no
estado alvo", a resposta pode incluir uma lista `not_found`.

---

### V-10 — Atualizações em lote concorrentes nos mesmos IDs

**Ataque**: Enviar duas requisições `PATCH /tasks/done` simultâneas para o mesmo conjunto de IDs.

**Observado**: Ambas as declarações UPDATE são executadas no banco de dados. O bloqueio de linha do SQLite
significa que um UPDATE é concluído primeiro, depois o segundo UPDATE é executado nas linhas já `done`.
Ambas as respostas retornam IDs `updated` (já que as linhas ainda existem com `status = done`).

**Veredicto**: **BLOCKED** — escritas idempotentes. Ambas as requisições produzem o mesmo resultado
(todos os IDs definidos como `done`). Para atualizações de `status` onde o status alvo difere por
chamador, escritas concorrentes usam última escrita vence.

---

## Resumo VULN

| # | Vetor de ataque | Veredicto |
|---|-----------------|-----------|
| V-01 | Sem autenticação | EXPOSED (por design) |
| V-02 | DoS de atualização em massa (array enorme) | EXPOSED |
| V-03 | SQL injection via cláusula `IN` | BLOCKED |
| V-04 | IDs não inteiros | BLOCKED |
| V-05 | Valor de status inválido | BLOCKED |
| V-06 | Array vazio | BLOCKED |
| V-07 | IDs duplicados no lote | ACEITO POR DESIGN |
| V-08 | IDs negativos/zero | BLOCKED |
| V-09 | Tarefas inexistentes ignoradas silenciosamente | ACEITO POR DESIGN |
| V-10 | Atualizações em lote concorrentes | BLOCKED |

**Vulnerabilidades reais para corrigir antes da produção**:
1. **V-01** — Adicionar autenticação e autorização
2. **V-02** — Adicionar limite máximo de tamanho de lote (ex.: 500 itens)

---

## Howtos relacionados

- [`implement-bulk-endpoint.md`](implement-bulk-endpoint.md) — criação em lote com erros por item
- [`batch-api-partial-success.md`](batch-api-partial-success.md) — padrões de sucesso parcial
- [`approval-workflow.md`](approval-workflow.md) — transições de status com guarda de enum
