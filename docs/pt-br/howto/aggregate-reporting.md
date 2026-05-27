# Como Fazer: API de Relatórios Agregados

> **Referência FT**: FT245 (`NENE2-FT/agglog`) — API de Relatórios Agregados

Demonstra uma API de relatórios agregados multidimensional onde uma única tabela de pedidos
é segmentada em totais de resumo, detalhamento diário, distribuição de status e itens principais —
tudo com filtragem opcional por intervalo de datas, `COALESCE` para agregações seguras com zero e
`COUNT(CASE WHEN...)` para contagens condicionais sem subconsultas.

---

## Rotas

| Método | Caminho                 | Descrição                                              |
|--------|-------------------------|--------------------------------------------------------|
| `POST` | `/orders`               | Registrar um pedido                                    |
| `GET`  | `/reports/summary`      | Total de pedidos, receita, valor médio, contagem de concluídos |
| `GET`  | `/reports/daily`        | Receita e contagem de pedidos por dia                  |
| `GET`  | `/reports/by-status`    | Contagem de pedidos e receita agrupados por status     |
| `GET`  | `/reports/top-items`    | Principais itens por receita (limitado, ordenado)      |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS orders (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id TEXT    NOT NULL,
    item_name   TEXT    NOT NULL,
    amount      INTEGER NOT NULL,
    status      TEXT    NOT NULL DEFAULT 'pending'
                    CHECK(status IN ('pending', 'completed', 'refunded', 'cancelled')),
    created_at  TEXT    NOT NULL
);
```

`status` é restrito por um `CHECK` no nível do banco de dados como proteção adicional. `amount` é
armazenado como inteiro (menor unidade de moeda). `created_at` é uma string ISO — comparações de
datas usam ordenação de string no formato `YYYY-MM-DD`, que é consistente lexicograficamente
com a ordem cronológica.

---

## Agregação de resumo: `COALESCE` + `COUNT(CASE WHEN ...)`

O endpoint de resumo retorna várias métricas agregadas em uma única consulta:

```php
$row = $this->db->fetchOne(
    "SELECT COUNT(*) AS total_orders,
            COALESCE(SUM(amount), 0) AS total_revenue,
            COALESCE(AVG(amount), 0) AS avg_order_value,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed_orders
     FROM orders {$where}",
    $params,
);
```

`COALESCE(SUM(amount), 0)` — retorna `0` em vez de `NULL` quando a tabela não tem
linhas correspondentes. `SUM()` e `AVG()` retornam `NULL` em conjuntos vazios; `COALESCE` converte
isso para um zero seguro.

`COUNT(CASE WHEN status = 'completed' THEN 1 END)` — conta apenas linhas onde `status =
'completed'`, sem uma subconsulta ou segunda passagem. `CASE WHEN` retorna `NULL` para
linhas não correspondentes; `COUNT` ignora `NULL`, então apenas os pedidos concluídos são contados.

Isso é equivalente a um `COUNT` filtrado, mas executa em uma única varredura, tornando-o mais
eficiente do que consultas separadas para cada status.

---

## Detalhamento diário: `substr()` para truncamento de data

```php
$rows = $this->db->fetchAll(
    "SELECT substr(created_at, 1, 10) AS date,
            COUNT(*) AS order_count,
            SUM(amount) AS revenue
     FROM orders {$where}
     GROUP BY date
     ORDER BY date ASC",
    $params,
);
```

`substr(created_at, 1, 10)` extrai os primeiros 10 caracteres (`YYYY-MM-DD`) da
string ISO datetime, agrupando todos os eventos no mesmo dia do calendário. Esta é uma
alternativa ao `strftime('%Y-%m-%d', created_at)` do SQLite para strings de timestamp no
formato ISO 8601 com um prefixo fixo.

`GROUP BY date` usa o alias — o SQLite suporta aliasing em `GROUP BY` (ao contrário de alguns
outros bancos de dados que exigem repetir a expressão).

---

## Distribuição por status: `GROUP BY status ORDER BY count DESC`

```php
$rows = $this->db->fetchAll(
    "SELECT status, COUNT(*) AS order_count, SUM(amount) AS revenue
     FROM orders {$where}
     GROUP BY status
     ORDER BY order_count DESC",
    $params,
);
```

`ORDER BY order_count DESC` coloca o status mais comum primeiro. O conjunto de resultados tem
no máximo tantas linhas quantos valores de status distintos existirem (quatro neste schema).

---

## Principais itens: classificados por receita com `LIMIT`

```php
$rows = $this->db->fetchAll(
    "SELECT item_name, COUNT(*) AS order_count, SUM(amount) AS revenue
     FROM orders {$where}
     GROUP BY item_name
     ORDER BY revenue DESC
     LIMIT ?",
    $params,
);
```

`ORDER BY revenue DESC LIMIT ?` — `LIMIT` parametrizado seleciona os top N itens por
receita total. O parâmetro `limit` do caminho é limitado no lado do servidor:

```php
private const int MAX_LIMIT = 100;

