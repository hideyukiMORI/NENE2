# Como Fazer: Fluxo de Aprovação de Conteúdo

> **Referência FT**: FT248 (`NENE2-FT/flowlog`) — API de Fluxo de Aprovação de Conteúdo
> **ATK**: FT248 — teste de ataque com mentalidade de cracker (ATK-01 a ATK-12)

Demonstra um ciclo de vida de publicação de posts onde uma `BackedEnum` `PostStatus` controla
o grafo de transições via `canTransitionTo()`, transições inválidas lançam
`InvalidTransitionException → 409`, e a rejeição carrega um motivo opcional. Inclui uma
avaliação completa de ataque com mentalidade de cracker.

---

## Rotas

| Método | Caminho                    | Descrição                                                |
|--------|----------------------------|----------------------------------------------------------|
| `POST` | `/posts`                   | Criar um post (sempre começa como `draft`)               |
| `GET`  | `/posts`                   | Listar posts (paginado, filtrável por status)            |
| `GET`  | `/posts/{id}`              | Obter um post                                            |
| `POST` | `/posts/{id}/submit`       | Transição: `draft → submitted`                           |
| `POST` | `/posts/{id}/approve`      | Transição: `submitted → approved`                        |
| `POST` | `/posts/{id}/reject`       | Transição: `submitted → rejected` (motivo opcional)      |

> **Rotas de ação estáticas antes das parametrizadas**: `/posts/{id}/submit`, `/approve`,
> `/reject` são registradas antes de `/posts/{id}` para que sub-caminhos literais não sejam
> capturados pelo segmento parametrizado.

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS posts (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    title         TEXT    NOT NULL,
    body          TEXT    NOT NULL DEFAULT '',
    author        TEXT    NOT NULL,
    status        TEXT    NOT NULL DEFAULT 'draft'
                           CHECK(status IN ('draft', 'submitted', 'approved', 'rejected')),
    reject_reason TEXT,
    created_at    TEXT    NOT NULL,
    updated_at    TEXT    NOT NULL
);
```

`status` tem um `CHECK` constraint no nível do BD como rede de segurança; a aplicação valida
via `PostStatus::canTransitionTo()` antes de qualquer escrita. `reject_reason` é nullable —
definido apenas na rejeição.

---

## `PostStatus` BackedEnum com `canTransitionTo()`

O grafo de transições de estado pertence ao próprio enum:

```php
enum PostStatus: string
{
    case Draft     = 'draft';
    case Submitted = 'submitted';
    case Approved  = 'approved';
    case Rejected  = 'rejected';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft     => $target === self::Submitted,
            self::Submitted => $target === self::Approved || $target === self::Rejected,
            self::Approved,
            self::Rejected  => false,  // estados terminais
        };
    }
}
```

O grafo de transições:
```
draft → submitted → approved (terminal)
                 → rejected  (terminal)
```

`Approved` e `Rejected` são estados terminais — nenhuma transição adicional é permitida.
Tentar aprovar um post já aprovado lança `InvalidTransitionException`.

---

## Método de transição no repositório

```php
public function transition(int $id, PostStatus $targetStatus, string $now, ?string $rejectReason = null): Post
{
    $post = $this->findById($id);

    if (!$post->status->canTransitionTo($targetStatus)) {
        throw new InvalidTransitionException($post->status, $targetStatus);
    }

    $this->executor->execute(
        'UPDATE posts SET status = ?, reject_reason = ?, updated_at = ? WHERE id = ?',
        [$targetStatus->value, $rejectReason, $now, $id],
    );

    return new Post($id, $post->title, $post->body, $post->author, $targetStatus, $rejectReason, $post->createdAt, $now);
}
```

O método `transition()` é compartilhado por submit, approve e reject — cada handler
o chama com um `$targetStatus` diferente. `reject_reason` é `null` para approve/submit,
e opcionalmente fornecido para reject.

---

## Filtro de status com `PostStatus::tryFrom()`

```php
$statusStr = QueryStringParser::string($request, 'status');

if ($statusStr !== null) {
    $status = PostStatus::tryFrom($statusStr);
    if ($status === null) {
        throw new ValidationException([
            new ValidationError('status', "Invalid status '{$statusStr}'. Valid values: draft, submitted, approved, rejected.", 'invalid'),
        ]);
    }
    $items = $this->repository->findByStatus($status, $pagination->limit, $pagination->offset);
}
```

`BackedEnum::tryFrom()` retorna `null` para valores de string desconhecidos em vez de lançar.
A verificação explícita de `null` produz um `422` estruturado com uma mensagem de erro legível
listando os valores válidos.

---

## Rejeição com motivo opcional

`POST /posts/{id}/reject` aceita um campo `reason` opcional:

```php
$raw    = (string) $request->getBody();
$reason = null;

