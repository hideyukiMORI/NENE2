# Como Fazer: Ordenação e Filtro Dinâmicos com Prevenção de Injeção em ORDER BY

> **Referência FT**: FT341 (`NENE2-FT/sortlog`) — API de ordenação/filtro dinâmico com prevenção de injeção SQL em ORDER BY via lista de permissões, lista de permissões de filtro de status, validação O(n) imune a ReDoS, mais de 40 testes cobrindo VULN-A a VULN-L e ATK-01 a ATK-12, todos PASS.

Este guia mostra como implementar um endpoint de listagem ordenável e filtrável com segurança. Como `ORDER BY` não pode usar placeholders parametrizados em SQL, a coluna e a direção devem ser validadas contra uma lista de permissões estrita antes da interpolação.

## Schema

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    body       TEXT    NOT NULL,
    status     TEXT    NOT NULL DEFAULT 'draft',
    created_at TEXT    NOT NULL
);
```

## Endpoint

```
GET /articles?sort=created_at&order=desc&status=published&limit=20
```

### Parâmetros válidos

| Param | Valores permitidos | Padrão |
|-------|-------------------|--------|
| `sort` | `id`, `title`, `status`, `created_at` | `created_at` |
| `order` | `asc`, `desc` | `desc` |
| `status` | `draft`, `published`, `archived` | (todos) |
| `limit` | 1–100 | 20 |

## Resposta

```php
GET /articles?sort=title&order=asc&status=published
→ 200
{
  "data": [
    {"id": 2, "title": "Alpha", "status": "published", ...},
    {"id": 1, "title": "Beta",  "status": "published", ...}
  ],
  "total": 2,
  "sort": "title",
  "order": "asc"
}
```

## Validação por lista de permissões — o único padrão seguro

Cláusulas `ORDER BY` **não podem usar valores bind parametrizados**. O nome da coluna deve ser interpolado diretamente no SQL. Isso torna a validação por lista de permissões obrigatória.

```php
private const SORT_COLUMNS = ['id', 'title', 'status', 'created_at'];
private const SORT_ORDERS  = ['asc', 'desc'];
private const STATUSES     = ['draft', 'published', 'archived'];

public function parseSort(string $sort, string $order): array
{
    // Correspondência exata de string na lista de permissões — O(n), case-sensitive, sem regex
    if (!in_array($sort, self::SORT_COLUMNS, true)) {
        throw new ValidationException("Invalid sort column: {$sort}");
    }
    if (!in_array($order, self::SORT_ORDERS, true)) {
        throw new ValidationException("Invalid sort direction: {$order}");
    }
    return [$sort, $order];
}

public function parseStatus(?string $status): ?string
{
    if ($status === null) {
        return null;  // sem filtro
    }
    if (!in_array($status, self::STATUSES, true)) {
        throw new ValidationException("Invalid status: {$status}");
    }
    return $status;
}
```

**Por que `in_array()` em vez de regex:**
- `in_array($v, $list, true)` é O(n) — imune a ReDoS
- Regex `/^[a-z_]+$/` em payloads de 50 chars controlados por atacante pode causar retrocesso catastrófico
- Terceiro argumento estrito (`true`) habilita comparação type-safe

### Sensibilidade a maiúsculas/minúsculas

A lista de permissões é case-sensitive por design:

```php
GET /articles?sort=ID       → 422  // 'ID' não está na lista de permissões
GET /articles?sort=TITLE    → 422
GET /articles?sort=Created_At → 422
GET /articles?sort=created_at → 200  ✅ correspondência exata
```

## Construção da consulta

```php
$sql = 'SELECT * FROM articles';

if ($status !== null) {
    // Status usa um placeholder parametrizado (seguro)
    $sql .= ' WHERE status = ?';
    $params[] = $status;
}

// Coluna e direção de ordenação vêm da lista de permissões — seguro para interpolar
$sql .= " ORDER BY {$sort} {$order}";
$sql .= ' LIMIT ?';
$params[] = $limit;
```

`ORDER BY` usa valores interpolados da lista de permissões; valores da cláusula `WHERE` sempre usam placeholders `?`.

## Payloads rejeitados

### Padrões de injeção → 422

```php
// Injeção SQL em sort
?sort='; DROP TABLE articles--             → 422
?sort=id UNION SELECT 1,2,3,4,5           → 422
?sort=(SELECT name FROM sqlite_master)    → 422
?sort=CASE WHEN 1=1 THEN id ELSE title END → 422
?sort=created_at--                        → 422  // comentário
?sort=created_at%00                       → 422  // byte nulo

// Injeção de direção
?order=asc; UNION SELECT 1,2,3--          → 422
?order=DESC;                              → 422

// Injeção de filtro de status
?status=' OR '1'='1                       → 422
?status=draft UNION SELECT 1,2--          → 422
?status=1                                 → 422  // deve ser nome de status exato
?status=TRUE                              → 422

// Bypass com espaço em branco
?sort=created_at%09                       → 422  // TAB
?sort= created_at                         → 422  // espaço à esquerda

// Injeção de array (PSR-7)
?sort[]=created_at                        → 422  // array, não string
```

### Injeção em limit → 422

```php
?limit=999999           → 422  // excede MAX_LIMIT=100
?limit=9999999999999999999999  → 422  // overflow (strlen > 18)
?limit=-1               → 422  // negativo
?limit=10.5             → 422  // float
```

### Requisições válidas → 200

```php
GET /articles                                  → 200  // padrões
GET /articles?sort=title&order=asc             → 200
GET /articles?sort=id&order=desc&status=draft  → 200
GET /articles?limit=50                         → 200
```

## Segurança de temporização

Cada rejeição é instantânea (<100ms). A verificação na lista de permissões usa `in_array()` que faz short-circuit na primeira não-correspondência — sem retrocesso de regex:

```php
// Payload ReDoS: "aaaa...a!" (50 a's + '!')
// in_array("aaaa...a!", ['id','title','status','created_at'], true) → false imediatamente
```

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Interpolar `?sort=` diretamente: `ORDER BY $sort` | SQL injection — atacante controla cláusula `ORDER BY` completamente |
| Validar com regex `/^[a-z_]+$/` apenas | ReDoS em payloads de 50+ chars; permite nomes de colunas desconhecidos como `password` |
| Comparação case-insensitive (`strcasecmp`) | `ORDER BY CREATED_AT` é SQL válido mas contorna testes case-sensitive |
| Parametrizar `ORDER BY $sort` como valor bind | A maioria dos drivers de DB o trata silenciosamente como literal ou lança um erro |
| Lista de permissões apenas para `sort`, não para direção `order` | `order=asc; UNION SELECT ...` contorna a verificação de coluna |
| Confiar em array `sort[]` após parsing PSR-7 | `implode(', ', $sort)` com injeção de array produz ORDER BY multi-coluna |
| Omitir lista de permissões do filtro `status` | `status=admin' OR '1'='1` vira `WHERE status = 'admin' OR '1'='1'` |
