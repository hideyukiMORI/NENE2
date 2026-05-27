# Sistema de Tagueamento (M:N)

Anexe tags a postagens usando uma tabela de junção many-to-many, com substituição atômica de tags e busca de tags sem N+1.

## Visão Geral

Um sistema de tagueamento tem três tabelas: `posts`, `tags` e `post_tags` (a tabela de junção). Postagens e tags têm um relacionamento M:N — uma postagem tem muitas tags, uma tag pertence a muitas postagens.

## Schema do Banco de Dados

```sql
CREATE TABLE posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL
);

CREATE TABLE tags (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL UNIQUE,
    created_at TEXT    NOT NULL
);

CREATE TABLE post_tags (
    post_id INTEGER NOT NULL,
    tag_id  INTEGER NOT NULL,
    PRIMARY KEY (post_id, tag_id),
    FOREIGN KEY (post_id) REFERENCES posts(id),
    FOREIGN KEY (tag_id)  REFERENCES tags(id)
);
```

A chave primária composta em `(post_id, tag_id)` aplica unicidade no nível do banco.

## Definindo Tags Atomicamente

O endpoint `PUT /posts/{id}/tags` substitui todas as tags de uma postagem em uma operação. Excluir primeiro, depois inserir:

```php
public function setPostTags(int $postId, array $tagNames): array
{
    $this->executor->execute('DELETE FROM post_tags WHERE post_id = ?', [$postId]);

    foreach ($tagNames as $name) {
        $tag = $this->findTagByName($name);
        if ($tag === null) {
            continue; // Silenciosamente pular nomes de tag desconhecidos
        }

        $this->executor->execute(
            'INSERT OR IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)',
            [$postId, $tag->id],
        );
    }

    return $this->findTagsByPostId($postId);
}
```

- Delete-then-insert torna a operação idempotente: chamá-la duas vezes com o mesmo payload tem o mesmo resultado.
- `INSERT OR IGNORE` previne um erro do banco se o mesmo nome de tag aparecer duas vezes no corpo da requisição.
- Nomes de tag desconhecidos são silenciosamente ignorados — o cliente deve criar tags antes de atribuí-las.
- Para limpar todas as tags, envie `{"tags": []}`.

## Evitando Queries N+1

Ao carregar uma lista de postagens (ex.: para busca baseada em tags), busque todas as tags em uma única query `IN` em vez de uma query por postagem:

```php
private function findTagsByPostIds(array $postIds): array
{
    if ($postIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($postIds), '?'));
    $rows = $this->executor->fetchAll(
        "SELECT t.*, pt.post_id FROM tags t
         INNER JOIN post_tags pt ON pt.tag_id = t.id
         WHERE pt.post_id IN ({$placeholders})
         ORDER BY t.name ASC",
        $postIds,
    );

    $map = [];
    foreach ($rows as $row) {
        $postId         = (int) $row['post_id'];
        $map[$postId][] = $this->hydrateTag($row);
    }

    return $map;
}
```

Isso retorna `array<int, Tag[]>` indexado por ID de postagem. Duas queries no total independentemente do número de postagens.

## Unicidade de Tag

Tags têm uma restrição `UNIQUE` em `name`. Criação duplicada retorna 409:

```php
public function createTag(string $name, string $now): ?Tag
{
    try {
        $this->executor->execute(
            'INSERT INTO tags (name, created_at) VALUES (?, ?)',
            [$name, $now],
        );
    } catch (\RuntimeException) {
        return null; // null → handler retorna 409
    }

    return $this->findTagByName($name);
}
```

## Busca Baseada em Tags

Filtrar postagens por tag usando um JOIN:

```sql
SELECT p.* FROM posts p
INNER JOIN post_tags pt ON pt.post_id = p.id
INNER JOIN tags t ON t.id = pt.tag_id
WHERE t.name = ?
ORDER BY p.id DESC
```

Depois carregue tags em lote para o conjunto de resultados com a query `IN` acima.

## Resumo das Rotas

| Método | Caminho | Descrição |
|---|---|---|
| `POST` | `/posts` | Criar uma postagem |
| `GET` | `/posts/{id}` | Obter uma postagem com suas tags |
| `POST` | `/tags` | Criar uma tag (duplicata → 409) |
| `GET` | `/tags` | Listar todas as tags (alfabeticamente) |
| `PUT` | `/posts/{id}/tags` | Substituir todas as tags de uma postagem |
| `GET` | `/tags/{name}/posts` | Listar postagens tagueadas com uma tag |

## Notas de Design

- Tags são entidades gerenciadas pela aplicação, não texto livre. Clientes criam tags primeiro, depois as atribuem.
- Nomes de tag desconhecidos em `PUT /posts/{id}/tags` são silenciosamente ignorados. Isso evita uma ida e volta para pré-validar nomes.
- Nomes de tag são ordenados alfabeticamente nas respostas para saída determinística.
- `GET /tags/{name}/posts` retorna 404 se a tag não existe, distinguindo "tag desconhecida" de "tag existe mas não tem postagens" (que retorna 200 com array vazio).
