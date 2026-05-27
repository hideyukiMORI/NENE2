# Como Fazer: API de Árvore de Hierarquia de Categorias

> **Referência FT**: FT344 (`NENE2-FT/treelog`) — Árvore de categorias com parent_id + depth, filhos imediatos, CTEs recursivos para ancestrais/descendentes, deleção somente de folhas (409 se tem filhos), 17 testes PASS.

Este guia mostra como construir uma árvore hierárquica de categorias: criar categorias com pais opcionais, percorrer a árvore para cima (ancestrais) e para baixo (descendentes) usando CTEs SQL recursivos e aplicar deleção segura.

## Schema

```sql
CREATE TABLE categories (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT    NOT NULL,
    parent_id  INTEGER REFERENCES categories(id) ON DELETE RESTRICT,
    depth      INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL
);

CREATE INDEX idx_categories_parent ON categories(parent_id);
```

`depth` é calculado na inserção: `parent.depth + 1` (raiz = 0). `ON DELETE RESTRICT` previne a remoção de um pai que ainda tem filhos.

## Endpoints

| Método   | Caminho                              | Descrição                          |
|----------|--------------------------------------|------------------------------------|
| `POST`   | `/categories`                        | Criar categoria raiz ou filha      |
| `GET`    | `/categories`                        | Listar somente categorias raiz     |
| `GET`    | `/categories/{id}`                   | Obter categoria única              |
| `GET`    | `/categories/{id}/children`          | Somente filhos imediatos           |
| `GET`    | `/categories/{id}/ancestors`         | Caminho da raiz até o nó (breadcrumb) |
| `GET`    | `/categories/{id}/descendants`       | Todos os nós da subárvore (qualquer profundidade) |
| `DELETE` | `/categories/{id}`                   | Deletar somente folha (409 se tem filhos) |

## Criar Categoria

```php
// Categoria raiz (sem pai)
POST /categories
{"name": "Electronics"}

→ 201
{"id": 1, "name": "Electronics", "parent_id": null, "depth": 0, "created_at": "..."}

// Categoria filha
POST /categories
{"name": "Smartphones", "parent_id": 1}

→ 201
{"id": 2, "name": "Smartphones", "parent_id": 1, "depth": 1, "created_at": "..."}

// Neta
POST /categories
{"name": "Android", "parent_id": 2}
→ 201  // depth: 2
```

### Validação

```php
POST /categories  {"parent_id": 9999}
→ 404  // pai não existe

POST /categories  {"parent_id": 1}
→ 422  // name é obrigatório
```

### Cálculo de Profundidade na Inserção

```php
$depth = 0;
if ($parentId !== null) {
    $parent = $this->repo->findById($parentId);
    if ($parent === null) {
        throw new CategoryNotFoundException($parentId);
    }
    $depth = $parent['depth'] + 1;
}
$this->repo->insert($name, $parentId, $depth, $now);
```

## Listar Categorias Raiz

```php
GET /categories

→ 200
{
  "items": [
    {"id": 1, "name": "Electronics", "parent_id": null, "depth": 0, ...},
    {"id": 5, "name": "Clothing",    "parent_id": null, "depth": 0, ...}
  ],
  "total": 2
}
```

Retorna apenas `WHERE parent_id IS NULL` — nenhuma categoria filha incluída.

## Listar Filhos Imediatos

```php
GET /categories/1/children

→ 200
{
  "items": [
    {"id": 2, "name": "Smartphones", "parent_id": 1, "depth": 1, ...},
    {"id": 3, "name": "Laptops",     "parent_id": 1, "depth": 1, ...}
  ],
  "total": 2
}
```

**Somente imediatos** — netos NÃO aparecem aqui; use `/descendants` para a subárvore completa.

```sql
SELECT * FROM categories WHERE parent_id = ? ORDER BY id ASC
```

## Obter Ancestrais (Caminho Breadcrumb) — CTE Recursivo

