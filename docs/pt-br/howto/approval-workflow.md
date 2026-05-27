# Como Fazer: API de Fluxo de Aprovação

> **Referência FT**: FT68 (`NENE2-FT/approvallog`) — API de Fluxo de Aprovação

Demonstra um fluxo de aprovação em várias etapas onde uma solicitação avança por estados definidos
(Draft → Submitted → UnderReview → Approved/Rejected). Transições inválidas
retornam 409 Conflict. A máquina de estados é codificada diretamente na enum com suporte `ApprovalStatus`
usando um método `allowedTransitions()`.

---

## Estados do fluxo

```
Draft ──submit──▶ Submitted ──review──▶ UnderReview
                                              │
                                    ┌─approve─┤─reject─┐
                                    ▼                   ▼
                                 Approved            Rejected
                                                        │
                                                    ─rework─▶ Draft
```

| Estado | Descrição |
|--------|-----------|
| `draft` | Criado mas ainda não enviado |
| `submitted` | Aguardando atribuição de revisor |
| `under_review` | Revisor atribuído e revisando |
| `approved` | Aprovação final concedida |
| `rejected` | Rejeitado com um motivo obrigatório |

Uma solicitação rejeitada pode ser retrabalhada (retornada para `draft`) para revisão e reenvio.
Uma solicitação aprovada não tem transições adicionais.

---

## Regras de transição codificadas na enum

As regras de transição de estado ficam dentro da enum — não no repositório ou controller:

```php
enum ApprovalStatus: string
{
    case Draft       = 'draft';
    case Submitted   = 'submitted';
    case UnderReview = 'under_review';
    case Approved    = 'approved';
    case Rejected    = 'rejected';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft       => [self::Submitted],
            self::Submitted   => [self::UnderReview],
            self::UnderReview => [self::Approved, self::Rejected],
            self::Approved    => [],
            self::Rejected    => [self::Draft],   // caminho de retrabalho
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
```

`canTransitionTo()` é a única fonte de verdade para determinar se uma transição é válida.
Adicionar uma nova transição permitida significa atualizar apenas este método.

---

## Rotas

| Método | Caminho                          | Descrição                              |
|--------|----------------------------------|----------------------------------------|
| `POST` | `/requests`                      | Criar uma solicitação em rascunho      |
| `GET`  | `/requests`                      | Listar todas as solicitações (filtro `?status=`) |
| `GET`  | `/requests/{id}`                 | Obter uma solicitação única            |
| `POST` | `/requests/{id}/submit`          | Draft → Submitted                      |
| `POST` | `/requests/{id}/review`          | Submitted → UnderReview (atribui revisor) |
| `POST` | `/requests/{id}/approve`         | UnderReview → Approved                 |
| `POST` | `/requests/{id}/reject`          | UnderReview → Rejected (motivo obrigatório) |
| `POST` | `/requests/{id}/rework`          | Rejected → Draft (limpa revisor/nota)  |

---

## Protegendo transições no repositório

O repositório verifica `canTransitionTo()` antes de executar a consulta UPDATE:

```php
public function submit(int $id, string $now): ?ApprovalRequest
{
    $req = $this->findById($id);

    if ($req === null || !$req->status->canTransitionTo(ApprovalStatus::Submitted)) {
        return null;   // o chamador mapeia null → 409 Conflict
    }

    $this->db->execute(
        "UPDATE requests SET status = 'submitted', submitted_at = ?, updated_at = ? WHERE id = ?",
        [$now, $now, $id],
    );

    return $this->findById($id);
}
```

Retornar `null` tanto para "não encontrado" quanto para "transição inválida" é uma simplificação deliberada.
Em produção, distinga entre 404 (não encontrado) e 409 (encontrado mas transição inválida)
retornando um resultado tipado ou lançando exceções de domínio.

O controller mapeia `null → 409 Conflict`:

```php
private function submit(ServerRequestInterface $request): ResponseInterface
{
    $id  = (int) ($params['id'] ?? 0);
    $req = $this->repo->submit($id, $now);

    if ($req === null) {
        return $this->problems->create(
            $request,
            'conflict',
            'Request not found or cannot be submitted from its current status.',
            409,
            '',
        );
    }

    return $this->json->create($req->toArray());
}
```

---

## Rejeição exige um motivo

A transição `reject` exige tanto `reviewer` quanto `note`:

