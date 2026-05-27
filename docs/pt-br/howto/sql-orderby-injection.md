# Como Prevenir Injeção SQL em ORDER BY

Cláusulas SQL `ORDER BY` não podem ser parametrizadas com placeholders padrão (`?`). Isso significa que colunas de ordenação e direções controladas pelo usuário nunca devem ser interpoladas diretamente no SQL. Este guia explica a única abordagem segura: uma allowlist explícita.

---

## O Problema

Placeholders de prepared statements protegem valores de coluna em cláusulas `WHERE`, mas **não** funcionam para nomes de coluna ou direções de ordenação em `ORDER BY`:

```php
// ❌ ERRADO — isso NÃO protege contra injeção
$stmt = $pdo->prepare("SELECT * FROM articles ORDER BY ? ?");
$stmt->execute([$column, $direction]);
// Muitos drivers de banco tratam argumentos ORDER BY como literais, não identificadores.
```

Um atacante enviando `?sort=SLEEP(5)` ou `?sort=(SELECT password FROM users LIMIT 1)` pode causar ataques baseados em tempo, vazamento de informações ou erros que revelam detalhes do schema.

---

## A Única Solução Segura: Allowlist Explícita

```php
// ✅ SEGURO — allowlist + in_array strict
public const array SORT_COLUMNS = ['id', 'title', 'status', 'created_at'];
public const array SORT_DIRS    = ['asc', 'desc'];

$sql = "SELECT * FROM articles ORDER BY {$sortCol} {$sortDir} LIMIT ?";
```

Os valores da allowlist são **strings hardcoded** que você controla. Apenas esses valores chegam ao SQL.

---

## Padrão Completo do Route Handler

```php
// ── Coluna de ordenação — DEVE ser validada contra allowlist ────────────────────
//
// SEGURANÇA: ORDER BY não suporta placeholders ? no SQL padrão.
// A ÚNICA abordagem segura é uma allowlist explícita verificada com in_array strict.
//
$rawSort = $params['sort'] ?? null;

if ($rawSort !== null) {
    // Injeção de array: PSR-7 pode dar array para ?sort[]=id
    if (!is_string($rawSort)) {
        return $this->responseFactory->create(['error' => 'sort deve ser uma string.'], 422);
    }

    // Verificação de byte nulo — PSR-7 decodifica %00 para o byte nulo real
    if (str_contains($rawSort, "\0")) {
        return $this->responseFactory->create(['error' => 'sort contém caracteres inválidos.'], 422);
    }

    // Verificação de allowlist — strict, case-sensitive.
    // PSR-7 já decodifica URL de query strings uma vez (%65 → e), então nomes de coluna
    // válidos com encoding único são aceitos. Valores duplamente codificados (%2565 → %65
    // em $rawSort) NÃO são decodificados uma segunda vez, então falham na allowlist e são rejeitados.
    if (!in_array($rawSort, MyRepository::SORT_COLUMNS, true)) {
        return $this->responseFactory->create(
            ['error' => sprintf('sort deve ser um de: %s.', implode(', ', MyRepository::SORT_COLUMNS))],
            422,
        );
    }

    $sortCol = $rawSort;
} else {
    $sortCol = 'created_at';  // padrão seguro
}

// ── Direção de ordenação — somente allowlist ─────────────────────────────────────
$rawOrder = $params['order'] ?? null;

if ($rawOrder !== null) {
    if (!is_string($rawOrder)) {
        return $this->responseFactory->create(['error' => 'order deve ser uma string.'], 422);
    }

    $dir = strtolower(trim($rawOrder));

    if (!in_array($dir, MyRepository::SORT_DIRS, true)) {
        return $this->responseFactory->create(
            ['error' => sprintf('order deve ser um de: %s.', implode(', ', MyRepository::SORT_DIRS))],
            422,
        );
    }

    $sortDir = $dir;
} else {
    $sortDir = 'desc';  // padrão seguro
}
```

