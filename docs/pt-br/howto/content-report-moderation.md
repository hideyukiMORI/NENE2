# Denúncia e Moderação de Conteúdo

Guia de implementação de sistema de denúncia e moderação de conteúdo (artigos).
Explica RBAC (controle de acesso baseado em papéis), prevenção de IDOR, denúncia idempotente e transição de status unidirecional.

## Visão Geral

- Usuário denuncia artigos (idempotente: re-denunciar o mesmo artigo retorna 200)
- Apenas moderadores podem visualizar lista de denúncias, resolver e rejeitar
- Denunciante pode ver apenas suas próprias denúncias (prevenção de IDOR)
- Status segue o fluxo unidirecional `pending → resolved / dismissed`

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/reports` | Denunciar artigo (idempotente) |
| `GET` | `/reports` | Listar denúncias (somente moderadores) |
| `GET` | `/reports/{id}` | Detalhes da denúncia (própria ou moderador) |
| `PUT` | `/reports/{id}/resolve` | Resolver denúncia (somente moderadores) |
| `PUT` | `/reports/{id}/dismiss` | Rejeitar denúncia (somente moderadores) |

## Design do Banco de Dados

```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user',
    created_at TEXT NOT NULL,
    CHECK (role IN ('user', 'moderator'))
);

CREATE TABLE reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reporter_id INTEGER NOT NULL,
    article_id INTEGER NOT NULL,
    reason TEXT NOT NULL,
    details TEXT,
    status TEXT NOT NULL DEFAULT 'pending',
    resolved_by INTEGER,
    resolved_at TEXT,
    resolution_note TEXT,
    created_at TEXT NOT NULL,
    UNIQUE (reporter_id, article_id),
    CHECK (status IN ('pending', 'resolved', 'dismissed')),
    CHECK (reason IN ('spam', 'harassment', 'misinformation', 'other')),
    FOREIGN KEY (reporter_id) REFERENCES users(id),
    FOREIGN KEY (article_id) REFERENCES articles(id),
    FOREIGN KEY (resolved_by) REFERENCES users(id)
);
```

`UNIQUE (reporter_id, article_id)` é a base da adição idempotente.
As constraints `CHECK` garantem no nível do BD status e motivos de denúncia válidos.

## Denúncia Idempotente

```php
$existing = $this->repository->findReportByReporterAndArticle($actorId, $articleId);
if ($existing !== null) {
    return $this->responseFactory->create($this->formatReport($existing), 200);
}

$id = $this->repository->createReport($actorId, $articleId, $reason, $details, date('c'));
$report = $this->repository->findReportById($id);

return $this->responseFactory->create($this->formatReport($report ?? []), 201);
```

Retorno `201` = nova denúncia, `200` = denúncia existente (o chamador distingue pelo status).

## RBAC — Verificação de Papel

```php
$actor = $this->repository->findUserById($actorId);
if ($actor === null || $actor['role'] !== 'moderator') {
    return $this->responseFactory->create(['error' => 'moderator role required'], 403);
}
```

Endpoints exclusivos para moderadores validam o papel no início do handler.

## Prevenção de IDOR

```php
$isModerator = $actor !== null && $actor['role'] === 'moderator';
$isReporter  = (int) $report['reporter_id'] === $actorId;

if (!$isModerator && !$isReporter) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

`GET /reports/{id}` só pode ser acessado por "própria denúncia" ou "moderador".
`reporter_id` não é obtido do corpo da requisição — sempre é definido a partir do header `X-User-Id`.

## Transição de Status (Unidirecional)

```php
if ($report['status'] !== 'pending') {
    return $this->responseFactory->create([
        'error' => 'report is not pending',
        'current_status' => $report['status'],
    ], 422);
}
```

Uma denúncia que foi uma vez transitada para `resolved` ou `dismissed` não pode ser reoperada.
A constraint `CHECK` do BD serve como backup para lacunas de validação na camada da aplicação.

## Obtenção de Parâmetros de Caminho

O Router do NENE2 armazena params de caminho no atributo `nene2.route.parameters`.

```php
// Forma correta de obter
$id = (int) Router::param($request, 'id');

// Incorreto (getAttribute('id') direto não funciona)
$id = (int) $request->getAttribute('id');
```

## Segurança do reporter_id

```php
// createReport: actorId já está confirmado a partir do header X-User-Id
$id = $this->repository->createReport($actorId, $articleId, $reason, $details, date('c'));
```

`reporter_id` ignora o campo `reporter_id` do corpo da requisição e
usa o `X-User-Id` autenticado. Isso previne personificação de outros usuários.

## Exemplo de Resposta POST /reports

```json
{
  "id": 1,
  "reporter_id": 1,
  "article_id": 3,
  "reason": "spam",
  "details": "This article contains repeated spam links",
  "status": "pending",
  "resolved_by": null,
  "resolved_at": null,
  "resolution_note": null,
  "created_at": "2026-05-21T12:00:00+00:00"
}
```

## Exemplo de Resposta PUT /reports/{id}/resolve

```json
{
  "id": 1,
  "reporter_id": 1,
  "article_id": 3,
  "reason": "spam",
  "details": "...",
  "status": "resolved",
  "resolved_by": 3,
  "resolved_at": "2026-05-21T13:00:00+00:00",
  "resolution_note": "Article removed for TOS violation",
  "created_at": "2026-05-21T12:00:00+00:00"
}
```

## Exemplo de Resposta GET /reports (Moderador)

```json
{
  "reports": [
    {
      "id": 2,
      "reporter_id": 2,
      "article_id": 5,
      "reason": "harassment",
      "status": "pending",
      ...
    }
  ],
  "count": 1
}
```
