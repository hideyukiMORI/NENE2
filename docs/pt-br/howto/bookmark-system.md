# Sistema de Favoritos

Permita que os usuários salvem itens em coleções nomeadas. O favoritar é idempotente — favoritar o mesmo item duas vezes retorna o favorito existente sem erro.

## Visão Geral

Um sistema de favoritos envolve:
- **Adicionar favorito** — salvar um item na coleção de um usuário (idempotente)
- **Remover favorito** — excluir um favorito salvo (404 se não encontrado)
- **Listar favoritos** — todos os favoritos de um usuário, opcionalmente filtrados por coleção
- **Contar favoritos** — contador leve para badge
- **Obter favorito** — verificar se um item específico está favoritado

## Schema do Banco de Dados

```sql
CREATE TABLE bookmarks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    item_id    INTEGER NOT NULL,
    collection TEXT    NOT NULL DEFAULT 'default',
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, item_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (item_id) REFERENCES items(id)
);
```

`UNIQUE (user_id, item_id)` impõe um favorito por usuário por item. O campo `collection` agrupa os favoritos em categorias nomeadas com `'default'` como fallback.

## Adição Idempotente

Verifique se um favorito existente antes de inserir. Em conflito (condição de corrida), capture `DatabaseConstraintException` e retorne o registro existente:

```php
public function add(int $userId, int $itemId, string $collection, string $now): Bookmark
{
    $existing = $this->find($userId, $itemId);

    if ($existing !== null) {
        return $existing;  // já favoritado — não é um erro
    }

    try {
        $this->executor->execute(
            'INSERT INTO bookmarks (user_id, item_id, collection, created_at) VALUES (?, ?, ?, ?)',
            [$userId, $itemId, $collection, $now],
        );
    } catch (DatabaseConstraintException) {
        // Condição de corrida — outra requisição ganhou; retorne o favorito existente
        $found = $this->find($userId, $itemId);
        if ($found !== null) {
            return $found;
        }
    }

    $id = (int) $this->executor->lastInsertId();
    return new Bookmark($id, $userId, $itemId, $collection, $now);
}
```

O padrão verificar-então-inserir trata o caso comum de forma eficiente. O catch de `DatabaseConstraintException` trata a condição de corrida sob requisições concorrentes.

## Filtragem por Coleção

Use um parâmetro de query `collection` opcional para filtrar favoritos:

```php
// GET /users/{userId}/bookmarks?collection=reading
$collection = isset($query['collection']) && $query['collection'] !== ''
    ? $query['collection'] : null;

$items = $this->repo->listByUser($userId, $collection);
```

Coleção `null` retorna todos os favoritos; uma string não vazia filtra para essa coleção.

## Remover Retorna 204 vs 404

- `204 No Content` — favorito existia e foi excluído
- `404 Not Found` — favorito não existia

```php
$removed = $this->repo->remove($userId, $itemId);

if (!$removed) {
    return $this->responseFactory->create(['error' => 'bookmark not found'], 404);
}

return $this->responseFactory->createEmpty(204);
```

`execute()` retorna a contagem de linhas afetadas — zero significa que nenhum favorito foi encontrado.

## Schema MySQL

MySQL requer sintaxe explícita `ENGINE=InnoDB` e `AUTO_INCREMENT`:

```sql
CREATE TABLE bookmarks (
    id         INT          NOT NULL AUTO_INCREMENT,
    user_id    INT          NOT NULL,
    item_id    INT          NOT NULL,
    collection VARCHAR(100) NOT NULL DEFAULT 'default',
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_item (user_id, item_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Para testes de integração MySQL, use `SET FOREIGN_KEY_CHECKS = 0` antes de remover tabelas para evitar problemas de ordem de dependência FK.

## Padrão de Teste de Integração MySQL

```php
protected function setUp(): void
{
    $host = (string) (getenv('MYSQL_HOST') ?: '');
    if ($host === '') {
        self::markTestSkipped('MYSQL_HOST não definido — pulando testes de integração MySQL');
    }
    ...
    $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $this->pdo->exec('DROP TABLE IF EXISTS bookmarks');
    $this->pdo->exec('DROP TABLE IF EXISTS items');
    $this->pdo->exec('DROP TABLE IF EXISTS users');
    $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $schema = (string) file_get_contents('.../database/schema.mysql.sql');
    $this->pdo->exec($schema);
}

protected function tearDown(): void
{
    if ($this->mysqlEnabled && $this->pdo !== null) {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DROP TABLE IF EXISTS bookmarks');
        $this->pdo->exec('DROP TABLE IF EXISTS items');
        $this->pdo->exec('DROP TABLE IF EXISTS users');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
```

## Propriedades de Segurança

| Propriedade | Implementação |
|---|---|
| Um favorito por usuário por item | Restrição DB `UNIQUE (user_id, item_id)` |
| Condição de corrida ao adicionar | Catch de `DatabaseConstraintException` → retorna existente |
| Isolamento por usuário | Todas as consultas filtram por `user_id` |
| Remover inexistente | Retorna 404 (não silencioso) |

## Resumo das Rotas

| Método | Caminho | Descrição |
|---|---|---|
| `POST` | `/users` | Criar um usuário |
| `POST` | `/items` | Criar um item |
| `POST` | `/users/{userId}/bookmarks` | Adicionar favorito (idempotente) |
| `DELETE` | `/users/{userId}/bookmarks/{itemId}` | Remover favorito (204 ou 404) |
| `GET` | `/users/{userId}/bookmarks` | Listar favoritos (filtro `?collection=`) |
| `GET` | `/users/{userId}/bookmarks/count` | Contagem total de favoritos |
| `GET` | `/users/{userId}/bookmarks/{itemId}` | Obter status de favorito único |
