# Como Fazer: Fluxo de Trabalho Rascunho → Publicação → Arquivamento

> **Referência FT**: FT305 (`NENE2-FT/draftlog`) — Máquina de estados do ciclo de vida de artigos: transições unidirecionais rascunho→publicado→arquivado, acesso de escrita apenas para o autor, não-autores veem apenas artigos publicados (rascunhos retornam 404), não é possível editar artigos publicados, listagem pública exclui rascunhos e arquivados, 20 testes / 28 asserções PASS.

Este guia mostra como implementar um ciclo de vida de conteúdo onde os artigos começam como rascunhos, são publicados para se tornarem visíveis e podem ser arquivados para removê-los das listagens públicas.

## Schema

```sql
CREATE TABLE articles (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id    INTEGER NOT NULL,
    title        TEXT    NOT NULL,
    body         TEXT    NOT NULL DEFAULT '',
    status       TEXT    NOT NULL DEFAULT 'draft',
    published_at TEXT,
    archived_at  TEXT,
    created_at   TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL,
    CHECK (status IN ('draft', 'published', 'archived')),
    FOREIGN KEY (author_id) REFERENCES users(id)
);
```

`CHECK (status IN (...))` garante que apenas estados conhecidos sejam armazenados. Os timestamps `published_at` e `archived_at` registram quando as transições ocorreram.

## Máquina de estados

```
draft ──(POST /publish)──▶ published ──(POST /archive)──▶ archived
```

| Transição | Pré-condição | Erro se violada |
|---|---|---|
| draft → published | status deve ser `'draft'` | 422 |
| published → archived | status deve ser `'published'` | 422 |
| published → draft | ❌ não permitido | — |
| archived → qualquer | ❌ não permitido | — |

```php
// Handler de publicação
if ($article['status'] !== 'draft') {
    return $this->responseFactory->create(['error' => 'only draft articles can be published'], 422);
}

// Handler de arquivamento
if ($article['status'] !== 'published') {
    return $this->responseFactory->create(['error' => 'only published articles can be archived'], 422);
}
```

## Endpoints

| Método | Caminho | Auth | Descrição |
|--------|---------|------|-----------|
| `POST` | `/articles` | `X-User-Id` | Criar artigo (começa como rascunho) |
| `GET` | `/articles` | — | Listar apenas artigos publicados |
| `GET` | `/articles/{id}` | `X-User-Id` | Obter artigo (verificação de visibilidade) |
| `PUT` | `/articles/{id}` | `X-User-Id` (autor) | Atualizar rascunho (apenas se for rascunho) |
| `POST` | `/articles/{id}/publish` | `X-User-Id` (autor) | Publicar |
| `POST` | `/articles/{id}/archive` | `X-User-Id` (autor) | Arquivar |

## Novos artigos começam como rascunho

```php
$id = $this->repo->create($actorId, $title, $body);
return $this->responseFactory->create(['id' => $id, 'status' => 'draft'], 201);
```

O `status` é sempre `'draft'` na criação, independentemente de qualquer campo do corpo. O cliente não pode escolher o status inicial.

## Visibilidade — Não-autores veem apenas publicados

```php
// Não-autores podem ver apenas artigos publicados
if ($article['status'] !== 'published' && (int) $article['author_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'not found'], 404);
}
```

Artigos não publicados (rascunho ou arquivado) retornam 404 para não-autores. Isso previne:
- Outros usuários lendo rascunhos não publicados
- Revelar se um artigo foi arquivado

## Não é possível editar artigos publicados

```php
// Handler de atualização — apenas rascunhos são editáveis
if ($article['status'] !== 'draft') {
    return $this->responseFactory->create(['error' => 'only draft articles can be edited'], 422);
}
if ((int) $article['author_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

Uma vez publicado, o conteúdo do artigo fica congelado. O autor deve despublicar (o que não é suportado aqui) para editar — neste design, publicar é uma barreira unidirecional.

## Endpoint de listagem — apenas publicados

```php
// Repositório: SELECT WHERE status = 'published' ORDER BY published_at DESC
$articles = $this->repo->listPublished();
```

O endpoint de listagem filtra para `status = 'published'` apenas. Rascunhos e artigos arquivados nunca aparecem na listagem pública.

## Ações apenas para o autor

Todas as operações de escrita (atualização, publicação, arquivamento) verificam se o ator é o autor do artigo:

```php
if ((int) $article['author_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'forbidden'], 403);
}
```

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Permitir status no corpo de criação | Cliente inicia artigo como `'published'` contornando o fluxo de revisão |
| Retornar 403 para GET de rascunho de não-autor | Revela que o artigo existe; use 404 para ocultar conteúdo não publicado |
| Permitir edição de artigos publicados | Retroativamente altera conteúdo ao vivo; viola a confiança do leitor |
| Permitir transição arquivado → publicado | Artigos arquivados reaparecem inesperadamente |
| Listar rascunhos na listagem pública | Conteúdo não publicado fica exposto antes de estar pronto |
| Sem `CHECK (status IN (...))` | Inserções diretas no DB podem definir strings de status arbitrárias |
| Artigos arquivados retornam 200 para não-autores | Informa não-autores que o conteúdo existiu e foi arquivado |
