# Como Fazer: Defesa contra Injeção SQL

> **Referência FT**: FT264 (`NENE2-FT/injectionlog`) — Defesa contra injeção SQL: queries parametrizadas, injeção LIKE, allowlist ORDER BY
> **ATK**: FT264 — teste de ataque com mentalidade de cracker (ATK-01 a ATK-12)

Demonstra os três principais vetores de injeção SQL em uma API PHP — injeção de valor, injeção de wildcard LIKE e injeção de coluna ORDER BY — e a defesa correta para cada um. Inclui uma avaliação completa de ataque com mentalidade de cracker.

---

## Rotas

| Método   | Caminho            | Descrição                                  |
|----------|--------------------|--------------------------------------------|
| `GET`    | `/products`        | Listar/buscar produtos (filtrável, ordenável) |
| `POST`   | `/products`        | Criar um produto                           |
| `GET`    | `/products/{id}`   | Obter um único produto                     |
| `DELETE` | `/products/{id}`   | Excluir um produto                         |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS products (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL,
    category    TEXT    NOT NULL,
    price       REAL    NOT NULL DEFAULT 0.0,
    description TEXT    NOT NULL DEFAULT ''
);
```

---

## As Três Superfícies de Injeção SQL

### 1. Injeção de valor: queries parametrizadas

```php
// ❌ Interpolação de string — injetável
$rows = $db->fetchAll("SELECT * FROM products WHERE id = {$id}");

// ✅ Parametrizado — o driver escapa todos os valores
$rows = $db->fetchAll('SELECT * FROM products WHERE id = ?', [$id]);
```

O placeholder `?` do PDO vincula o valor como um parâmetro tipado. O valor nunca é interpolado na string SQL. Um atacante que envia `id = "1; DROP TABLE products; --"` tem toda a sua entrada armazenada como uma vinculação de string literal — o SQL não é modificado.

### 2. Injeção de wildcard LIKE: wildcards parametrizados

```php
// ❌ LIKE interpolado — injetável E com escape de wildcard
$rows = $db->fetchAll("SELECT * FROM products WHERE name LIKE '%{$q}%'");

// ✅ Wildcard parametrizado — o valor ? é vinculado após a concatenação ||
$rows = $db->fetchAll(
    "SELECT * FROM products WHERE name LIKE '%' || ? || '%' OR description LIKE '%' || ? || '%'",
    [$q, $q],
);
```

`'%' || ? || '%'` é concatenação de string SQL padrão (SQLite, PostgreSQL). O valor `?` é vinculado como parâmetro — os wildcards `%` são literais na string SQL, não vindos da entrada do usuário.

**Escape de metacaracteres LIKE**: `%` e `_` dentro do `$q` do usuário NÃO são escapados nesta implementação. Uma busca por `%` corresponderia a tudo. Para produção, escape os metacaracteres LIKE:

```php
$escaped = str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $q);
$rows = $db->fetchAll("... WHERE name LIKE '%' || ? || '%' ESCAPE '\\'", [$escaped, $escaped]);
```

### 3. Injeção em ORDER BY: allowlist de coluna

```php
private const array ALLOWED_SORT_FIELDS = ['id', 'name', 'category', 'price'];

public function search(string $query = '', string $sortField = 'id', string $sortDir = 'asc'): array
{
    if (!in_array($sortField, self::ALLOWED_SORT_FIELDS, true)) {
        throw new InvalidSortFieldException("Campo de ordenação inválido: {$sortField}");
    }

    $sortDir    = strtolower($sortDir) === 'desc' ? 'DESC' : 'ASC';
    $sortClause = $sortField . ' ' . $sortDir;   // seguro: coluna da allowlist + direção da whitelist

    $rows = $db->fetchAll(
        "SELECT * FROM products ORDER BY {$sortClause}",
    );
}
```

`ORDER BY` não pode usar placeholders parametrizados — o nome da coluna deve ser interpolado. A defesa correta é uma allowlist explícita: apenas valores em `ALLOWED_SORT_FIELDS` podem aparecer na string SQL. Qualquer outro valor lança uma exceção (400 no controller).

`sortDir` é mapeado para exatamente `'ASC'` ou `'DESC'` — a entrada do usuário nunca é interpolada diretamente.

---

## ATK — Teste de Ataque com Mentalidade de Cracker (FT264)

### ATK-01 — Injeção SELECT Clássica via Parâmetro GET

**Ataque**: Injetar SQL via query de busca `?q=' OR '1'='1`.

```
GET /products?q=' OR '1'='1
```

**Observado**: `$q` é vinculado como parâmetro `?` em `LIKE '%' || ? || '%'`. A string inteira `' OR '1'='1` é tratada como um valor de texto literal para corresponder. Nenhuma linha adicional é retornada.

**Veredicto**: **BLOCKED** — LIKE parametrizado previne injeção de valor.

---

### ATK-02 — Injeção DROP TABLE via Busca

**Ataque**: Injetar uma instrução destrutiva.

```
GET /products?q='; DROP TABLE products; --
```

**Observado**: O payload é vinculado como um padrão LIKE. `'; DROP TABLE products; --` é buscado como texto literal. A tabela não é deletada.

