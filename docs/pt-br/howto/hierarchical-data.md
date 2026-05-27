# Dados Hierárquicos — FK Auto-Referencial + Caminho Materializado

> **Referência FT**: FT171 (`NENE2-FT/hierarchylog`) — Categorias hierárquicas com FK auto-referencial e caminho materializado para queries O(1) de subárvore.

Armazene uma árvore de categorias (ou qualquer hierarquia) em uma única tabela SQL usando uma
**foreign key auto-referencial** (`parent_id`) mais um **caminho materializado**
(`/1/3/7/`) para permitir queries O(1) de subárvore.

---

## Quando Usar Este Padrão

| Use quando… | Considere alternativas quando… |
|---|---|
| A profundidade é limitada (≤ 5–10 níveis) | Profundidade ilimitada com re-parenteamento frequente |
| Leituras de subárvore são comuns | A árvore é intensiva em escrita com muitas movimentações |
| Uma solução de banco de dados único é preferida | Relacionamentos em grafo (múltiplos pais) |

---

## Design do Schema

```sql
CREATE TABLE categories (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    parent_id  INTEGER,                      -- NULL = nó raiz
    path       TEXT    NOT NULL UNIQUE,      -- caminho materializado: "/1/", "/1/3/", "/1/3/7/"
    depth      INTEGER NOT NULL DEFAULT 0,  -- 0 = raiz
    created_at TEXT    NOT NULL,
    FOREIGN KEY (parent_id) REFERENCES categories(id)
);
```

### Convenção de Caminho

- Nó raiz: `/1/` (corresponde a `/{id}/`)
- Filho de nível 1 da raiz: `/1/3/`
- Neto de nível 2: `/1/3/7/`
- Sempre começa e termina com `/`.
- Após o INSERT, calcule `path = parentPath . newId . '/'` e faça UPDATE na linha.

---

## Operações Principais

### Criar (com cálculo de caminho)

```php
// 1. INSERT com um placeholder temporário
$id = $this->db->insert(
    'INSERT INTO categories (name, parent_id, path, depth, created_at) VALUES (?, ?, ?, ?, ?)',
    [$name, $parentId, '__tmp__', $depth, $now],
);
// 2. Corrige o caminho agora que sabemos o id
$path = $parentPath . $id . '/';
$this->db->execute('UPDATE categories SET path = ? WHERE id = ?', [$path, $id]);
```

### Query de Subárvore (O(1) na coluna path indexada)

```php
// Todos os descendentes do nó com path "/1/3/"
$rows = $this->db->fetchAll(
    "SELECT * FROM categories WHERE path LIKE ? AND id != ? ORDER BY path",
    [$root->path . '%', $rootId],
);
```

### Ancestrais

```php
// Path "/1/3/7/" → ids ancestrais [1, 3]
$parts = array_filter(explode('/', $node->path));
$ancestorIds = array_filter(
    array_map('intval', $parts),
    fn(int $pid) => $pid !== $node->id,
);
```

### Mover (em cascata para descendentes)

```php
$oldPath = $node->path;
$newPath = $newParentPath . $id . '/';

// Atualiza o próprio nó
$this->db->execute(
    'UPDATE categories SET parent_id = ?, path = ?, depth = ? WHERE id = ?',
    [$newParentId, $newPath, $newDepth, $id],
);

// Cascata para todos os descendentes
foreach ($this->subtree($id) as $desc) {
    $updatedPath  = $newPath . substr($desc->path, strlen($oldPath));
    $updatedDepth = $desc->depth - $node->depth + $newDepth;
    $this->db->execute(
        'UPDATE categories SET path = ?, depth = ? WHERE id = ?',
        [$updatedPath, $updatedDepth, $desc->id],
    );
}
```

---

## Regras de Validação

| Regra | Implementação |
|---|---|
| Profundidade máxima | `if ($parent->depth >= MAX_DEPTH - 1) throw CategoryDepthException` |
| Circular (auto-movimentação) | `if ($newParentId === $id) throw CategoryCircularException` |
| Circular (descendente) | `if (str_starts_with($newParent->path, $node->path)) throw CategoryCircularException` |
| Deletar apenas folha | `if ($children !== []) throw CategoryHasChildrenException` |
| Overflow de profundidade ao mover | Verifique `$newDepth + maxSubtreeRelativeDepth >= MAX_DEPTH` antes de mover |

---

## Endpoints

| Método | Caminho | Descrição |
|---|---|---|
| `GET` | `/categories` | Listar categorias raiz (`?parent_id=N` para filhos) |
| `POST` | `/categories` | Criar uma categoria |
| `GET` | `/categories/{id}` | Obter uma categoria com sua cadeia de ancestrais |
| `GET` | `/categories/{id}/subtree` | Obter todos os descendentes |
| `PUT` | `/categories/{id}` | Renomear uma categoria |
| `PATCH` | `/categories/{id}/move` | Mover para um novo pai (`parent_id: null` para raiz) |
| `DELETE` | `/categories/{id}` | Deletar uma folha (rejeitar se tiver filhos → 409) |

---

## Formas de Resposta

### Objeto de categoria

```json
{
  "id": 7,
  "name": "PHP Frameworks",
  "parent_id": 3,
  "path": "/1/3/7/",
  "depth": 2,
  "created_at": "2026-01-01T00:00:00+00:00"
}
```

### GET /categories/{id} com ancestrais

```json
{
  "data": { ... },
  "ancestors": [
    { "id": 1, "name": "Technology", "depth": 0, ... },
    { "id": 3, "name": "Programming", "depth": 1, ... }
  ]
}
```

---

## Estrutura da Camada de Domínio

```
src/Category/
├── Category.php                    # entidade readonly
├── CategoryRepository.php          # operações de árvore (create / list / subtree / ancestors / move / delete)
├── RouteRegistrar.php              # conecta handlers HTTP ao Router
├── CategoryNotFoundException.php
├── CategoryDepthException.php
├── CategoryCircularException.php
└── CategoryHasChildrenException.php
```

---

## Trade-offs vs. Nested Sets / Closure Tables

| Abordagem | Leitura de subárvore | Insert | Mover |
|---|---|---|---|
| **Caminho materializado** (este guia) | Rápido (`LIKE`) | O(1) | O(tamanho da subárvore) |
| Closure table | Rápido (join) | O(ancestrais) | O(subárvore × ancestrais) |
| Nested sets | Rápido (`BETWEEN`) | O(tabela) | O(tabela) |

O caminho materializado é o ponto ideal para árvores de profundidade limitada onde movimentações são
infrequentes. Use closure table quando queries de ancestrais devem ser apenas por índice e
movimentações são frequentes.

---

## Veja Também

- [Adicionar um endpoint com banco de dados](./add-database-endpoint.md)
- [Adicionar paginação](./add-pagination.md)
- [Soft delete](./soft-delete.md)