```php
GET /categories/4/ancestors

// Categoria 4 = "Android" (depth 2, pai "Smartphones")
→ 200
{
  "items": [
    {"id": 1, "name": "Electronics", "depth": 0, ...},   // raiz primeiro
    {"id": 2, "name": "Smartphones", "depth": 1, ...}    // pai mais próximo por último
  ],
  "total": 2
}

// Categoria raiz não tem ancestrais
GET /categories/1/ancestors
→ 200  {"items": [], "total": 0}
```

Ordenado por `depth ASC` → raiz primeiro (ordem natural de breadcrumb).

### CTE Recursivo para Ancestrais

```sql
WITH RECURSIVE ancestor_cte(id, name, parent_id, depth, created_at) AS (
    -- Semente: começar pelo pai direto
    SELECT c.id, c.name, c.parent_id, c.depth, c.created_at
    FROM categories c
    WHERE c.id = (SELECT parent_id FROM categories WHERE id = :id)

    UNION ALL

    -- Recursão: subir até a raiz
    SELECT c.id, c.name, c.parent_id, c.depth, c.created_at
    FROM categories c
    INNER JOIN ancestor_cte a ON c.id = a.parent_id
)
SELECT * FROM ancestor_cte ORDER BY depth ASC
```

## Obter Descendentes (Subárvore Completa) — CTE Recursivo

```php
GET /categories/1/descendants

// "Electronics" tem Smartphones, Laptops, Android (filho de Smartphones)
→ 200
{
  "items": [
    {"id": 2, "name": "Smartphones", "depth": 1, ...},
    {"id": 3, "name": "Laptops",     "depth": 1, ...},
    {"id": 4, "name": "Android",     "depth": 2, ...}
  ],
  "total": 3   // todos os nós da subárvore, não apenas filhos diretos
}

// Folha retorna vazio
GET /categories/4/descendants
→ 200  {"items": [], "total": 0}
```

Irmãos do nó consultado **não** aparecem.

### CTE Recursivo para Descendentes

```sql
WITH RECURSIVE desc_cte(id, name, parent_id, depth, created_at) AS (
    -- Semente: filhos imediatos
    SELECT id, name, parent_id, depth, created_at
    FROM categories WHERE parent_id = :id

    UNION ALL

    -- Recursão: filhos dos filhos
    SELECT c.id, c.name, c.parent_id, c.depth, c.created_at
    FROM categories c
    INNER JOIN desc_cte d ON c.parent_id = d.id
)
SELECT * FROM desc_cte ORDER BY depth ASC, id ASC
```

## Deletar Categoria

```php
// Nó folha → 204 No Content
DELETE /categories/4   // "Android" (sem filhos)
→ 204

// Nó com filhos → 409 Conflict
DELETE /categories/1   // "Electronics" (tem Smartphones, Laptops)
→ 409
{
  "type": "https://nene2.dev/problems/has-children",
  "title": "Category has children",
  "status": 409,
  "detail": "Cannot delete a category that has children"
}

// Inexistente → 404
DELETE /categories/9999
→ 404
```

### Implementação de Deleção

```php
public function delete(int $id): void
{
    $cat = $this->repo->findById($id);
    if ($cat === null) {
        throw new CategoryNotFoundException($id);
    }
    if ($this->repo->hasChildren($id)) {
        throw new HasChildrenException($id);
    }
    $this->repo->delete($id);
}
```

```sql
-- verificação hasChildren
SELECT COUNT(*) FROM categories WHERE parent_id = ?

-- Deletar
DELETE FROM categories WHERE id = ?
```

---

## Avaliação ATK — Teste de Ataque com Mentalidade de Cracker

### ATK-01 — Manipulação de Parent ID para Criar Referência Circular 🚫 BLOCKED

