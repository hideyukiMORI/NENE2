# Como Fazer: Consulta com Filtro Dinâmico (Cláusula WHERE Dinâmica)

> **Cenários relacionados**: DX Scenario 03, 18, 22, 25, 29, 30, 33, 37, 38, 41, 47, 48 — o howto mais frequentemente citado como ausente nos 50 cenários DX.

Muitos endpoints de listagem aceitam parâmetros de consulta opcionais que se traduzem em condições SQL.
O principal desafio: quando um parâmetro está ausente (`null`), a condição deve ser **completamente omitida** — não comparada contra `NULL` no SQL.

Este guia mostra o padrão canônico usado nos howtos do NENE2.

---

## O Padrão Central: array `$conditions` + `implode`

```php
public function search(
    ?string $status,
    ?int    $categoryId,
    ?string $keyword,
): array {
    $conditions = ['deleted_at IS NULL'];   // condição obrigatória — sempre incluída
    $bindings   = [];

    if ($status !== null) {
        $conditions[] = 'status = ?';
        $bindings[]   = $status;
    }

    if ($categoryId !== null) {
        $conditions[] = 'category_id = ?';
        $bindings[]   = $categoryId;
    }

    if ($keyword !== null && $keyword !== '') {
        $conditions[] = '(title LIKE ? OR description LIKE ?)';
        $bindings[]   = "%{$keyword}%";
        $bindings[]   = "%{$keyword}%";
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);

    return $this->db->fetchAll(
        "SELECT * FROM products {$where} ORDER BY created_at DESC",
        $bindings,
    );
}
```

**Por que isso funciona**:
- `$conditions` sempre tem pelo menos um elemento (a condição obrigatória), então `implode(' AND ', $conditions)` nunca produz uma string vazia.
- Cada bloco opcional acrescenta tanto o fragmento SQL quanto seu valor de binding — eles ficam em sincronia.
- Se todos os parâmetros opcionais forem `null`, a consulta reduz a `WHERE deleted_at IS NULL`.

---

## Anti-padrão: `WHERE 1=1`

Uma alternativa comum é `WHERE 1=1` como condição inicial, e então sempre acrescentar `AND`:

```php
// Funciona, mas menos claro:
$where = 'WHERE 1=1';
if ($status !== null) {
    $where    .= ' AND status = ?';
    $bindings[] = $status;
}
```

Isso também funciona. A abordagem com array `$conditions` é preferida porque separa
fragmentos SQL de seus bindings de forma limpa e é mais fácil de testar cada condição isoladamente.

---

## Condições de intervalo: filtros de mínimo/máximo

Intervalo de preço, intervalo de data e filtros similares com `>=` / `<=`:

```php
public function searchProperties(
    ?int    $priceMin,
    ?int    $priceMax,
    ?int    $bedroomsMin,
    ?string $dateFrom,
    ?string $dateTo,
): array {
    $conditions = ['status = ?'];
    $bindings   = ['available'];

    if ($priceMin !== null) {
        $conditions[] = 'price_yen >= ?';
        $bindings[]   = $priceMin;
    }

    if ($priceMax !== null) {
        $conditions[] = 'price_yen <= ?';
        $bindings[]   = $priceMax;
    }

    if ($bedroomsMin !== null) {
        $conditions[] = 'bedrooms >= ?';
        $bindings[]   = $bedroomsMin;
    }

    if ($dateFrom !== null) {
        $conditions[] = 'available_from >= ?';
        $bindings[]   = $dateFrom;
    }

    if ($dateTo !== null) {
        $conditions[] = 'available_from <= ?';
        $bindings[]   = $dateTo;
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);

    return $this->db->fetchAll(
        "SELECT * FROM properties {$where} ORDER BY price_yen ASC",
        $bindings,
    );
}
```

Use condições `min` e `max` separadas em vez de `BETWEEN` — isso permite que o cliente
forneça apenas um limite (ex.: "preço até 5M, sem limite inferior").

---

## Filtro por enum / lista de permissões

Quando o valor de um parâmetro deve vir de um conjunto fixo, valide antes de adicionar a `$conditions`:

```php
private const VALID_STATUSES = ['draft', 'published', 'archived'];

public function listByStatus(?string $status): array
{
    $conditions = ['deleted_at IS NULL'];
    $bindings   = [];

    if ($status !== null) {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }
        $conditions[] = 'status = ?';
        $bindings[]   = $status;
    }

    // ...
}
```

**Não** interpole `$status` diretamente na string SQL mesmo que pareça seguro.
Use sempre um parâmetro bind (`?`) e deixe o PDO tratar as aspas.

---

## Cláusula IN: filtro multi-valor

Quando o cliente pode passar múltiplos valores (ex.: `?category_ids[]=1&category_ids[]=3`):

```php
public function filterByCategories(array $categoryIds): array
{
    if ($categoryIds === []) {
        return $this->findAll();   // sem filtro — retornar tudo
    }

    $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));

    return $this->db->fetchAll(
        "SELECT * FROM products WHERE category_id IN ({$placeholders}) ORDER BY created_at DESC",
        $categoryIds,
    );
}
```

