# Como Fazer: API de Coleção (Listas Curadas por Usuário)

> **Referência FT**: FT299 (`NENE2-FT/collectionlog`) — Coleções de artigos curadas pelo usuário: visibilidade is_public/privado (404 para não proprietários em privado), deduplicação com UNIQUE(collection_id, article_id), ordenação por posição, acesso de escrita somente pelo proprietário, 20 testes / 34 asserções PASS.

Este guia mostra como construir uma API de coleção curada pelo usuário onde os usuários criam listas nomeadas, adicionam artigos a elas e controlam a visibilidade pública/privada.

## Schema

```sql
CREATE TABLE collections (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    name       TEXT    NOT NULL,
    is_public  INTEGER NOT NULL DEFAULT 0,  -- 0=privado, 1=público
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE collection_items (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    collection_id INTEGER NOT NULL,
    article_id    INTEGER NOT NULL,
    position      INTEGER NOT NULL,
    added_at      TEXT    NOT NULL,
    UNIQUE (collection_id, article_id),
    FOREIGN KEY (collection_id) REFERENCES collections(id),
    FOREIGN KEY (article_id)    REFERENCES articles(id)
);
```

`UNIQUE(collection_id, article_id)` previne que o mesmo artigo apareça duas vezes em uma coleção. `position` habilita exibição ordenada.

## Endpoints

| Método | Caminho | Auth | Descrição |
|--------|---------|------|-----------|
| `POST` | `/collections` | `X-User-Id` | Criar coleção |
| `GET` | `/collections/{id}` | `X-User-Id` | Obter coleção (verificação de visibilidade) |
| `PUT` | `/collections/{id}` | `X-User-Id` (proprietário) | Atualizar nome/visibilidade |
| `DELETE` | `/collections/{id}` | `X-User-Id` (proprietário) | Deletar coleção |
| `POST` | `/collections/{id}/items` | `X-User-Id` (proprietário) | Adicionar artigo |
| `DELETE` | `/collections/{id}/items/{articleId}` | `X-User-Id` (proprietário) | Remover artigo |

## Visibilidade — 404 para Coleções Privadas

```php
$isOwner  = (int) $collection['user_id'] === $actorId;
$isPublic = (bool) $collection['is_public'];

if (!$isOwner && !$isPublic) {
    return $this->responseFactory->create(['error' => 'collection not found'], 404);
}
```

Não proprietários tentando acessar uma coleção privada recebem 404 — não 403. Isso previne divulgar a existência de coleções privadas.

## Acesso de Escrita Somente pelo Proprietário

```php
if ((int) $collection['user_id'] !== $actorId) {
    return $this->responseFactory->create(['error' => 'access denied'], 403);
}
```

Operações de adicionar, remover, atualizar e deletar exigem que o ator seja o proprietário da coleção. Ao contrário da visibilidade, falhas de acesso de escrita retornam 403 (a existência da coleção já é conhecida neste ponto).

## UNIQUE(collection_id, article_id) — Deduplicação

A restrição do banco de dados previne que o mesmo artigo apareça duas vezes em uma coleção. A aplicação verifica duplicatas antes de inserir:

```php
// Repositório verifica findItem() antes de addItem()
if ($this->repository->findItem($id, $articleId) !== null) {
    return $this->responseFactory->create(['error' => 'article already in collection'], 409);
}
$this->repository->addItem($id, $articleId, date('c'));
```

## is_public como Inteiro Booleano

```php
$isPublic = isset($body['is_public']) && $body['is_public'] === true;
```

`is_public` é armazenado como INTEGER (0/1) no SQLite. Na leitura: `(bool) $collection['is_public']`. Na escrita: verificação estrita `=== true` previne que a string `"true"` habilite o acesso público.

## Formato de Resposta

```php
private function formatCollection(array $collection, array $items): array
{
    return [
        'id'         => (int)    $collection['id'],
        'user_id'    => (int)    $collection['user_id'],
        'name'       => (string) $collection['name'],
        'is_public'  => (bool)   $collection['is_public'],
        'item_count' => count($items),
        'items'      => array_map(fn($item) => [
            'article_id' => (int)    $item['article_id'],
            'title'      => (string) $item['article_title'],
            'position'   => (int)    $item['position'],
            'added_at'   => (string) $item['added_at'],
        ], $items),
        'created_at' => (string) $collection['created_at'],
        'updated_at' => (string) $collection['updated_at'],
    ];
}
```

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Retornar 403 para acesso a coleção privada | Revela a existência da coleção para não proprietários (divulgação de informações) |
| Permitir que qualquer usuário adicione itens a qualquer coleção | Não proprietários injetam conteúdo nas coleções de outros |
| Sem `UNIQUE(collection_id, article_id)` | Mesmo artigo adicionado duas vezes; entradas duplicadas confusas |
| Aceitar string `"true"` para `is_public` | Confusão de tipo: qualquer string é truthy na comparação frouxa |
| Sem campo de posição | Itens sempre aparecem em ordem de inserção; sem reordenação possível |
| DELETE da coleção sem verificação de propriedade | Qualquer usuário deleta qualquer coleção |
| Expor `item_count` sem incluir itens | Revela o tamanho da coleção mesmo para não proprietários de coleções privadas |