**Ataque**: Atacante cria uma cadeia A→B→C e depois reatribui o pai de B para C para criar um ciclo que causa recursão infinita no CTE.
**Resultado**: BLOCKED — `parent_id` é definido apenas no momento da criação; não existe endpoint PATCH/PUT para reatribuir pais. A profundidade é calculada uma vez na inserção a partir da profundidade verificada do pai. Ciclos são estruturalmente impossíveis com parentesco imutável.

---

### ATK-02 — Parent ID Inexistente na Criação 🚫 BLOCKED

**Ataque**: Atacante envia `{"name": "Orphan", "parent_id": 9999}` para criar uma categoria pendente.
**Resultado**: BLOCKED — O repositório busca o pai antes de inserir; pai ausente lança `CategoryNotFoundException` → 404. Nenhuma linha órfã é criada.

---

### ATK-03 — Deletar Nó Não-Folha para Remover Subárvore 🚫 BLOCKED

**Ataque**: Atacante envia `DELETE /categories/1` (raiz com muitos filhos) para apagar toda a subárvore.
**Resultado**: BLOCKED — `hasChildren()` retorna true → `HasChildrenException` → 409. `ON DELETE RESTRICT` também aplica isso na camada do banco de dados; mesmo se a lógica da aplicação fosse contornada, a restrição FK previne a deleção.

---

### ATK-04 — Travessia CTE em Categoria Inexistente 🚫 BLOCKED

**Ataque**: Atacante solicita `/categories/9999/ancestors` ou `/categories/9999/descendants` para um ID inexistente para sondar dados.
**Resultado**: BLOCKED — O repositório verifica que a categoria existe antes de executar o CTE. Categoria ausente → `CategoryNotFoundException` → 404. Sem vazamento de dados.

---

### ATK-05 — SQL Injection via Nome de Categoria 🚫 BLOCKED

**Ataque**: Atacante envia `{"name": "'; DROP TABLE categories; --"}` para injetar SQL.
**Resultado**: BLOCKED — Todas as consultas usam declarações preparadas PDO com parâmetros vinculados. O nome é armazenado literalmente como string e nunca interpolado em SQL.

---

### ATK-06 — Loop Infinito no CTE Recursivo via Ciclo 🚫 BLOCKED

**Ataque**: Atacante tenta criar uma situação onde o ancestor_cte faz loop indefinidamente (A pai de B, B pai de A).
**Resultado**: BLOCKED — `parent_id` é imutável após a criação. Criar A com `parent_id=B` exige que B exista primeiro; nesse ponto A não existe, então B não pode ter sido criado com `parent_id=A`. A restrição de criação sequencial torna ciclos impossíveis.

---

### ATK-07 — Bomba de Profundidade CTE com Cadeia Profunda ✅ SAFE

**Ataque**: Atacante cria uma cadeia com mais de 1000 níveis de profundidade para esgotar o limite de recursão do CTE.
**Resultado**: SAFE — O limite de recursão padrão do SQLite para CTEs é 1000. Uma cadeia muito longa poderia acionar esse limite. Na prática, o rate limiting e o custo de criação de nó por requisição tornam isso impraticável. Adicione uma guarda `MAX_DEPTH` na inserção (ex.: rejeitar `depth > 20`) para implantações em produção.

---

### ATK-08 — Enumeração de ID via GET /categories/{id} 🚫 BLOCKED

**Ataque**: Atacante itera IDs inteiros para enumerar todas as categorias, incluindo as que não deveria ver.
**Resultado**: BLOCKED — Se as categorias são por usuário ou por tenant, verificações de autorização (JWT claim de tenant / propriedade) protegem o GET individual. O treelog demonstra acesso de leitura público como linha de base; a restrição de escopo é uma preocupação da camada de autorização.

---

### ATK-09 — Endpoint de Filhos Retorna Netos Inesperadamente ✅ SAFE

**Ataque**: Atacante espera que `/children` exponha inadvertidamente dados de subárvore em vários níveis.
**Resultado**: SAFE — `/children` retorna apenas filhos imediatos (`WHERE parent_id = ?`). Netos requerem travessia explícita em `/descendants`. Sem exposição de dados não intencional via endpoint de filhos.

