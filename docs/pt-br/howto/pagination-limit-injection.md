# Como Fazer: Prevenção de Limite de Paginação e Injeção de Limit

> **Referência FT**: FT319 (`NENE2-FT/limitlog`) — Paginação por offset e cursor com validação estrita de limit/page, imposição de limite MAX_LIMIT, validação com ctype_digit segura contra ReDoS, 20 testes / 384 assertivas PASS.

Este guia mostra como implementar paginação segura com estratégias de offset e cursor, enquanto previne ataques de limite de inteiros e injeção de limit.

## Constantes

```php
const DEFAULT_LIMIT = 20;
const MAX_LIMIT     = 100;
```

## Paginação por Offset

```php
GET /articles?page=1&limit=10
→ 200
{
  "data": [...],      // 10 itens
  "total": 25,
  "limit": 10,
  "page": 1,
  "has_more": true
}
```

```php
// página 3 de 25 itens com limit=10 → última página
GET /articles?page=3&limit=10
→ 200  {"data": [...], "has_more": false}  // 5 itens
```

**Cálculo do OFFSET**: `(page - 1) * limit` — page deve ser ≥ 1 para prevenir OFFSET negativo.

## Paginação por Cursor

```php
GET /articles/cursor?limit=5
→ 200  {"data": [...], "next_cursor": 42, "has_more": true}

GET /articles/cursor?after=42&limit=5
→ 200  {"data": [...], "next_cursor": 37, "has_more": true}

GET /articles/cursor?after=37&limit=5
→ 200  {"data": [...], "next_cursor": null, "has_more": false}
```

O cursor é o `id` do último item: `WHERE id < $after ORDER BY id DESC LIMIT $limit`.

## Filtro por Autor

```php
GET /articles/by-author?author_id=2&limit=10
→ 200  {"data": [...]}  // apenas itens com author_id = 2
```

`author_id` deve ser um inteiro positivo (mesma validação que `limit`).

## Validação de Limit — Padrão `ctype_digit`

Use `ctype_digit()` para validação O(n) — imune a ReDoS, diferente do regex `^\d+$`:

```php
/**
 * Analisa um parâmetro inteiro da query string.
 * Rejeita: zero, negativo, float, overflow, não numérico, espaço em branco.
 */
function parseQueryInt(string $raw, int $min, int $max): int
{
    // Rejeita vazio, floats, sinais, espaços em branco, chars não-dígito
    if ($raw === '' || !ctype_digit($raw)) {
        throw new ValidationException(/* 422 */);
    }
    // Guarda contra overflow 64 bits antes do cast
    if (strlen($raw) > 18) {
        throw new ValidationException(/* 422 */);
    }
    $val = (int) $raw;
    if ($val < $min || $val > $max) {
        throw new ValidationException(/* 422 */);
    }
    return $val;
}
```

### O Que `ctype_digit` Bloqueia

| Entrada | `ctype_digit` | Motivo |
|---------|--------------|--------|
| `"10"` | ✅ Passa | Dígitos válidos |
| `"0"` | ✅ Passa (ctype) | Rejeitado pela verificação min=1 |
| `"-1"` | ❌ Rejeita | `-` não é dígito |
| `"10.5"` | ❌ Rejeita | `.` não é dígito |
| `"1e2"` | ❌ Rejeita | `e` não é dígito |
| `"+10"` | ❌ Rejeita | `+` não é dígito |
| `" 10"` | ❌ Rejeita | espaço não é dígito |
| `"0x10"` | ❌ Rejeita | `x` não é dígito |
| `"10\x00"` | ❌ Rejeita | byte nulo não é dígito |
| string de 20 dígitos | ❌ Rejeita | guarda strlen > 18 |
| Payload ReDoS `"1...1x"` | ❌ Rejeita (rápido) | Scan O(n), sem backtracking |

### Casos de Erro

```php
GET /articles?limit=999999  → 422  // excede MAX_LIMIT
GET /articles?limit=0       → 422  // min=1
GET /articles?limit=-1      → 422  // não passa em ctype_digit
GET /articles?limit=10.5    → 422  // float
GET /articles?limit=abc     → 422  // não numérico
GET /articles?page=0        → 422  // OFFSET negativo
GET /articles/cursor?after=99999999999999999999  → 422  // overflow
```

## Ataque de Parâmetro Duplicado

```php
GET /articles?limit=5&limit=1000
// PHP usa o último valor: 1000 → excede MAX_LIMIT → 422
```

A maioria das implementações PSR-7 usa a última ocorrência. 422 (último valor acima do MAX) ou 200 com o valor válido são aceitáveis — nunca usar silenciosamente 1000.

## Número de Página Grande

```php
GET /articles?page=999999&limit=10
→ 200  {"data": [], "has_more": false}  // vazio, não um crash
```

Uma página enorme que excede a contagem total é válida — retorna dados vazios, não um erro.

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| `(int) $raw` sem `ctype_digit` | `-1`, `1.5`, `" 10"` fazem cast para inteiros silenciosamente |
| Regex `/^\d+$/` para validação de inteiro | Backtracking catastrófico (ReDoS) em entradas longas mistas |
| Sem limite MAX_LIMIT | `limit=999999` despeja toda a tabela em uma requisição |
| Permitir `page=0` | `OFFSET = (0-1)*limit = -limit` corrompe ou gera erro na query SQL |
| Guarda apenas de strlen para overflow | `"1.5"` tem 3 chars — curto o suficiente para passar, mas não é um inteiro válido |
| Sem verificação de mínimo em `author_id` | `author_id=0` retorna resultado vazio silenciosamente; inválido semanticamente |