**Veredicto**: **BLOCKED** — queries parametrizadas não podem executar instruções injetadas.

---

### ATK-03 — Injeção em ORDER BY: coluna arbitrária

**Ataque**: Injetar uma coluna de ordenação não reconhecida.

```
GET /products?sort=password
```

**Observado**: `in_array('password', self::ALLOWED_SORT_FIELDS, true)` retorna `false`. `InvalidSortFieldException` é lançada. O controller a captura e retorna 400.

**Veredicto**: **BLOCKED** — allowlist de coluna rejeita nomes de coluna desconhecidos.

---

### ATK-04 — Injeção em ORDER BY: injeção de subquery

**Ataque**: Injetar uma subquery como coluna de ordenação.

```
GET /products?sort=(SELECT%20name%20FROM%20users%20LIMIT%201)
```

**Observado**: O valor decodificado `(SELECT name FROM users LIMIT 1)` não está em `ALLOWED_SORT_FIELDS`. `InvalidSortFieldException` lançada. 400 retornado.

**Veredicto**: **BLOCKED** — allowlist rejeita qualquer valor não presente na lista de colunas conhecidas, incluindo subqueries.

---

### ATK-05 — Injeção em ORDER BY: adulteração de direção

**Ataque**: Injetar SQL via parâmetro de direção de ordenação.

```
GET /products?order=DESC;%20DROP%20TABLE%20products;--
```

**Observado**: `strtolower($sortDir) === 'desc'` é `false` para o valor injetado. A direção cai para `'ASC'`. O SQL injetado nunca é interpolado. 200 retornado com produtos ordenados ASC.

**Veredicto**: **BLOCKED** — direção é mapeada para exatamente `'ASC'` ou `'DESC'`, nunca interpolada.

---

### ATK-06 — Injeção UNION via query de busca

**Ataque**: Injetar um `UNION SELECT` para exfiltrar dados.

```
GET /products?q=' UNION SELECT id,name,email,password,'' FROM users --
```

**Observado**: A string de injeção completa é vinculada como valor de parâmetro LIKE. O `UNION SELECT` é buscado como texto literal nas colunas `name` e `description`. Nenhum dado de usuário é retornado.

**Veredicto**: **BLOCKED** — query parametrizada previne injeção UNION.

---

### ATK-07 — Injeção de ID via parâmetro de caminho

**Ataque**: Injetar SQL via parâmetro de caminho.

```
GET /products/1;%20DROP%20TABLE%20products;
```

**Observado**: O parâmetro de caminho `{id}` é convertido para `int` por `(int) $params['id']`. O SQL se torna `WHERE id = 1` — o sufixo de injeção é truncado pela conversão. A tabela não é deletada.

**Veredicto**: **BLOCKED** — conversão `(int)` trunca no primeiro caractere não-dígito.

---

### ATK-08 — Injeção blind baseada em booleano via busca

**Ataque**: Vazar dados via condições booleanas.

```
GET /products?q=' AND '1'='1
GET /products?q=' AND '1'='2
```

**Observado**: Ambas as strings são vinculadas como parâmetros LIKE. Ambas retornam produtos cujo nome ou descrição contém o texto literal `' AND '1'='1`. Nenhuma query modifica a lógica WHERE do SQL. Ambas retornam o mesmo conjunto de resultados (vazio).

**Veredicto**: **BLOCKED** — vinculação parametrizada previne injeção booleana.

---

### ATK-09 — Injeção de segunda ordem: payload armazenado recuperado depois

**Ataque**: Criar um produto com um nome contendo SQL, depois buscar todos os produtos.

```json
POST /products {"name": "'; DROP TABLE products; --", "category": "test", "price": 1}
GET /products
```

**Observado**: O `INSERT` usa `?` parametrizado — o payload de injeção é armazenado como texto literal. As queries `SELECT *` e `LIKE` também usam queries parametrizadas. O payload é retornado como valor de string, nunca executado como SQL.

