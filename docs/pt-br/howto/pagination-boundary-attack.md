# Como Fazer: Ataque de Limite de Paginação e Injeção de Limit

**FT177 — limitlog**

Validação robusta de parâmetros inteiros para paginação baseada em offset e cursor —
prevenindo dumps de banco, overflows, confusão de tipos e ReDoS.

---

## A superfície de ataque

Todo endpoint de paginação expõe pelo menos dois parâmetros inteiros (`limit`, `page` / `after`).
Atacantes rotineiramente os sondiam com:

| Ataque | Exemplo | Risco |
|--------|---------|-------|
| Limit excessivo | `limit=999999` | Dump completo da tabela |
| Zero/negativo | `limit=0`, `limit=-1` | OFFSET negativo → erro no banco ou wrap |
| Injeção de float | `limit=10.5`, `limit=1e2` | Cast silencioso: `(int)"10.5" === 10` |
| Preenchido / com sinal | `limit=+10`, `limit= 10` | Trim silencioso: `(int)" 10" === 10` |
| Overflow de inteiro | `limit=99999999999999999999` | Wrap para negativo em 64 bits |
| Não numérico | `limit=abc`, `limit=1;DROP TABLE` | Erro de tipo ou injeção |
| Hex / octal | `limit=0x10`, `limit=010` | `0x` → falha no ctype; `010` passa! |
| Parâmetro duplicado | `?limit=5&limit=1000` | Último valor ofusca o validado |
| Payload ReDoS | `limit=111...1x` | Backtracking exponencial em regex |

---

## O padrão `clampInt()`

```php
/**
 * @param array<string, mixed> $params
 */
private function clampInt(array $params, string $key, ?int $default, int $min, int $max): ?int
{
    if (!array_key_exists($key, $params)) {
        return $default;  // ausente → usar padrão (não null = inválido)
    }

    $raw = $params[$key];

    // ctype_digit: O(n), imune a ReDoS, rejeita '' / '-' / '.' / '+' / ' ' / 'e'
    // ctype_digit('') === false  →  string vazia já rejeitada
    if (!is_string($raw) || !ctype_digit($raw)) {
        return null;  // sinal: chamador deve retornar 422
    }

    // Prevenir overflow silencioso do PHP: (int)"99999999999999999999" faz wrap
    if (strlen($raw) > 18) {
        return null;
    }

    $value = (int) $raw;

    if ($value < $min || $value > $max) {
        return null;
    }

    return $value;
}
```

### Por que `ctype_digit`, não regex

| Validador | Seguro contra ReDoS? | Rejeita `010`? | Rejeita `+10`? |
|-----------|---------------------|----------------|----------------|
| `/^\d+$/` | ❌ exponencial em `111...1x` | ✅ | ❌ |
| `ctype_digit()` | ✅ O(n) | ✅ (prefixo `0`: passa — mas limitado pelo intervalo) | ✅ |
| `is_numeric()` | ✅ | ❌ | ❌ |
| `filter_var(FILTER_VALIDATE_INT)` | ✅ | ✅ | ❌ (`+10` passa!) |

**Use `ctype_digit()`** — é o mais estrito e rápido.

### O problema com `010`

`ctype_digit('010')` → `true` (passa na verificação de dígito), `(int)'010'` → `10` (decimal, não octal).
Isso é seguro porque o PHP não interpreta octalmente inteiros convertidos de string
(diferente de `010` como literal PHP). Confirme nos testes se sua equipe tiver dúvidas.

---

## Paginação baseada em cursor

```php
// Buscar uma linha extra para determinar has_more — sem query COUNT
$rows = $this->db->fetchAll(
    'SELECT * FROM articles WHERE id < ? ORDER BY id DESC LIMIT ?',
    [$afterId, $limit + 1],
);

$hasMore = count($rows) > $limit;
if ($hasMore) {
    array_pop($rows);  // descartar o sentinela
}

$nextCursor = $hasMore && count($rows) > 0 ? end($rows)->id : null;
```

### Sentinela de cursor para "primeira página"

```php
private const int NO_CURSOR = PHP_INT_MAX;

// GET /articles/cursor (sem parâmetro ?after) → afterId padrão para PHP_INT_MAX
// WHERE id < PHP_INT_MAX  ==>  efetivamente todas as linhas
```

---

## Paginação por offset — guarda de página zero

`page=0` produz `OFFSET = (0-1) * limit = -limit` — OFFSET negativo é um erro SQL
em alguns bancos (MySQL o rejeita) ou faz wrap silenciosamente em outros.

```php
$page  = $this->clampInt($params, 'page', 1, 1, PHP_INT_MAX);
// min=1 → page=0 retorna null → 422
```

---

## Guarda de overflow de inteiro

O cast `(int)` do PHP em uma string de 20 dígitos faz wrap silenciosamente:

```php
(int)'99999999999999999999'  // === -1 em PHP 64 bits
```

A guarda `strlen($raw) > 18` previne isso antes do cast. 18 dígitos cobre com segurança
`PHP_INT_MAX` (19 dígitos) com margem para que o cast seja sempre seguro.

---

## Checklist VULN-A a VULN-L

| # | Teste | Expectativa |
|---|-------|-------------|
| VULN-A | `limit` acima do MAX (100) | 422 — rejeição explícita, não truncamento silencioso |
| VULN-B | `limit=0`, `limit=-1` | 422 — `0` falha no min=1; `-` falha no ctype_digit |
| VULN-C | Float string `10.5`, `1e2`, `1.0` | 422 — `.` e `e` falham no ctype_digit |
| VULN-D | Preenchido `%2010`, `10%20`, `%2B10` | 422 — espaço/`+` falham no ctype_digit |
| VULN-E | Overflow `9999...` (20 dígitos) | 422 — guarda strlen > 18 |
| VULN-F | Não numérico, hex `0x10`, injeção SQL | 422 — ctype_digit rejeita tudo |
| VULN-G | `page=0` (paginação por offset) | 422 — guarda min=1 |
| VULN-H | Limite de cursor: `after=0` válido, cursor com overflow 422 | Misto |
| VULN-I | `author_id=0`, `-1`, `abc`, `1.5` | 422 |
| VULN-J | Página muito grande (page=999999) | 200 vazio — não deve travar |
| VULN-K | Parâmetro duplicado `?limit=5&limit=1000` | 200 (seguro) ou 422 — nunca > MAX |
| VULN-L | Payload ReDoS `111...1x` (50 dígitos + x) | 422 em < 100ms |

---

## Nota de teste: VULN-J vs VULN-A

Parecem contraditórios, mas servem a objetivos diferentes:

- **VULN-A**: `limit=999999` → **422** — rejeitar contagem de linhas excessivamente grande
- **VULN-J**: `page=999999&limit=10` → **200 vazio** — uma página válida que simplesmente não tem dados

O servidor não deve travar ou dar erro em uma página semanticamente válida mas praticamente vazia.
`OFFSET = (999999-1) * 10 = 9999980` é um OFFSET SQL legal; o resultado simplesmente é vazio.