---

## Camada Repository

O repository recebe valores já validados e os interpola diretamente:

```php
/**
 * $sortCol e $sortDir DEVEM ser verificados na allowlist pelo chamador.
 * Este método confia neles e os interpola diretamente no SQL.
 *
 * @return array{data: list<Article>, total: int, sort: string, order: string, limit: int}
 */
public function list(string $sortCol, string $sortDir, ?ArticleStatus $status, int $limit): array
{
    $where  = $status !== null ? 'WHERE status = ?' : '';
    $params = $status !== null ? [$status->value] : [];

    // $sortCol e $sortDir são pré-validados — seguro para interpolar.
    // Nunca coloque entrada bruta do usuário aqui.
    $sql  = "SELECT * FROM articles {$where} ORDER BY {$sortCol} {$sortDir} LIMIT ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([...$params, $limit]);
    ...
}
```

---

## Padrões de Ataque Bloqueados por Esta Abordagem

| Ataque | Entrada | Resultado |
|---|---|---|
| Injeção DROP TABLE | `?sort='; DROP TABLE articles--` | 422 — não está na allowlist |
| Exfiltração UNION SELECT | `?sort=1; SELECT password` | 422 — não está na allowlist |
| Extração via subquery | `?sort=(SELECT name FROM sqlite_master)` | 422 — não está na allowlist |
| Blind baseado em tempo | `?sort=SLEEP(5)` | 422 — não está na allowlist |
| Injeção por índice de coluna | `?sort=1` | 422 — não está na allowlist |
| Coluna desconhecida | `?sort=password` | 422 — não está na allowlist |
| Bypass por case/comment | `?sort=CREATED_AT--` | 422 — case-sensitive |
| Bypass por byte nulo | `?sort=created_at%00` | 422 — verificação de byte nulo |
| Injeção de array | `?sort[]=created_at` | 422 — verificação de tipo |
| Encoding duplo de URL | `?sort=cr%2565ated_at` | 422 — PSR-7 decodifica uma vez; `cr%65ated_at` não está na allowlist |
| Encoding único de URL (válido) | `?sort=cr%65ated_at` | 200 — PSR-7 decodifica para `created_at` ✓ |
| Injeção de direção | `?order=asc; UNION SELECT 1--` | 422 — não está na allowlist |

---

## Pontos-Chave

1. **Sem `rawurldecode()` após PSR-7**: O `getQueryParams()` do PSR-7 já decodifica a query string uma vez. Chamar `rawurldecode()` novamente permitiria que valores duplamente codificados passassem pela verificação de allowlist.

2. **`in_array($value, $allowlist, true)`**: O terceiro argumento `true` habilita comparação strict (segura por tipo). Sem ele, `in_array(0, ['id', 'created_at'])` retorna `true` porque o PHP coerce strings para inteiros.

3. **Verificação case-sensitive**: Nomes de coluna devem ser minúsculos e correspondidos exatamente. Nunca use `strcasecmp` ou `strtolower` antes da verificação de allowlist — `CREATED_AT` não é o mesmo token que `created_at` do ponto de vista de confiança.

4. **Direção: `strtolower(trim())` é seguro**: Diferente de nomes de coluna, direção (`asc`/`desc`) tem apenas dois valores válidos. Normalizar case antes da verificação de allowlist é aceitável já que a própria allowlist é exaustiva e minúscula.

5. **Documente o contrato**: O método repository deve documentar que confia em suas entradas. Chamadores nunca devem passar entrada bruta do usuário.

---

## Relacionados

- FT180 — sortlog: Injeção SQL em ORDER BY & Prevenção de Ordenação/Filtro Dinâmico
- [RFC 3986](https://www.rfc-editor.org/rfc/rfc3986) — Codificação URI
- [PSR-7](https://www.php-fig.org/psr/psr-7/) — `ServerRequestInterface::getQueryParams()`
