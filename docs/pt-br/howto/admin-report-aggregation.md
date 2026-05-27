# Como Adicionar Agregação de Relatórios Admin

Construa endpoints de agregação estilo dashboard com filtros de intervalo de datas, agrupamento e limitação de resultados.

## Schema

```sql
CREATE TABLE orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id TEXT NOT NULL, item_name TEXT NOT NULL,
    amount INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK(status IN ('pending','completed','refunded','cancelled')),
    created_at TEXT NOT NULL
);
```

## Rotas

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/orders` | Inserir pedido |
| `GET` | `/reports/summary` | Total de pedidos, receita, média, contagem de concluídos |
| `GET` | `/reports/daily` | Pedidos agrupados por data |
| `GET` | `/reports/by-status` | Pedidos agrupados por status |
| `GET` | `/reports/top-items` | Top N itens por receita |

Parâmetros de query (todos os relatórios): `from=YYYY-MM-DD`, `to=YYYY-MM-DD`

## Filtro de Intervalo de Datas (Parametrizado e Seguro)

Construa a cláusula WHERE dinamicamente, passe valores como parâmetros vinculados — nunca interpole:

```php
private function dateFilter(?string $from, ?string $to): array
{
    $conditions = [];
    $params     = [];
    if ($from !== null) { $conditions[] = 'created_at >= ?'; $params[] = $from; }
    if ($to !== null)   { $conditions[] = 'created_at <= ?'; $params[] = $to; }
    $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
    return [$where, $params];
}
```

## Validação de Datas (Proteção contra Injeção)

Rejeite datas que não estejam no formato ISO-8601 antes de chegarem à consulta:

```php
private function isValidDate(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date; // rejeitar 2026-13-01
}
```

Rejeitar `from > to`:

```php
if ($from !== null && $to !== null && $from > $to) {
    $errors[] = new ValidationError('from', 'from must be before or equal to to', 'invalid');
}
```

## Limitação de Resultados

```php
private const int MAX_LIMIT = 100;

$limit = min((int) $q['limit'], self::MAX_LIMIT);
if ($limit <= 0) { /* 422 */ }
```

## Consultas de Agregação

**Resumo**:
```sql
SELECT COUNT(*) AS total_orders,
       COALESCE(SUM(amount), 0) AS total_revenue,
       COALESCE(AVG(amount), 0) AS avg_order_value,
       COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed_orders
FROM orders {where}
```

**Detalhamento diário** (substring de data SQLite):
```sql
SELECT substr(created_at, 1, 10) AS date, COUNT(*) AS order_count, SUM(amount) AS revenue
FROM orders {where}
GROUP BY date ORDER BY date ASC
```

**Top itens**:
```sql
SELECT item_name, COUNT(*) AS order_count, SUM(amount) AS revenue
FROM orders {where}
GROUP BY item_name ORDER BY revenue DESC LIMIT ?
```

## Notas de Segurança

- Todos os parâmetros de query são validados antes do uso — SQL injection via `from`/`to`/`limit` é rejeitado com 422.
- Nomes de itens e IDs de clientes são armazenados via consultas parametrizadas — caracteres especiais e tentativas de injeção são strings literais.
- `COALESCE(SUM(...), 0)` previne NULL em resumos quando nenhuma linha corresponde.
- Limite restrito a `MAX_LIMIT` — previne esgotamento de recursos por valores `LIMIT` enormes.
