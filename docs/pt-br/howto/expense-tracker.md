# Como Fazer: API de Rastreamento de Despesas

Este guia mostra como construir um sistema pessoal de rastreamento de despesas com filtragem por categoria,
consultas por intervalo de data, agregação de resumo mensal e CRUD completo usando NENE2.
Padrão demonstrado pelo field trial **expenselog** (FT223).

## Funcionalidades

- Criar, ler, atualizar, deletar despesas (data, valor, categoria, nota)
- Listar com filtro de intervalo de data (`?from=` / `?to=`) e filtro de categoria
- Agregação de resumo mensal (total por categoria por mês)
- Paginação com contagem total
- Validação de lista de permissões de categoria
- Validação de valor: inteiro positivo (centavos)

## Schema

```sql
CREATE TABLE IF NOT EXISTS expenses (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    date       TEXT    NOT NULL,
    amount     INTEGER NOT NULL,
    category   TEXT    NOT NULL,
    note       TEXT    NOT NULL DEFAULT '',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_expenses_date ON expenses (date DESC);
CREATE INDEX IF NOT EXISTS idx_expenses_category ON expenses (category, date DESC);
```

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `GET` | `/expenses` | Listar despesas (filtrável, paginado) |
| `POST` | `/expenses` | Criar despesa |
| `GET` | `/expenses/summary` | Resumo mensal por categoria |
| `GET` | `/expenses/{id}` | Obter despesa única |
| `PATCH` | `/expenses/{id}` | Atualização parcial |
| `DELETE` | `/expenses/{id}` | Deletar despesa |

## Padrões de validação

### Valor (inteiro positivo, armazenado em centavos)

```php
if (!is_int($amount) || $amount <= 0) {
    $errors[] = new ValidationError('amount', 'Amount must be a positive integer (cents).');
}
```

Usar `is_int()` rejeita floats do JSON (`1.5` não é um int no modo estrito do PHP).

### Data (formato ISO 8601)

```php
$date = DateTime::createFromFormat('Y-m-d', $dateStr);
if (!$date || $date->format('Y-m-d') !== $dateStr) {
    $errors[] = new ValidationError('date', 'date must be a valid YYYY-MM-DD date.');
}
```

Validação round-trip: parse e reformate — garante que a string era canônica.

### Lista de permissões de categoria

```php
private const array VALID_CATEGORIES = [
    'food', 'transport', 'utilities', 'entertainment',
    'health', 'shopping', 'travel', 'other',
];

if (!in_array($category, self::VALID_CATEGORIES, true)) {
    $errors[] = new ValidationError('category', 'Invalid category.');
}
```

## Filtragem por intervalo de data

```php
public function findAll(
    ?string $from = null,
    ?string $to = null,
    ?string $category = null,
    int $limit = 20,
    int $offset = 0,
): array
```

Os filtros são opcionais — omita para consultas de todos os tempos. Datas comparadas lexicograficamente (strings ISO 8601 são ordenáveis em UTC).

## Consulta de resumo mensal

Agregue por ano-mês usando `strftime` do SQLite:

```sql
SELECT strftime('%Y-%m', date) AS month, category, SUM(amount) AS total, COUNT(*) AS count
FROM expenses
WHERE date BETWEEN :from AND :to
GROUP BY month, category
ORDER BY month DESC, category ASC
```

Retorna totais por categoria para cada mês:

```json
{
  "summary": [
    { "month": "2026-05", "category": "food", "total": 42000, "count": 12 },
    { "month": "2026-05", "category": "transport", "total": 8500, "count": 5 }
  ]
}
```

## Atualização parcial com PATCH

Apenas os campos fornecidos no corpo são atualizados — campos ausentes retêm seus valores atuais:

```php
if (isset($body['amount'])) {
    if (!is_int($body['amount']) || $body['amount'] <= 0) {
        $errors[] = new ValidationError('amount', 'Amount must be a positive integer.');
    } else {
        $updates['amount'] = $body['amount'];
    }
}
// Mesmo padrão para date, category, note
```

## Padrões de validação

| Campo | Verificação | Motivo |
|-------|------------|--------|
| `amount` | `is_int() && > 0` | Rejeita floats, zero, negativos |
| `date` | Parse round-trip `Y-m-d` | Apenas ISO 8601 canônico |
| `category` | `in_array(strict: true)` | Previne erros de digitação e injeção |
| `limit` / `offset` | `max(1, min(100, $limit))` | Previne DoS e SQL injection |