if ($raw !== '') {
    $body   = JsonRequestBodyParser::parse($request);
    $raw    = isset($body['reason']) && is_string($body['reason']) ? trim($body['reason']) : '';
    $reason = $raw !== '' ? $raw : null;
}
```

Um corpo vazio `{}` ou um campo `reason` ausente resultam em `null`. Uma string de motivo
contendo apenas espaços também é normalizada para `null` via `trim()`. O motivo é armazenado
na coluna nullable `reject_reason`.

---

## ATK — Teste de ataque com mentalidade de cracker (FT248)

### ATK-01 — Sem autenticação: qualquer pessoa pode aprovar ou rejeitar qualquer post

**Ataque**: Aprovar ou rejeitar um post sem nenhuma credencial.

```bash
curl -X POST http://localhost:8200/posts/1/approve
curl -X POST http://localhost:8200/posts/1/reject
```

**Observado**: Ambos têm sucesso com `200 OK`. Qualquer chamador pode empurrar qualquer post
por qualquer transição permitida.

**Veredicto**: **EXPOSED** — adicione autenticação e autorização baseada em papéis. Apenas
revisores designados devem poder aprovar/rejeitar. O envio deve exigir que o autor do post
esteja autenticado.

---

### ATK-02 — Transição de estado inválida: aprovar um rascunho

**Ataque**: Tentar aprovar um post que ainda está no status `draft`.

```bash
curl -X POST http://localhost:8200/posts/1/approve
# post 1 está em draft
```

**Observado**: `canTransitionTo(Approved)` retorna `false` para `Draft` → `InvalidTransitionException`
→ `409 Conflict` com contexto from/to na resposta.

**Veredicto**: **BLOCKED** — o grafo de transições de propriedade do enum previne saltos ilegais de estado.

---

### ATK-03 — Aprovação dupla: aprovar um post já aprovado

**Ataque**: Aprovar um post uma segunda vez.

```bash
curl -X POST http://localhost:8200/posts/1/submit
curl -X POST http://localhost:8200/posts/1/approve
curl -X POST http://localhost:8200/posts/1/approve  # segunda aprovação
```

**Observado**: Terceira requisição: `canTransitionTo(Approved)` de `Approved` → `false`
→ `409 Conflict`. O post permanece no estado `Approved`.

**Veredicto**: **BLOCKED** — `Approved` é um estado terminal; o enum retorna explicitamente
`false` para todas as transições de estados terminais.

---

### ATK-04 — SQL injection via título ou corpo

**Ataque**: Incorporar metacaracteres SQL.

```json
{"title": "'; DROP TABLE posts; --", "author": "x"}
```

**Observado**: Os valores são vinculados via placeholders `?` parametrizados. O payload de
injeção é armazenado como texto literal.

**Veredicto**: **BLOCKED** — consultas parametrizadas previnem injeção SQL.

---

### ATK-05 — Valor de filtro de status inválido

**Ataque**: Passar um status desconhecido para o endpoint de listagem.

```
GET /posts?status=hacked
GET /posts?status=published
```

**Observado**: `PostStatus::tryFrom('hacked')` retorna `null` → `ValidationException`
→ `422 Unprocessable Entity` com a lista de statuses válidos.

**Veredicto**: **BLOCKED** — `BackedEnum::tryFrom()` + verificação explícita de null rejeita
valores de status desconhecidos.

---

### ATK-06 — Personificação de autor

**Ataque**: Criar um post afirmando ser um autor privilegiado.

```json
{"title": "Official announcement", "author": "admin"}
```

**Observado**: `201 Created` — o campo `author` é retirado literalmente do corpo da requisição
sem verificação. Qualquer string é aceita.

**Veredicto**: **EXPOSED** — `author` é fornecido pelo usuário sem vinculação criptográfica.
Em produção, derive `author` da sessão/token autenticado, nunca do corpo da requisição.

---

### ATK-07 — Mass assignment: injetar `status` na criação

**Ataque**: Definir `status` como `approved` diretamente durante a criação.

```json
{"title": "Instant publish", "author": "x", "status": "approved"}
```

**Observado**: `createPost()` ignora qualquer campo `status` no corpo — sempre insere
`PostStatus::Draft->value`. A chave extra é descartada silenciosamente.

**Veredicto**: **BLOCKED** — o controller constrói o INSERT com um valor hardcoded
`PostStatus::Draft->value`; nenhum campo do corpo pode substituí-lo.

---

### ATK-08 — Payload XSS em título, corpo ou autor

**Ataque**: Armazenar uma tag script.

```json
{"title": "<script>alert(1)</script>", "author": "x"}
```

**Observado**: O conteúdo é armazenado como está e retornado verbatim em JSON. A API não
faz encoding HTML na saída.

**Veredicto**: **ACEITO POR DESIGN** — APIs JSON retornam conteúdo bruto. A camada de
renderização deve sanitizar antes de inserir em HTML.

---

### ATK-09 — ID de post não numérico

**Ataque**: Usar uma string ou float como `{id}`.

```
POST /posts/abc/approve
POST /posts/1.5/approve
```

**Observado**: `(int) 'abc'` = `0`, `(int) '1.5'` = `1`.
- `abc` → `findById(0)` → sem linha → `PostNotFoundException` → `404 Not Found`.
- `1.5` → `findById(1)` → se o post 1 existir, sua transição é disparada.

**Veredicto**: **PARCIALMENTE BLOCKED** — strings não numéricas mapeiam para 404. Strings
float são truncadas silenciosamente. Adicione `ctype_digit()` para validação estrita de ID.

---

### ATK-10 — Título vazio ou autor vazio

**Ataque**: Enviar com campos em branco.

```json
{"title": "", "author": "x"}
{"title": "y", "author": ""}
{"title": "   ", "author": "   "}
```

**Observado**: Verificações de `trim($body['title']) === ''` e `trim($body['author']) === ''`
são disparadas → `ValidationException` → `422`.

**Veredicto**: **BLOCKED** — trim + verificações de string vazia cobrem tanto valores
vazios quanto valores com apenas espaços.

---

### ATK-11 — Rejeitar sem fornecer motivo

**Ataque**: Rejeitar com corpo vazio ou sem campo `reason`.

```bash
curl -X POST http://localhost:8200/posts/1/reject
curl -X POST http://localhost:8200/posts/1/reject -d '{}'
curl -X POST http://localhost:8200/posts/1/reject -d '{"reason": ""}'
```

**Observado**: Todos os três casos produzem `null` para `reject_reason`. A rejeição sem
motivo é aceita — a coluna é nullable.

**Veredicto**: **ACEITO POR DESIGN** — `reject_reason` é opcional. Para fluxos de produção
que exijam motivo de rejeição obrigatório, adicione `if ($reason === null) → 422`.

---

### ATK-12 — Rejeitar um post já rejeitado (rejeição dupla)

**Ataque**: Tentar rejeitar um post que já está rejeitado.

```bash
curl -X POST http://localhost:8200/posts/1/submit
curl -X POST http://localhost:8200/posts/1/reject
curl -X POST http://localhost:8200/posts/1/reject  # segunda rejeição
```

**Observado**: `canTransitionTo(Rejected)` de `Rejected` → `false` → `409 Conflict`.

**Veredicto**: **BLOCKED** — `Rejected` é um estado terminal; o enum retorna explicitamente
`false` para todas as transições de estados terminais.

---

## Resumo ATK

| # | Vetor de ataque | Veredicto |
|---|-----------------|-----------|
| ATK-01 | Sem autenticação em approve/reject | EXPOSED |
| ATK-02 | Transição inválida (aprovar rascunho) | BLOCKED |
| ATK-03 | Aprovação dupla | BLOCKED |
| ATK-04 | SQL injection via título/corpo | BLOCKED |
| ATK-05 | Valor de filtro de status inválido | BLOCKED |
| ATK-06 | Personificação de autor | EXPOSED |
| ATK-07 | Mass assignment de status na criação | BLOCKED |
| ATK-08 | Payload XSS no conteúdo | ACEITO POR DESIGN |
| ATK-09 | ID de post não numérico | PARCIALMENTE BLOCKED |
| ATK-10 | Título vazio ou autor vazio | BLOCKED |
| ATK-11 | Rejeitar sem motivo (opcional) | ACEITO POR DESIGN |
| ATK-12 | Rejeição dupla | BLOCKED |

**Vulnerabilidades reais a corrigir antes da produção**:
1. **ATK-01** — Adicionar autenticação e autorização baseada em papéis (papel de revisor para approve/reject)
2. **ATK-06** — Derivar `author` da identidade verificada, nunca do corpo da requisição
3. **ATK-09** — Adicionar guarda `ctype_digit()` para parâmetros de caminho de ID

---

## Howtos relacionados

- [`state-machine-audit-log.md`](state-machine-audit-log.md) — transição de estado com histórico de auditoria e InvalidTransitionException
- [`approval-workflow.md`](approval-workflow.md) — solicitação de aprovação com múltiplos aprovadores
- [`step-workflow-approval.md`](step-workflow-approval.md) — fluxo de trabalho multi-etapas com etapas ordenadas
- [`content-draft-lifecycle.md`](content-draft-lifecycle.md) — padrões de ciclo de vida draft/publish