```php
private function reject(ServerRequestInterface $request): ResponseInterface
{
    $reviewer = isset($body['reviewer']) && is_string($body['reviewer']) ? trim($body['reviewer']) : '';
    $note     = isset($body['note']) && is_string($body['note']) ? trim($body['note']) : '';

    if ($reviewer === '' || $note === '') {
        $errors = [];
        if ($reviewer === '') {
            $errors[] = ['field' => 'reviewer', 'code' => 'required', 'message' => 'reviewer is required.'];
        }
        if ($note === '') {
            $errors[] = ['field' => 'note', 'code' => 'required', 'message' => 'note (rejection reason) is required.'];
        }

        return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, compact('errors'));
    }
    // ...
}
```

Rejeitar sem motivo é rejeitado (422). Aprovar sem nota é permitido — o campo `note`
é opcional para aprovações.

---

## Retrabalho: limpando o estado de revisão

Quando uma solicitação rejeitada é retrabalhada, o revisor e a nota de revisão são limpos para que
o próximo revisor comece do zero:

```php
// Repositório: rework (Rejected → Draft)
$this->db->execute(
    "UPDATE requests SET status = 'draft', reviewer = NULL, review_note = NULL, reviewed_at = NULL, updated_at = ? WHERE id = ?",
    [$now, $id],
);
```

O timestamp `submitted_at` é preservado — registra quando a solicitação foi enviada pela primeira vez,
não o ciclo atual.

---

## Schema

```sql
CREATE TABLE requests (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    title        TEXT    NOT NULL,
    submitter    TEXT    NOT NULL,
    status       TEXT    NOT NULL DEFAULT 'draft',
    reviewer     TEXT,              -- NULL até o início da revisão
    review_note  TEXT,             -- NULL até ser revisado
    submitted_at TEXT,             -- NULL até ser enviado
    reviewed_at  TEXT,             -- NULL até ser aprovado/rejeitado
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);
```

As colunas anuláveis (`reviewer`, `review_note`, `submitted_at`, `reviewed_at`) são limpas
para `NULL` no retrabalho, mantendo o schema limpo sem adicionar uma coluna `rework_count`.

> **Melhoria**: adicione um `CHECK(status IN ('draft','submitted','under_review','approved','rejected'))`
> como proteção no nível do banco de dados para corresponder aos valores da enum.

---

## Filtro de status no endpoint de listagem

```php
private function list(ServerRequestInterface $request): ResponseInterface
{
    $params    = $request->getQueryParams();
    $statusRaw = isset($params['status']) && is_string($params['status']) ? $params['status'] : null;
    $status    = $statusRaw !== null ? ApprovalStatus::tryFrom($statusRaw) : null;

    if ($statusRaw !== null && $status === null) {
        return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
            'errors' => [['field' => 'status', 'code' => 'invalid_value', 'message' => 'Invalid status value.']],
        ]);
    }

    $requests = $this->repo->listByStatus($status);
    // ...
}
```

`ApprovalStatus::tryFrom()` retorna `null` para strings de status desconhecidas → 422. Quando
`$statusRaw === null` (sem filtro), todas as solicitações são retornadas.

---

## Adicionando uma nova transição

Para adicionar um estado `cancelled` que pode ser alcançado a partir de qualquer estado não terminal:

1. Adicione `case Cancelled = 'cancelled';` a `ApprovalStatus`.
2. Atualize `allowedTransitions()` para `Draft`, `Submitted` e `UnderReview` para
   incluir `self::Cancelled`.
3. Adicione a rota `POST /requests/{id}/cancel` e o handler.
4. Escreva o UPDATE do banco de dados no repositório.
5. Atualize a restrição `CHECK` do schema (se adicionada).

A enum é a única fonte de verdade — nenhum outro arquivo precisa ser alterado para adicionar a
guarda de transição.

---

## Howtos relacionados

- [`content-draft-lifecycle.md`](content-draft-lifecycle.md) — ciclo de vida draft → publish (máquina de estados mais simples)
- [`media-watchlist.md`](media-watchlist.md) — validação de enum com suporte usando `tryFrom()`
- [`add-custom-route.md`](add-custom-route.md) — padrão de endpoint de ação POST
- [`multi-step-workflow.md`](multi-step-workflow.md) — padrões genéricos de fluxo de trabalho em várias etapas
