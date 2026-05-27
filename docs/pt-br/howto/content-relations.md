# Relações de Conteúdo — Links M:N Auto-Referenciais Tipados

Vincule artigos (ou qualquer recurso) uns aos outros usando uma **tabela de junção com
coluna `relation_type`**. Suporte a tipos assimétricos (sequel ↔ prequel) com
inserção automática de inverso, e tipos simétricos (related, reference) com a mesma
lógica de inverso.

**Implementação de referência:** `FT173 relatedlog` em
[hideyukiMORI/NENE2-examples](https://github.com/hideyukiMORI/NENE2-examples)

---

## Quando Usar Este Padrão

| Use quando… | Considere alternativas quando… |
|---|---|
| Recursos se vinculam uns aos outros com arestas tipadas | Você só precisa de links "relacionados" sem tipo |
| Arestas assimétricas são necessárias (A é uma sequência de B) | Um sistema de tags simples é suficiente |
| Consultas bidirecionais devem ser rápidas | Travessia de grafo por muitos saltos é necessária |
| O tipo de relação afeta o comportamento da UI ("Ver sequências") | — |

---

## Schema

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    created_at TEXT    NOT NULL
);

CREATE TABLE article_relations (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id    INTEGER NOT NULL,
    related_id    INTEGER NOT NULL,
    relation_type TEXT    NOT NULL,
    -- 'related' | 'sequel' | 'prequel' | 'reference'
    created_at    TEXT    NOT NULL,
    UNIQUE (article_id, related_id, relation_type),
    FOREIGN KEY (article_id) REFERENCES articles(id),
    FOREIGN KEY (related_id) REFERENCES articles(id),
    CHECK (article_id != related_id)      -- auto-referência prevenida no nível do BD
);
```

### Notas de Design

- A constraint `UNIQUE (article_id, related_id, relation_type)` previne arestas duplicadas
  do mesmo tipo. O mesmo par pode ter **múltiplos** tipos
  (ex.: A → B como `related` e `reference` ao mesmo tempo).
- `CHECK (article_id != related_id)` previne auto-loops no nível do banco de dados.
- **Ambas as direções são armazenadas**: adicionar `A → B (sequel)` também insere `B → A (prequel)`.
  Isso torna as consultas por artigo triviais (`WHERE article_id = ?`) sem JOINs.

---

## Tipos de Relação

```php
enum RelationType: string
{
    case Related   = 'related';    // simétrico: A related B ↔ B related A
    case Sequel    = 'sequel';     // assimétrico: A sequel→B ↔ B prequel→A
    case Prequel   = 'prequel';    // assimétrico: inverso de sequel
    case Reference = 'reference';  // simétrico: citação bidirecional

    public function inverse(): self
    {
        return match ($this) {
            self::Sequel  => self::Prequel,
            self::Prequel => self::Sequel,
            default       => $this,  // related, reference são auto-inversos
        };
    }
}
```

---

## Operação Principal: Adicionar Relação com Inverso Automático

```php
public function addRelation(int $articleId, int $relatedId, RelationType $type, string $now): ArticleRelation
{
    // 1. Validar que ambos os artigos existem
    // 2. Verificar duplicata (a constraint UNIQUE também capturaria isso)
    $existing = $this->db->fetchOne(
        'SELECT id FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
        [$articleId, $relatedId, $type->value],
    );
    if ($existing !== null) {
        throw new RelationAlreadyExistsException($articleId, $relatedId, $type);
    }

    // 3. Inserir relação direta
    $id = $this->db->insert(
        'INSERT INTO article_relations (article_id, related_id, relation_type, created_at) VALUES (?, ?, ?, ?)',
        [$articleId, $relatedId, $type->value, $now],
    );

    // 4. Inserir inverso (se ainda não existir)
    $inverse = $type->inverse();
    $inverseExists = $this->db->fetchOne(
        'SELECT id FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
        [$relatedId, $articleId, $inverse->value],
    );
    if ($inverseExists === null) {
        $this->db->insert(
            'INSERT INTO article_relations (article_id, related_id, relation_type, created_at) VALUES (?, ?, ?, ?)',
            [$relatedId, $articleId, $inverse->value, $now],
        );
    }

    return new ArticleRelation($id, $articleId, $relatedId, $type, $now);
}
```

### Remover Relação (cascata no inverso)

```php
public function removeRelation(int $articleId, int $relatedId, RelationType $type): bool
{
    $deleted = $this->db->execute(
        'DELETE FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
        [$articleId, $relatedId, $type->value],
    );
    // Remover inverso
    $inverse = $type->inverse();
    $this->db->execute(
        'DELETE FROM article_relations WHERE article_id = ? AND related_id = ? AND relation_type = ?',
        [$relatedId, $articleId, $inverse->value],
    );
    return $deleted > 0;
}
```

---

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/articles` | Criar um artigo |
| `GET` | `/articles/{id}` | Obter artigo com stubs de relacionados embutidos |
| `POST` | `/articles/{id}/relations` | Adicionar uma relação (+ insere inverso automaticamente) |
| `GET` | `/articles/{id}/relations` | Listar relações (`?type=sequel` para filtrar) |
| `DELETE` | `/articles/{id}/relations/{relatedId}` | Remover relação (`?type=sequel` obrigatório) |

---

## Formatos de Resposta

### GET /articles/{id} — com relações embutidas

```json
{
  "data": { "id": 1, "title": "Part 1", ... },
  "relations": [
    {
      "relation": { "id": 1, "article_id": 1, "related_id": 2, "relation_type": "sequel", ... },
      "related":  { "id": 2, "title": "Part 2", ... }
    }
  ]
}
```

### POST /articles/{id}/relations — requisição

```json
{
  "related_id": 2,
  "relation_type": "sequel"
}
```

### DELETE /articles/{id}/relations/{relatedId}

```
DELETE /articles/1/relations/2?type=sequel
```

O parâmetro de query `type` é **obrigatório** — um par pode ter múltiplos tipos de relação
simultaneamente, então o tipo distingue qual aresta remover.

---

## Estrutura da Camada de Domínio

```
src/Article/
├── Article.php
├── ArticleRelation.php
├── ArticleRepository.php       # addRelation / removeRelation / listRelations / findWithRelations
├── RelationType.php            # enum com inverse()
├── ArticleNotFoundException.php
└── RelationAlreadyExistsException.php
```

---

## Casos de Borda

| Cenário | Comportamento |
|---------|---------------|
| Auto-relação (`article_id == related_id`) | 422 — verificado no handler antes do BD |
| Tipo duplicado entre o mesmo par | 409 Conflict |
| Mesmo par com tipo diferente | 201 — válido, armazenado como linhas separadas |
| Remover relação inexistente | 404 |
| Remover sem parâmetro `type` | 422 |
| Artigos ausentes | 404 para cada ID inválido |

---

## Veja Também

- [Sistema de Tags (M:N)](./tagging-system.md) — M:N recurso-para-tag sem arestas tipadas
- [Comentários Aninhados](./threaded-comments.md) — `parent_id` auto-referencial
- [Dados Hierárquicos](./hierarchical-data.md) — árvore de caminho materializado
- [Sistema de Seguimento de Usuários](./user-follow-system.md) — M:N direcional entre usuários
