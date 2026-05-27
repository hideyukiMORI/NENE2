# Como Fazer: API de Rastreamento de Despesas

> **Referência FT**: FT311 (`NENE2-FT/expenselog`) — Rastreamento de despesas: validação de formato de data YYYY-MM-DD, categoria como string (aberta, sem enum), agregação de resumo mensal por categoria, paginação por offset com limit/offset, atualização parcial PATCH (apenas campos fornecidos alterados), filtro por intervalo de data, rota estática `/summary` antes da dinâmica `/{id}`, 34 testes / 67 asserções PASS.

Este guia mostra como construir uma API de rastreamento de despesas com filtragem por data, agregação por categoria, paginação e atualizações parciais.

## Schema

```sql
CREATE TABLE IF NOT EXISTS expenses (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    date        TEXT    NOT NULL,  -- YYYY-MM-DD
    amount      INTEGER NOT NULL,  -- centavos
    category    TEXT    NOT NULL,
    note        TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_expenses_date     ON expenses (date);
CREATE INDEX IF NOT EXISTS idx_expenses_category ON expenses (category);
```

Índices em `date` e `category` suportam filtragem rápida. `amount` em centavos inteiros evita problemas de precisão de ponto flutuante.

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `GET` | `/expenses` | Listar com filtros opcionais + paginação |
| `POST` | `/expenses` | Criar despesa |
| `GET` | `/expenses/summary` | Agregação mensal por categoria |
| `GET` | `/expenses/{id}` | Obter despesa única |
| `PATCH` | `/expenses/{id}` | Atualização parcial |
| `DELETE` | `/expenses/{id}` | Deletar |

**Ordem das rotas**: `/expenses/summary` deve ser registrada **antes** de `/expenses/{id}` — caso contrário `summary` é capturado como parâmetro `id`.

## Validação de data — YYYY-MM-DD

```php
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $errors[] = new ValidationError('date', 'date must be in YYYY-MM-DD format.', 'invalid_format');
}
```

Apenas o formato estrito `YYYY-MM-DD` é aceito. Strings ISO 8601 com componentes de hora ou offsets de fuso horário são rejeitadas.

## Categoria — string aberta (sem enum)

```php
$category = isset($body['category']) && is_string($body['category']) && $body['category'] !== ''
    ? $body['category']
    : null;
if ($category === null) {
    $errors[] = new ValidationError('category', 'category is required.', 'required');
}
```

Categorias são strings de forma livre (não um enum fechado). Qualquer string não vazia é válida, permitindo categorias como `"food"`, `"transport"`, `"entertainment"` sem mudanças de schema.

## Resumo mensal — formato YYYY-MM

```php
// Consulta: SELECT category, SUM(amount), COUNT(*) WHERE date LIKE 'YYYY-MM-%'
$summary = $this->repository->monthlySummary($month);

return $this->json->create([
    'month'       => $summary->month,
    'total'       => $summary->total,
    'count'       => $summary->count,
    'by_category' => $summary->byCategory, // [{category, total, count}, ...]
]);
```

Formato do parâmetro de mês: `YYYY-MM`. Agrega todas as despesas naquele mês, agrupadas por categoria.

## Paginação — baseada em offset

```php
$pagination = PaginationQueryParser::parse($request);
// Retorna: { limit: int, offset: int }

$items = $this->repository->findAll($from, $to, $category, $pagination->limit, $pagination->offset);
$total = $this->repository->count($from, $to, $category);

return $this->json->create([
    'items'  => $items,
    'total'  => $total,
    'limit'  => $pagination->limit,
    'offset' => $pagination->offset,
]);
```

`limit` inválido (não inteiro, negativo, muito grande) → 422.

## PATCH — Atualização parcial

```php
// Atualizar apenas campos que estão presentes no corpo
$date     = array_key_exists('date', $body)     ? $body['date']     : null;
$amount   = array_key_exists('amount', $body)   ? $body['amount']   : null;
$category = array_key_exists('category', $body) ? $body['category'] : null;
$note     = array_key_exists('note', $body)     ? $body['note']     : null;
```

`array_key_exists()` distingue "campo não fornecido" de "campo fornecido como null". Apenas os campos fornecidos são validados e atualizados.

## Filtro por intervalo de data

```php
// GET /expenses?date_from=2026-01-01&date_to=2026-01-31
$from = QueryStringParser::string($request, 'date_from');
$to   = QueryStringParser::string($request, 'date_to');
$category = QueryStringParser::string($request, 'category');

$items = $this->repository->findAll($from, $to, $category, $limit, $offset);
```

Todos os parâmetros de filtro são opcionais. Null significa "sem filtro nessa dimensão".

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Registrar `/expenses/{id}` antes de `/expenses/summary` | `"summary"` corresponde como `id`; endpoint de resumo inacessível |
| Armazenar `amount` como FLOAT | Precisão de ponto flutuante: `0.1 + 0.2 ≠ 0.3`; use centavos inteiros |
| Aceitar qualquer string de data (ISO 8601 com hora) | Comparação de data inconsistente em cláusulas WHERE |
| Enum fechado de categoria | Novas categorias exigem migração de schema |
| `isset($body['field'])` para PATCH | `isset()` retorna false para `null`; use `array_key_exists()` |
| Consulta de contagem sem os mesmos filtros da lista | Total de paginação não corresponde à contagem filtrada real |
| Sem índice em date/category | Full table scan em cada requisição de lista filtrada |