**Veredicto**: **BLOCKED** — todos os caminhos de leitura e escrita usam queries parametrizadas.

---

### ATK-10 — Flood de metacaractere LIKE: busca por `%`

**Ataque**: Enviar `?q=%` para corresponder a todos os produtos, contornando um padrão de busca vazia intencional.

```
GET /products?q=%25   (decodificado pela URL: %)
```

**Observado**: `$q = '%'` é vinculado como parâmetro LIKE. `LIKE '%' || '%' || '%'` = `LIKE '%%%'` que corresponde a todas as linhas. Todos os produtos são retornados.

**Veredicto**: **EXPOSED** — `%` e `_` na entrada do usuário não são escapados. Uma busca por `%` corresponde a tudo; uma busca por `_` corresponde a qualquer único caractere. Escape os metacaracteres LIKE ou documente o comportamento como intencional.

---

### ATK-11 — Injeção de byte nulo

**Ataque**: Incorporar um byte nulo na query de busca.

```
GET /products?q=widget%00extra
```

**Observado**: A vinculação `?` do PHP passa a string bruta incluindo o byte nulo para a query parametrizada do SQLite. O SQLite trata o byte nulo como parte da string. `LIKE '%widget\0extra%'` não corresponde a nomes normais de produtos. Nenhuma injeção ocorre.

**Veredicto**: **BLOCKED** — queries parametrizadas tratam bytes nulos como conteúdo literal de string.

---

### ATK-12 — Queries empilhadas (injeção multi-instrução)

**Ataque**: Injetar uma segunda instrução após um ponto e vírgula.

```
GET /products?q=test'; INSERT INTO products VALUES (99,'hacked','x',0,''); --
```

**Observado**: PDO executa apenas uma instrução por chamada `query()`/`prepare()` — queries empilhadas não são suportadas por padrão. Mesmo que PDO permitisse múltiplas instruções, o valor é vinculado como parâmetro (não interpolado). O INSERT injetado é armazenado como texto literal de busca LIKE.

**Veredicto**: **BLOCKED** — vinculação parametrizada + modo de instrução única do PDO previnem queries empilhadas.

---

## Resumo ATK

| # | Vetor de ataque | Veredicto |
|---|---|---|
| ATK-01 | Injeção SELECT clássica via `?q=` | BLOCKED |
| ATK-02 | DROP TABLE via busca | BLOCKED |
| ATK-03 | ORDER BY coluna desconhecida | BLOCKED |
| ATK-04 | Injeção de subquery em ORDER BY | BLOCKED |
| ATK-05 | Injeção na direção de ordenação | BLOCKED |
| ATK-06 | UNION SELECT via busca | BLOCKED |
| ATK-07 | Injeção de ID via parâmetro de caminho | BLOCKED |
| ATK-08 | Injeção blind baseada em booleano | BLOCKED |
| ATK-09 | Injeção de segunda ordem | BLOCKED |
| ATK-10 | Flood de metacaractere LIKE (`%`) | EXPOSED |
| ATK-11 | Injeção de byte nulo | BLOCKED |
| ATK-12 | Queries empilhadas | BLOCKED |

**Vulnerabilidades reais a corrigir antes de produção**:
1. **ATK-10** — Escapar metacaracteres LIKE (`%`, `_`, `\`) antes de vincular para prevenir flood de wildcard.

---

## Resumo de Defesa

| Superfície | Padrão vulnerável | Padrão seguro |
|---|---|---|
| Valor em WHERE | `WHERE id = {$id}` | `WHERE id = ?` com `[$id]` |
| Busca LIKE | `WHERE name LIKE '%{$q}%'` | `WHERE name LIKE '%' \|\| ? \|\| '%'` |
| Coluna ORDER BY | `ORDER BY {$sortField}` | `in_array($sortField, ALLOWED, true)` + interpolar |
| Direção ORDER BY | `ORDER BY col {$dir}` | `$dir === 'desc' ? 'DESC' : 'ASC'` |
| ID parâmetro de caminho | `WHERE id = {$id}` | `(int) $id` + parametrizado |

---

## Howtos Relacionados

- [`mass-assignment-defence.md`](mass-assignment-defence.md) — whitelist explícita de DTO como padrão de defesa mais amplo
- [`sqlite-fts5-search.md`](sqlite-fts5-search.md) — FTS5 como alternativa ao LIKE para busca full-text
- [`jwt-authentication.md`](jwt-authentication.md) — avaliação VULN incluindo injeção SQL (V-08)