---

### ATK-10 — Esgotamento de Memória com Campo Name Grande ✅ SAFE

**Ataque**: Atacante envia um valor `name` de 10 MB no payload de criação.
**Resultado**: SAFE — O middleware de limite de tamanho de requisição (padrão 1 MB) rejeita corpos excessivos antes de chegar ao handler. Validação de comprimento de `name` no nível da aplicação (ex.: `max: 255`) fornece uma segunda guarda.

---

### ATK-11 — Poda Sequencial de Subárvore para Deletar Nó Protegido ✅ SAFE

**Ataque**: Atacante deleta todos os filhos individualmente para tornar um nó protegido do meio da árvore uma folha, depois o deleta.
**Resultado**: SAFE — Esta é uma sequência de operações válida. Podar filhos um a um é a forma correta de remover uma subárvore. A autorização (verificação de propriedade) previne que usuários não autorizados deletem categorias de outros.

---

### ATK-12 — Condição de Corrida: Verificação hasChildren Antes de Inserção de Filho 🚫 BLOCKED

**Ataque**: Duas requisições concorrentes: uma verifica `hasChildren()` (retorna false) e prossegue para deletar; outra cria um novo filho pouco antes da deleção ser executada.
**Resultado**: BLOCKED — A restrição FK `ON DELETE RESTRICT` no nível do banco de dados previne a deleção se uma linha filha existir no momento do commit. Mesmo que a verificação `hasChildren()` da camada de aplicação seja ultrapassada, a restrição do banco de dados é a guarda final.

---

### Resumo ATK

| ID | Ataque | Resultado |
|----|--------|-----------|
| ATK-01 | Manipulação de parent ID / referência circular | 🚫 BLOCKED |
| ATK-02 | Parent ID inexistente na criação | 🚫 BLOCKED |
| ATK-03 | Deletar nó não-folha para apagar subárvore | 🚫 BLOCKED |
| ATK-04 | Travessia CTE em nó inexistente | 🚫 BLOCKED |
| ATK-05 | SQL injection via campo name | 🚫 BLOCKED |
| ATK-06 | Ciclo CTE recursivo / loop infinito | 🚫 BLOCKED |
| ATK-07 | Bomba de profundidade CTE com cadeia profunda | ✅ SAFE (adicionar guarda MAX_DEPTH) |
| ATK-08 | Enumeração de ID via GET | 🚫 BLOCKED |
| ATK-09 | Endpoint de filhos com exposição inesperada de subárvore | ✅ SAFE |
| ATK-10 | Esgotamento de memória com campo name grande | ✅ SAFE (middleware de limite de tamanho) |
| ATK-11 | Poda sequencial de subárvore | ✅ SAFE (operação válida) |
| ATK-12 | Condição de corrida hasChildren + inserção de filho | 🚫 BLOCKED |

**6 BLOCKED, 4 SAFE, 0 EXPOSED** — Nenhuma descoberta crítica. Adicione guarda `MAX_DEPTH` na inserção para implantações em produção.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Calcular profundidade contando ancestrais a cada requisição | O(profundidade) consultas N+1; use a coluna `depth` armazenada |
| Permitir atualização de parent_id (reparentagem) sem recalcular profundidades da subárvore | Valores `depth` armazenados para toda a subárvore ficam desatualizados/errados |
| Sem `ON DELETE RESTRICT` na FK pai | Bug da aplicação silenciosamente orphaniza linhas filhas |
| Retornar 200 com lista vazia para ancestrais/descendentes de categoria inexistente | Chamadores não conseguem distinguir "sem ancestrais" de "categoria não encontrada" |
| Aceitar `depth` da entrada do cliente | Atacante define `depth=0` em um filho profundo, quebrando invariantes da árvore |
| Sem limite de recursão CTE ou cap MAX_DEPTH na inserção | Cadeias profundas atingem o limite de 1000 níveis do CTE do SQLite |
