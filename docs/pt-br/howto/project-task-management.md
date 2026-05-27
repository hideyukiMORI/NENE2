# Como Fazer: Gerenciamento de Projetos e Tarefas com Recursos Aninhados

> **Referência FT**: FT241 (`NENE2-FT/projtrack`) — API de Gerenciamento de Projetos e Tarefas

Demonstra uma API de recurso aninhado de dois níveis onde tarefas pertencem a projetos,
com validação de existência do pai, atualizações seletivas via `PATCH` usando `array_key_exists()`,
allowlist de status via restrição `CHECK`, prioridade como inteiro, e `204 No Content`
para respostas de DELETE.

---

## Rotas

| Método   | Caminho                                  | Descrição                                             |
|----------|------------------------------------------|-------------------------------------------------------|
| `GET`    | `/projects`                              | Listar projetos (paginado)                            |
| `POST`   | `/projects`                              | Criar um projeto                                      |
| `GET`    | `/projects/{id}`                         | Obter um único projeto                                |
| `DELETE` | `/projects/{id}`                         | Excluir um projeto (cascata para tarefas)             |
| `GET`    | `/projects/{projectId}/tasks`            | Listar tarefas de um projeto (paginado, filtrável)    |
| `POST`   | `/projects/{projectId}/tasks`            | Criar uma tarefa dentro de um projeto                 |
| `GET`    | `/projects/{projectId}/tasks/{taskId}`   | Obter uma única tarefa                                |
| `PATCH`  | `/projects/{projectId}/tasks/{taskId}`   | Atualizar tarefa seletivamente (campos omitidos mantidos) |
| `DELETE` | `/projects/{projectId}/tasks/{taskId}`   | Excluir uma tarefa (`204 No Content`)                 |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS projects (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL,
    description TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS tasks (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id  INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    title       TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'open' CHECK(status IN ('open', 'in_progress', 'done')),
    priority    INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);
```

`status` é restrito no nível de banco com `CHECK(status IN (...))` — uma rede de segurança
contra valores inválidos que escapem. `ON DELETE CASCADE` significa que excluir um projeto
remove automaticamente todas as suas tarefas. `priority` tem padrão `0`; valores maiores são ordenados primeiro.

---

## Recursos aninhados: validação de existência do pai

Toda operação de tarefa valida que o projeto pai existe **antes** de tocar a tarefa.
Se o ID do projeto for desconhecido, `ProjectNotFoundException` é lançada imediatamente:

```php
private function listTasks(ServerRequestInterface $request): ResponseInterface
{
    $params    = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $projectId = (int) ($params['projectId'] ?? 0);

    // Garantir que o projeto existe (lança ProjectNotFoundException → 404)
    $this->projects->findById($projectId);

    $items = $this->tasks->findByProject($projectId, $status, $pagination->limit, $pagination->offset);
    // ...
}
```

`ProjectNotFoundException` é registrada como um handler de exceção que mapeia para
`404 Not Found`. Isso significa que `/projects/99/tasks` retorna `404` quando o projeto 99
não existe — o mesmo status de uma tarefa ausente. Chamadores não conseguem distinguir "projeto
ausente" de "tarefa ausente" sem ler o campo `detail` do Problem Details.

O repositório de tarefas também impõe escopo de projeto no nível SQL:

```php
public function findByProjectAndId(int $projectId, int $taskId): Task
{
    $row = $this->executor->fetchOne(
        'SELECT * FROM tasks WHERE id = ? AND project_id = ?',
        [$taskId, $projectId],
    );
    if ($row === null) {
        throw new TaskNotFoundException($projectId, $taskId);
    }
    return $this->hydrate($row);
}
```

`WHERE id = ? AND project_id = ?` impede acesso entre projetos — a tarefa 5 do projeto
1 não pode ser obtida via `/projects/2/tasks/5`, mesmo que a tarefa 5 exista.

---

## PATCH: atualização seletiva de campos com `array_key_exists()`

`PATCH /projects/{projectId}/tasks/{taskId}` aceita qualquer subconjunto de `title`, `status`,
e `priority`. Campos ausentes do corpo da requisição são preservados.

`isset()` não consegue distinguir "chave ausente" de "chave presente com null". Para semântica
de PATCH, `array_key_exists()` é a ferramenta correta:

```php
$title    = null;
$status   = null;
$priority = null;

if (array_key_exists('title', $body)) {
    if (!is_string($body['title']) || trim($body['title']) === '') {
        $errors[] = new ValidationError('title', 'title must be a non-empty string.', 'invalid_value');
    } else {
        $title = trim($body['title']);
    }
}

if (array_key_exists('status', $body)) {
    $validStatuses = ['open', 'in_progress', 'done'];
    if (!is_string($body['status']) || !in_array($body['status'], $validStatuses, true)) {
        $errors[] = new ValidationError('status', 'status must be one of: open, in_progress, done.', 'invalid_value');
    } else {
        $status = $body['status'];
    }
}

if (array_key_exists('priority', $body)) {
    if (!is_int($body['priority'])) {
        $errors[] = new ValidationError('priority', 'priority must be an integer.', 'invalid_type');
    } else {
        $priority = $body['priority'];
    }
}
```

`$title`, `$status` e `$priority` permanecem `null` quando a chave está ausente. O
repositório interpreta `null` como "não alterar":

```php
public function update(int $projectId, int $taskId, ?string $title, ?string $status, ?int $priority, string $now): Task
{
    $existing    = $this->findByProjectAndId($projectId, $taskId);
    $newTitle    = $title    ?? $existing->title;
    $newStatus   = $status   ?? $existing->status;
    $newPriority = $priority ?? $existing->priority;

    $this->executor->execute(
        'UPDATE tasks SET title = ?, status = ?, priority = ?, updated_at = ? WHERE id = ? AND project_id = ?',
        [$newTitle, $newStatus, $newPriority, $now, $taskId, $projectId],
    );

    return $this->findByProjectAndId($projectId, $taskId);
}
```

O operador null-coalescing `??` mescla os valores fornecidos com o registro existente. Um único
`UPDATE` sempre é executado (sem lista de colunas dinâmica) — o valor existente simplesmente
substitui a si mesmo quando um campo está ausente.

---

## `is_int()` para priority: rejeitando floats e strings do JSON

JSON `1` decodifica como PHP `int`, mas `1.0` decodifica como `float` e `"1"` como `string`.
`is_int()` aceita apenas a forma de inteiro:

```php
if (isset($body['priority'])) {
    if (!is_int($body['priority'])) {
        $errors[] = new ValidationError('priority', 'priority must be an integer.', 'invalid_type');
    } else {
        $priority = $body['priority'];
    }
}
```

`is_numeric()` passaria `"1"` e `1.0` — use `is_int()` para validação estrita de inteiros. Nota:
`priority` é opcional na criação (padrão `0`); no PATCH, a mesma verificação se aplica dentro
do bloco `array_key_exists('priority', $body)`.

---

## Validação de allowlist de status

O status é validado contra uma allowlist explícita antes de chegar ao banco:

```php
$validStatuses = ['open', 'in_progress', 'done'];
if (!is_string($body['status']) || !in_array($body['status'], $validStatuses, true)) {
    $errors[] = new ValidationError('status', 'status must be one of: open, in_progress, done.', 'invalid_value');
}
```

`in_array(..., true)` — comparação estrita — garante que o valor é uma string igual a um
dos estados permitidos. A restrição `CHECK` do banco fornece uma segunda camada de defesa,
mas a verificação no nível de aplicação fornece um `422` estruturado com uma mensagem de erro
significativa em vez de um erro bruto do banco.

---

## Filtro de status para listagem de tarefas

`GET /projects/{projectId}/tasks?status=open` filtra tarefas por status. O query string
é lido com `QueryStringParser::string()`:

```php
$status = QueryStringParser::string($request, 'status');

$validStatuses = ['open', 'in_progress', 'done'];
if ($status !== null && !in_array($status, $validStatuses, true)) {
    throw new ValidationException([
        new ValidationError('status', 'status must be one of: open, in_progress, done.', 'invalid_value'),
    ]);
}
```

`QueryStringParser::string()` retorna `null` quando o parâmetro está ausente — nenhum filtro
aplicado. Um valor inválido retorna `422 Unprocessable Entity` em vez de retornar silenciosamente
uma lista vazia.

O repositório constrói a cláusula WHERE dinamicamente:

```php
public function findByProject(int $projectId, ?string $status = null, int $limit = 20, int $offset = 0): array
{
    $where  = ['project_id = ?'];
    $params = [$projectId];

    if ($status !== null) {
        $where[]  = 'status = ?';
        $params[] = $status;
    }

    $sql = 'SELECT * FROM tasks WHERE ' . implode(' AND ', $where)
        . ' ORDER BY priority DESC, created_at ASC LIMIT ? OFFSET ?';
    $params[] = $limit;
    $params[] = $offset;

    return array_map($this->hydrate(...), $this->executor->fetchAll($sql, $params));
}
```

As tarefas são ordenadas por `priority DESC` (prioridade mais alta primeiro), depois por `created_at ASC`
(tarefas mais antigas primeiro dentro da mesma prioridade).

---

## `204 No Content` para DELETE

As respostas DELETE não carregam corpo. `JsonResponseFactory::createEmpty(204)` produz uma
resposta `204 No Content`:

```php
private function deleteTask(ServerRequestInterface $request): ResponseInterface
{
    $params    = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $projectId = (int) ($params['projectId'] ?? 0);
    $taskId    = (int) ($params['taskId'] ?? 0);

    $this->projects->findById($projectId);
    $this->tasks->delete($projectId, $taskId);

    return $this->json->createEmpty(204);
}
```

O repositório de tarefas valida a existência antes de excluir:

```php
public function delete(int $projectId, int $taskId): void
{
    $this->findByProjectAndId($projectId, $taskId);  // lança TaskNotFoundException se não encontrado
    $this->executor->execute('DELETE FROM tasks WHERE id = ? AND project_id = ?', [$taskId, $projectId]);
}
```

Se a tarefa não existe (ou pertence a um projeto diferente), `TaskNotFoundException`
é lançada → `404 Not Found` antes que qualquer DELETE seja executado.

---

## Recursos integrados do NENE2 utilizados

| Recurso integrado | Finalidade |
|---|---|
| `PaginationQueryParser::parse()` | Lê `?limit=` e `?offset=` com padrões seguros |
| `PaginationResponse` | Produz envelope `{ items, total, limit, offset }` |
| `ValidationException` / `ValidationError` | `422` estruturado com array `errors` |
| `QueryStringParser::string()` | Lê um parâmetro de query string nomeado, retorna `null` se ausente |
| `JsonRequestBodyParser::parse()` | Decodifica corpo JSON |
| `JsonResponseFactory::create()` | Codifica resposta JSON |
| `JsonResponseFactory::createEmpty()` | Produz resposta sem corpo (ex.: `204`) |
| `Router::PARAMETERS_ATTRIBUTE` | Recupera parâmetros de caminho da requisição |

---

## Howtos relacionados

- [`note-management-ownership.md`](note-management-ownership.md) — prevenção de IDOR com `WHERE id = ? AND owner_id = ?`
- [`contact-management.md`](contact-management.md) — associações many-to-many, filtragem de busca
- [`document-versioning.md`](document-versioning.md) — versionamento append-only com flag `is_current`
- [`scheduled-reminders.md`](scheduled-reminders.md) — padrão de validação de header `V::userId()`