$limit = min((int) $q['limit'], self::MAX_LIMIT);
```

`min(..., MAX_LIMIT)` previne que os clientes solicitem mais de 100 itens. Nota:
`is_numeric($q['limit'])` é usado aqui (em vez de `is_int`) porque os valores de query string
são sempre strings — `is_int` sempre falharia em entradas de query string.

---

## Cláusula `WHERE` dinâmica com `dateFilter()`

Todas as consultas de agregação compartilham um helper `dateFilter()` que anexa condições apenas
quando um limite de data é fornecido:

```php
private function dateFilter(?string $from, ?string $to): array
{
    $conditions = [];
    $params     = [];
    if ($from !== null) {
        $conditions[] = 'created_at >= ?';
        $params[]     = $from;
    }
    if ($to !== null) {
        $conditions[] = 'created_at <= ?';
        $params[]     = $to;
    }
    $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
    return [$where, $params];
}
```

Quando `from` e `to` são ambos `null`, `$where` é `''` — a tabela inteira é varrida.
O chamador incorpora `{$where}` na string SQL antes da consulta ser executada. Os
valores reais ainda são parametrizados (`?`) — apenas a palavra-chave `WHERE` é interpolada.

---

## Validação de datas: round-trip com `createFromFormat()`

Aceitar `from` e `to` como strings YYYY-MM-DD requer validar que a data é
bem formada e semanticamente válida (ex.: `2026-02-30` é rejeitada):

```php
private function isValidDate(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}
```

Validação em dois passos:
1. `preg_match` — rejeita formato não correspondente rapidamente sem overhead de objeto de data.
2. `createFromFormat` + round-trip `format()` — detecta datas semanticamente inválidas como
   `2026-02-30` (que o PHP transbordaría para `2026-03-02` se validado apenas por regex).

A direção do intervalo também é validada:
```php
if ($from !== null && $to !== null && $from > $to) {
    $errors[] = new ValidationError('from', 'from must be before or equal to to', 'invalid');
}
```

A comparação de strings funciona corretamente aqui porque ambas as datas são `YYYY-MM-DD` — um formato
onde a ordem lexicográfica é igual à ordem cronológica.

---

## Built-ins do `NENE2` usados

| Built-in | Propósito |
|---|---|
| `ValidationException` / `ValidationError` | `422` estruturado com array `errors` |
| `JsonResponseFactory::create()` | Codifica resposta JSON |
| Constantes `Router` | `PARAMETERS_ATTRIBUTE` para params de caminho |

---

## Howtos relacionados

- [`event-analytics-api.md`](event-analytics-api.md) — analytics com blob JSON usando `json_extract()`, agrupamento com `COUNT(DISTINCT)`
- [`cqrs-pattern.md`](cqrs-pattern.md) — SQL VIEW como modelo de leitura para agregação de pedidos
- [`credit-ledger.md`](credit-ledger.md) — cálculo de saldo com `COALESCE(SUM(amount * direction), 0)`
- [`admin-report-aggregation.md`](admin-report-aggregation.md) — padrões de agregação com escopo admin
