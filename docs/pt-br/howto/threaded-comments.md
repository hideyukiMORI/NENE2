# Comentários em Thread

Implemente threads de comentários com auto-referência com limites de profundidade e soft delete.

## Visão Geral

Um sistema de comentários em thread tem uma tabela que referencia a si mesma. Cada comentário conhece seu `parent_id` (null para nível superior), sua `depth` (baseado em 0) e seu `status`. As respostas se aninham dentro de seu pai na árvore de resposta.

## Schema do Banco de Dados

```sql
CREATE TABLE comments (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id     INTEGER NOT NULL,
    parent_id   INTEGER,
    author_name TEXT    NOT NULL,
    body        TEXT    NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'published',
    depth       INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL,
    FOREIGN KEY (post_id)   REFERENCES posts(id),
    FOREIGN KEY (parent_id) REFERENCES comments(id)
);
```

`depth` é desnormalizado na linha para evitar queries de ancestrais recursivas em cada inserção.

## Profundidade Máxima

Aplique um limite de profundidade no momento da escrita:

```php
public const int MAX_DEPTH = 3;

public function canHaveReplies(): bool
{
    return $this->depth < self::MAX_DEPTH;
}
```

No handler de rota, verifique antes de inserir:

```php
if (!$parent->canHaveReplies()) {
    return $this->problems->create($request, 'unprocessable-entity', 'Profundidade máxima de comentário atingida.', 422, '');
}

$comment = $this->repo->addComment(
    $parent->postId, $parentId, $authorName, $body, $parent->depth + 1, $now,
);
```

## Soft Delete

Soft delete substitui o corpo por `[deleted]` e define `status = 'deleted'`. Os filhos são preservados:

```php
public function softDelete(int $id): void
{
    $this->executor->execute(
        "UPDATE comments SET status = 'deleted', body = '[deleted]' WHERE id = ?",
        [$id],
    );
}
```

A busca da árvore retorna comentários excluídos com corpos `[deleted]` para que a estrutura do thread permaneça coerente — os leitores veem um placeholder onde o comentário excluído estava, e seus filhos ainda são visíveis.

Tentar responder a um comentário excluído retorna 409:

```php
if ($parent->isDeleted()) {
    return $this->problems->create($request, 'conflict', 'Não é possível responder a um comentário excluído.', 409, '');
}
```

## Construindo a Árvore de Comentários Sem N+1

Carregue todos os comentários de uma postagem em uma única query ordenada por ID (pais sempre têm IDs menores que seus filhos). Então monte a árvore em PHP usando dois passos:

```php
// Passo 1: construir mapa de linhas brutas e lista de adjacência de IDs de filhos
foreach ($rows as $row) {
    $rowMap[(int) $row['id']]   = $row;
    $childIds[(int) $row['id']] = [];
}

foreach ($rowMap as $id => $row) {
    $parentId = isset($row['parent_id']) ? (int) $row['parent_id'] : null;
    if ($parentId === null) {
        $roots[] = $id;
    } elseif (isset($childIds[$parentId])) {
        $childIds[$parentId][] = $id;
    }
}

// Passo 2: construir recursivamente objetos Comment a partir das raízes
return $this->buildTree($roots, $rowMap, $childIds);
```

Manter linhas brutas e listas `int[]` de IDs de filhos separadas dos value objects `Comment` evita confusão de tipos no PHPStan ao trabalhar com classes `readonly`.

## Separando Dados de Linha de Value Objects

Ao montar uma árvore de value objects readonly recursivamente, o PHPStan precisa de fronteiras de tipo limpas. O padrão que funciona:

1. **Passo 1** — construir `array<int, array<string, mixed>> $rowMap` e `array<int, int[]> $childIds` a partir de linhas brutas. Sem value objects ainda.
2. **Passo 2** — `buildTree()` recebe apenas IDs `int[]` e os dois mapas, recursa e hidrata objetos `Comment` com arrays de filhos completamente montados.

Isso evita misturar objetos `Comment` e IDs `int` no mesmo array, o que causaria um tipo union que o PHPStan não consegue restringir.

## Resumo das Rotas

| Método | Caminho | Descrição |
|---|---|---|
| `POST` | `/posts` | Criar uma postagem |
| `GET` | `/posts/{id}` | Obter uma postagem |
| `POST` | `/posts/{id}/comments` | Adicionar comentário de nível superior |
| `GET` | `/posts/{id}/comments` | Obter árvore de comentários |
| `POST` | `/comments/{id}/replies` | Responder a um comentário |
| `DELETE` | `/comments/{id}` | Soft-delete de um comentário |

## Notas de Design

- `depth` é armazenado na linha (desnormalizado) para evitar queries de ancestrais recursivas em cada inserção.
- `ORDER BY id ASC` garante que pais apareçam antes de seus filhos ao carregar a lista plana.
- Soft delete preserva a estrutura do thread — hard delete tornaria comentários filhos órfãos.
- Responder a um comentário excluído é bloqueado (409) para prevenir threads fantasma.
