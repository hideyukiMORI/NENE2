# Como Fazer: API de Relações entre Artigos

> **Referência FT**: FT334 (`NENE2-FT/relatedlog`) — Relações tipadas entre artigos com criação automática de inverso, tipos de relação simétricos e assimétricos, filtro por tipo e stubs de relação embutidos nas respostas GET, 17 testes / 40+ asserções PASS.

Este guia mostra como modelar relacionamentos tipados entre itens de conteúdo — `related`, `sequel`, `prequel`, `reference` — com gerenciamento automático de inverso para que cada relação permaneça consistente em ambas as direções.

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
    article_id    INTEGER NOT NULL REFERENCES articles(id),
    related_id    INTEGER NOT NULL REFERENCES articles(id),
    relation_type TEXT    NOT NULL,
    created_at    TEXT    NOT NULL,
    UNIQUE(article_id, related_id, relation_type)
);
```

`UNIQUE(article_id, related_id, relation_type)` previne arestas de relação duplicadas para o mesmo tipo. Tipos diferentes entre o mesmo par são permitidos.

## Tipos de Relação e Inversos

| Tipo enviado | Inverso criado automaticamente |
|---|---|
| `related` | `related` (simétrico) |
| `sequel` | `prequel` |
| `prequel` | `sequel` |
| `reference` | `reference` (simétrico) |

Quando A→B é `sequel`, o servidor insere atomicamente B→A como `prequel`. Deletar A→B também deleta B→A.

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/articles` | Criar artigo |
| `GET`  | `/articles/{id}` | Obter artigo com relações embutidas |
| `POST` | `/articles/{id}/relations` | Adicionar uma relação |
| `GET`  | `/articles/{id}/relations` | Listar relações (opcional ?type=) |
| `DELETE` | `/articles/{id}/relations/{relatedId}?type=` | Remover uma relação (e seu inverso) |

## Criar Artigo

```php
POST /articles
{"title": "Hello", "body": "World"}
→ 201  {"id": 1, "title": "Hello", "body": "World", "created_at": "..."}

// Título ausente
POST /articles  {"body": "No title"}
→ 422

// Body ausente
POST /articles  {"title": "No body"}
→ 422
```

## GET Artigo com Relações Embutidas

```php
GET /articles/1
→ 200
{
  "data": {"id": 1, "title": "Intro", ...},
  "relations": [
    {
      "relation": {"relation_type": "sequel"},
      "related":  {"id": 2, "title": "Follow-up"}
    }
  ]
}

// Sem relações ainda
GET /articles/1
→ 200  {"data": {...}, "relations": []}

GET /articles/9999
→ 404
```

## Adicionar uma Relação

```php
POST /articles/1/relations
{"related_id": 2, "relation_type": "sequel"}
→ 201  {"relation_type": "sequel", "article_id": 1, "related_id": 2}

// O inverso é inserido automaticamente: o artigo 2 agora tem uma relação "prequel" apontando para 1
GET /articles/2/relations
→ 200  {"data": [{"relation_type": "prequel", "related_id": 1}]}
```

### Relação simétrica

```php
POST /articles/1/relations
{"related_id": 2, "relation_type": "related"}
→ 201

// B também recebe uma relação "related" para A automaticamente
GET /articles/2/relations
→ 200  {"data": [{"related_id": 1, "relation_type": "related"}]}
```

### Casos de Erro

```php
// related_id desconhecido
POST /articles/1/relations  {"related_id": 9999, "relation_type": "related"}
→ 404

// Duplicado (mesmo par + mesmo tipo já existe)
POST /articles/1/relations  {"related_id": 2, "relation_type": "related"}
→ 409

// Auto-relação
POST /articles/1/relations  {"related_id": 1, "relation_type": "related"}
→ 422

// Tipo de relação inválido
POST /articles/1/relations  {"related_id": 2, "relation_type": "not-a-type"}
→ 422
```

### Múltiplos Tipos entre o Mesmo Par

O mesmo par pode ter múltiplos tipos de relação diferentes:

```php
POST /articles/1/relations  {"related_id": 2, "relation_type": "related"}   → 201
POST /articles/1/relations  {"related_id": 2, "relation_type": "reference"} → 201

GET /articles/1/relations
→ 200  {"data": [
    {"related_id": 2, "relation_type": "related"},
    {"related_id": 2, "relation_type": "reference"}
  ]}
```

## Listar Relações

```php
// Todas as relações
GET /articles/1/relations
→ 200  {"data": [{...}, {...}]}

// Filtrar por tipo
GET /articles/1/relations?type=sequel
→ 200  {"data": [{"related_id": 2, "relation_type": "sequel"}]}

// Artigo desconhecido
GET /articles/9999/relations
→ 404
```

## Deletar Relação

```php
DELETE /articles/1/relations/2?type=related
→ 200  {"deleted": true}

// O inverso também é removido automaticamente
GET /articles/2/relations
→ 200  {"data": []}  // não tem mais "related" de volta para 1

// Não encontrado
DELETE /articles/1/relations/2?type=related
→ 404

// Parâmetro de query type ausente
DELETE /articles/1/relations/2
→ 422
```

## Implementação — Gerenciamento Atômico de Inverso

```php
private function addRelation(int $articleId, int $relatedId, string $type): void
{
    $this->db->beginTransaction();

    $inverse = match ($type) {
        'sequel'    => 'prequel',
        'prequel'   => 'sequel',
        default     => $type,   // related, reference → simétrico
    };

    $this->repo->insert($articleId, $relatedId, $type);
    $this->repo->insert($relatedId, $articleId, $inverse);

    $this->db->commit();
}

private function removeRelation(int $articleId, int $relatedId, string $type): void
{
    $inverse = match ($type) {
        'sequel'    => 'prequel',
        'prequel'   => 'sequel',
        default     => $type,
    };

    $this->db->beginTransaction();
    $this->repo->delete($articleId, $relatedId, $type);
    $this->repo->delete($relatedId, $articleId, $inverse);
    $this->db->commit();
}
```

Envolva ambas as inserções/deleções em uma transação — se uma falhar, nenhuma é confirmada.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Inserir relação sem verificar existência do artigo | Violação de FK ou inserção silenciosa de 0 linhas; sempre 404 para IDs desconhecidos |
| Sem transação ao redor da inserção direta + inverso | Falha parcial deixa dados assimétricos (A→B existe mas B→A não) |
| Sem `UNIQUE(article_id, related_id, relation_type)` | Arestas duplicadas inflam contagens de listagem |
| Permitir auto-relações | Ciclos na travessia de relações; `sequel` de si mesmo não tem significado |
| Assumir que todos os tipos são simétricos | `sequel`→`sequel` (errado) em vez de `prequel` |
| Deletar apenas a aresta direta | Inverso órfão permanece; B ainda "vê" A como prequel após A ser deletado |