`array_fill(0, count($ids), '?')` gera o número correto de placeholders `?`.
Nunca use `implode(',', $categoryIds)` para construir uma string `IN (1,2,3)` — isso é SQL injection.

Para semântica AND (itens que correspondem a **todas** as tags fornecidas), veja [`multi-value-tag-filter.md`](multi-value-tag-filter.md).

---

## ORDER BY seguro: interpolação com lista de permissões

Nomes de colunas em `ORDER BY` **não podem** usar parâmetros bind — eles devem ser interpolados.
Sempre valide contra uma lista de permissões:

```php
private const SORT_COLUMNS  = ['name', 'price_yen', 'created_at'];
private const SORT_DIRECTION = ['asc', 'desc'];

public function list(?string $sortBy, ?string $order): array
{
    $col = in_array($sortBy, self::SORT_COLUMNS, true)  ? $sortBy : 'created_at';
    $dir = in_array($order,  self::SORT_DIRECTION, true) ? $order  : 'desc';

    $conditions = ['deleted_at IS NULL'];

    $where = 'WHERE ' . implode(' AND ', $conditions);

    return $this->db->fetchAll(
        "SELECT * FROM products {$where} ORDER BY {$col} {$dir}",
        [],
    );
}
```

Veja [`dynamic-sort-order-injection.md`](dynamic-sort-order-injection.md) para um tratamento completo
da prevenção de injeção em ORDER BY.

---

## Combinando filtro com paginação

Um padrão comum — filtro dinâmico + paginação por cursor ou offset:

```php
public function paginatedSearch(
    ?string $status,
    ?string $keyword,
    int     $limit,
    int     $offset,
): array {
    $conditions = ['deleted_at IS NULL'];
    $bindings   = [];

    if ($status !== null) {
        $conditions[] = 'status = ?';
        $bindings[]   = $status;
    }

    if ($keyword !== null && $keyword !== '') {
        $conditions[] = 'title LIKE ?';
        $bindings[]   = "%{$keyword}%";
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);

    // Consulta de contagem reutiliza o mesmo WHERE
    $total = (int) ($this->db->fetchOne(
        "SELECT COUNT(*) AS cnt FROM products {$where}",
        $bindings,
    )['cnt'] ?? 0);

    // Consulta de dados acrescenta LIMIT/OFFSET — NÃO adicione-os a $bindings antes do COUNT
    $rows = $this->db->fetchAll(
        "SELECT * FROM products {$where} ORDER BY created_at DESC LIMIT ? OFFSET ?",
        [...$bindings, $limit, $offset],
    );

    return ['total' => $total, 'items' => $rows];
}
```

Construa `$bindings` para as condições de filtro primeiro, depois espalhe-os em ambas a
consulta `COUNT` e a consulta de dados. Acrescente `$limit` e `$offset` apenas à consulta de dados.

---

## Parsing de parâmetros de consulta opcionais

Use os helpers `QueryStringParser` para obter valores tipados null-safe da requisição:

```php
use Nene2\Http\QueryStringParser;

$status     = QueryStringParser::string($request, 'status');     // ?string
$priceMin   = QueryStringParser::int($request, 'price_min');     // ?int
$priceMax   = QueryStringParser::int($request, 'price_max');     // ?int
$keyword    = QueryStringParser::string($request, 'q');          // ?string
$sortBy     = QueryStringParser::string($request, 'sort');       // ?string
$categoryId = QueryStringParser::int($request, 'category_id');   // ?int
```

Todos os helpers retornam `null` quando o parâmetro está ausente ou não pode ser convertido para o tipo alvo.
Passe esses valores nullable diretamente ao método do repositório — o método omite
condições onde o valor é `null`.

---

## Erros comuns

| Erro | Problema | Correção |
|------|---------|---------|
| `WHERE status = ?` com binding `null` | SQLite avalia `status = NULL` → sempre false (deveria ser `IS NULL`) | Omita a condição quando o valor for `null`; use `IS NULL` apenas quando quiser explicitamente linhas NULL |
| `WHERE 1=1` sem condição obrigatória | Vaza todas as linhas se todos os parâmetros opcionais estiverem ausentes e não houver filtro de tenant/owner | Sempre inclua pelo menos uma condição obrigatória (tenant, owner, deleted_at) |
| Interpolar `$status` diretamente | SQL injection | Use sempre o parâmetro bind `?` |
| `IN (implode(',', $ids))` | SQL injection | Use `array_fill` + placeholders `?` |
| Adicionar `LIMIT`/`OFFSET` a `$bindings` antes de `COUNT(*)` | COUNT obtém resultados errados | Construa `$bindings` de filtro primeiro; espalhe no COUNT, depois acrescente LIMIT/OFFSET para a consulta de dados |

---

## Howtos relacionados

- [`multi-value-tag-filter.md`](multi-value-tag-filter.md) — semântica AND / OR para filtros de tags N:M (`HAVING COUNT(DISTINCT)`)
- [`dynamic-sort-order-injection.md`](dynamic-sort-order-injection.md) — ORDER BY seguro com lista de permissões
- [`add-pagination.md`](add-pagination.md) — combinando com paginação por offset / cursor
- [`contact-management.md`](contact-management.md) — exemplo completo com filtro LIKE + EXISTS
