# Defesa contra Injeção SQL

Os métodos de banco de dados do NENE2 (`execute`, `insert`, `fetchOne`, `fetchAll`) usam prepared statements PDO internamente. Qualquer valor passado no array `$parameters` é vinculado como parâmetro PDO — nunca interpolado na string SQL.

## Seguro por Padrão: Parâmetros de Valor

```php
// Todos os valores passam pela vinculação PDO — seguro contra injeção independentemente do conteúdo
$product = $this->db->fetchOne(
    'SELECT * FROM products WHERE id = ?',
    [$userId],
);

// Busca LIKE — wildcard no literal SQL, valor vinculado separadamente
$rows = $this->db->fetchAll(
    "SELECT * FROM products WHERE name LIKE '%' || ? || '%'",
    [$searchQuery],
);
```

Payloads clássicos (`' OR '1'='1`, `'; DROP TABLE products; --`, `UNION SELECT ...`) se tornam strings de busca literais porque o PDO nunca os interpola no SQL.

## A Armadilha do ORDER BY — Allowlist Obrigatória

**O PDO não pode parametrizar nomes de coluna ou elementos estruturais do SQL.** `ORDER BY ?` não funciona — ele vincula um valor de string literal, não uma referência de coluna.

Se um desenvolvedor coloca entrada do usuário diretamente em `ORDER BY`, isso se torna um vetor de injeção:

```php
// INSEGURO — nunca faça isso
$sort = QueryStringParser::string($request, 'sort') ?? 'id';
$rows = $this->db->fetchAll("SELECT * FROM products ORDER BY {$sort} ASC");
// ?sort=id;+DROP+TABLE+products;+-- executa o DROP
```

**Sempre valide contra uma allowlist explícita antes de interpolar nomes de coluna:**

```php
private const array ALLOWED_SORT_FIELDS = ['id', 'name', 'price', 'created_at'];

public function list(string $sortField, string $sortDir): array
{
    if (!in_array($sortField, self::ALLOWED_SORT_FIELDS, true)) {
        throw new InvalidSortFieldException("Campo de ordenação inválido: {$sortField}");
    }

    // Apenas ASC ou DESC — normalizar, nunca interpolar entrada bruta do usuário
    $dir  = strtolower($sortDir) === 'desc' ? 'DESC' : 'ASC';
    $rows = $this->db->fetchAll(
        "SELECT * FROM products ORDER BY {$sortField} {$dir}",
    );

    return $rows;
}
```

O mesmo princípio se aplica a qualquer elemento estrutural SQL: nomes de tabela, nomes de coluna em `GROUP BY`, `HAVING`, `INSERT INTO ... (col1, col2)` — nenhum desses pode ser vinculado como parâmetros PDO. Valide contra allowlist antes de interpolar.

## Cláusula IN com Comprimento Variável

O PDO não suporta a vinculação de uma lista de comprimento variável diretamente. Construa a lista de placeholders explicitamente:

```php
$ids          = [1, 2, 3];
$placeholders = implode(', ', array_fill(0, count($ids), '?'));
$rows         = $this->db->fetchAll(
    "SELECT * FROM products WHERE id IN ({$placeholders})",
    $ids,
);
```

## Resumo

| Tipo de entrada | Método seguro |
|---|---|
| Valor de filtro (`WHERE col = ?`) | Placeholder `?` em `$parameters` |
| Valor LIKE | `'%' \|\| ? \|\| '%'` — valor em `$parameters` |
| Coluna ORDER BY | Allowlist `in_array` + interpolar somente após passar |
| Direção ORDER | Normalizar para literal `'ASC'` ou `'DESC'` |
| Lista IN | Construir placeholders `?` a partir de `count()`, espalhar array como params |
| Nome de tabela/coluna | Somente allowlist — nunca aceitar da entrada do usuário |
