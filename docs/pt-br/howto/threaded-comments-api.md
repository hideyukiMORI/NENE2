# Como Fazer: API de Comentários em Thread

> **Referência FT**: FT343 (`NENE2-FT/threadlog`) — Sistema de comentários em thread de dois níveis com exclusão tombstone (conteúdo substituído por `[deleted]`), aplicação de profundidade de resposta, isolamento com escopo de postagem e prevenção de resposta a comentário excluído, 14 testes / 40+ assertivas PASS.

Este guia mostra como construir um sistema de comentários com um nível de respostas: comentários raiz podem receber respostas, mas respostas não podem ser respondidas (profundidade máxima = 1). Comentários excluídos são tombstoneados, preservando a estrutura do thread.

## Schema

```sql
CREATE TABLE comments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id    TEXT    NOT NULL,           -- identificador opaco de postagem
    parent_id  INTEGER REFERENCES comments(id),  -- NULL = comentário raiz
    author     TEXT    NOT NULL,
    content    TEXT    NOT NULL,
    deleted    INTEGER NOT NULL DEFAULT 0,
    deleted_at TEXT,
    created_at TEXT    NOT NULL
);
```

`parent_id IS NULL` = comentário raiz; `parent_id IS NOT NULL` = resposta.

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/posts/{postId}/comments` | Criar comentário raiz |
| `GET`  | `/posts/{postId}/comments` | Listar comentários com respostas |
| `GET`  | `/posts/{postId}/comments/{id}` | Obter comentário único |
| `POST` | `/posts/{postId}/comments/{id}/replies` | Adicionar resposta |
| `DELETE` | `/posts/{postId}/comments/{id}` | Soft delete (tombstone) |

## Criar Comentário Raiz

```php
POST /posts/post-1/comments
{"author": "alice", "content": "Ótima postagem!"}
→ 201
{
  "id": 1,
  "author": "alice",
  "content": "Ótima postagem!",
  "parent_id": null,
  "replies": [],
  "deleted": false,
  "created_at": "..."
}

// Campos ausentes
POST /posts/post-1/comments  {"author": "alice"}
→ 422  // content obrigatório
```

## Listar Comentários

```php
GET /posts/post-1/comments
→ 200
{
  "comments": [
    {
      "id": 1,
      "author": "alice",
      "content": "Comentário raiz",
      "replies": [
        {"id": 2, "author": "bob", "content": "Minha resposta", "parent_id": 1}
      ]
    }
  ]
}
```

Comentários são escopados ao `post_id`. Os comentários de `post-1` nunca aparecem na listagem de `post-2`.

## Obter Comentário Único

```php
GET /posts/post-1/comments/1
→ 200
{
  "id": 1,
  "author": "alice",
  "content": "Comentário raiz",
  "reply_count": 2,
  "replies": [...]
}

GET /posts/post-1/comments/999
→ 404
```

## Adicionar Resposta (Profundidade Máxima = 1)

```php
POST /posts/post-1/comments/1/replies
{"author": "bob", "content": "Minha resposta"}
→ 201
{
  "id": 2,
  "parent_id": 1,
  "author": "bob",
  "content": "Minha resposta"
}

// Resposta a uma resposta é rejeitada (profundidade seria 2)
POST /posts/post-1/comments/2/replies  {"author": "charlie", "content": "Resposta profunda"}
→ 409  // limite de profundidade excedido

// Resposta a comentário inexistente
POST /posts/post-1/comments/999/replies  {"author": "bob", "content": "X"}
→ 404

// Resposta a comentário excluído
// (comentário 1 já excluído)
POST /posts/post-1/comments/1/replies  {"author": "bob", "content": "X"}
→ 409  // não é possível responder a comentário excluído

// Campos ausentes → 422
```

### Implementação da Verificação de Profundidade

```php
public function canReceiveReply(int $commentId): bool
{
    $row = $this->findById($commentId);
    if ($row === null) {
        throw new CommentNotFoundException($commentId);
    }
    if ($row['deleted']) {
        throw new CommentDeletedException($commentId);
    }
    // Apenas comentários raiz (parent_id = null) podem ter respostas
    return $row['parent_id'] === null;
}
```

Retornar 409 quando `canReceiveReply()` retornar false.

## Exclusão Tombstone

```php
DELETE /posts/post-1/comments/1
→ 200
{
  "deleted": true,
  "author": "[deleted]",
  "content": "[deleted]"
}

// Comentário excluído ainda aparece na listagem (tombstone)
GET /posts/post-1/comments
→ 200
{
  "comments": [
    {
      "id": 1,
      "author": "[deleted]",
      "content": "[deleted]",
      "deleted": true,
      "replies": [{"id": 2, "author": "bob", ...}]  // respostas ainda visíveis
    }
  ]
}
```

Tombstoning preserva a estrutura do thread. As respostas permanecem visíveis mesmo após a exclusão do pai.

```php
// Excluir comentário já excluído → 404
DELETE /posts/post-1/comments/1  (já excluído)
→ 404

// Comentário desconhecido → 404
DELETE /posts/post-1/comments/999
→ 404
```

### SQL de Tombstone

```sql
UPDATE comments
SET deleted = 1, author = '[deleted]', content = '[deleted]', deleted_at = ?
WHERE id = ? AND deleted = 0
-- Corresponde apenas a linhas não excluídas
-- 0 linhas atualizadas → 404
```

---

## O Que NÃO Fazer

| Antipadrão | Risco |
|---|---|
| Hard-delete comentário pai | Respostas ficam órfãs; estrutura do thread quebra |
| Permitir profundidade de aninhamento ilimitada | Cadeias profundas criam queries SQL recursivas ou estouros de pilha |
| Retornar 404 para resposta a comentário excluído | Ocultar o estado do pai confunde os clientes; 409 com `detail` claro é melhor |
| Sem escopo `post_id` nas queries | Comentários de outras postagens aparecem na lista |
| Verificar profundidade apenas no lado do cliente | Atacante contorna a verificação enviando requisições diretamente à API |
| Mostrar autor/conteúdo de comentário excluído | Derrota o propósito da exclusão; sempre tombstone |
