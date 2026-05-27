# Paginação

Dois padrões estão disponíveis para paginar endpoints de listagem: **OFFSET** e **cursor** (keyset). Escolha com base no volume de dados e nos requisitos de UI.

## Comparação rápida

| | OFFSET | Cursor |
|---|---|---|
| Implementação | Simples | Moderada (padrão fetch+1) |
| Contagem total | Requer `COUNT(*)` | Não necessário |
| Velocidade em páginas profundas | Degrada linearmente | Constante (seek por índice) |
| UI de número de página | Fácil | Difícil |
| Scroll infinito / feed | Frágil (deriva de linhas) | Seguro |
| Mudanças de dados durante a navegação | Pode causar deriva de linhas | Estável |

**Regra geral:** Use OFFSET para tabelas administrativas com números de página e conjuntos de dados pequenos. Use cursor para feeds, scroll infinito e qualquer tabela com mais de ~10.000 linhas.

## Paginação por OFFSET

```php
private function listByOffset(ServerRequestInterface $request): ResponseInterface
{
    $limit  = max(1, min(100, QueryStringParser::int($request, 'limit', 20) ?? 20));
    $offset = max(0, QueryStringParser::int($request, 'offset', 0) ?? 0);

    $items = $repo->fetchAll(
        'SELECT * FROM articles ORDER BY id DESC LIMIT ? OFFSET ?',
        [$limit, $offset],
    );
    $total = $repo->fetchOne('SELECT COUNT(*) AS cnt FROM articles', [])['cnt'] ?? 0;

    return $json->create([
        'items'       => $items,
        'limit'       => $limit,
        'offset'      => $offset,
        'total'       => (int) $total,
        'has_more'    => ($offset + $limit) < (int) $total,
        'next_offset' => ($offset + $limit) < (int) $total ? $offset + $limit : null,
    ]);
}
```

**Por que OFFSET fica mais lento**: O banco de dados deve escanear e descartar todas as linhas antes do offset. Para `OFFSET 5000`, o motor lê 5001 linhas e descarta as primeiras 5000. Você pode verificar com SQLite:

```sql
EXPLAIN QUERY PLAN
SELECT * FROM articles ORDER BY id DESC LIMIT 20 OFFSET 5000;
-- SCAN articles USING INDEX idx_articles_id_desc
-- O scan ainda toca 5020 linhas.
```

## Paginação por cursor

O cursor é o `id` da última linha vista. Cada página busca linhas "antes" do cursor (para ordem decrescente) usando `WHERE id < cursor`, que o índice serve com um seek — nenhuma linha antes do cursor é tocada.

```php
private function listByCursor(ServerRequestInterface $request): ResponseInterface
{
    $limit   = max(1, min(100, QueryStringParser::int($request, 'limit', 20) ?? 20));
    $afterId = QueryStringParser::int($request, 'after'); // null = primeira página

    // padrão fetch+1: detectar has_more sem query COUNT
    $fetch = $limit + 1;

    if ($afterId === null) {
        $rows = $repo->fetchAll(
            'SELECT * FROM articles ORDER BY id DESC LIMIT ?',
            [$fetch],
        );
    } else {
        $rows = $repo->fetchAll(
            'SELECT * FROM articles WHERE id < ? ORDER BY id DESC LIMIT ?',
            [$afterId, $fetch],
        );
    }

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows); // descartar a linha sentinela extra
    }

    $nextCursor = $hasMore && $rows !== [] ? (int) end($rows)['id'] : null;

    return $json->create([
        'items'       => $rows,
        'limit'       => $limit,
        'has_more'    => $hasMore,
        'next_cursor' => $nextCursor,
    ]);
}
```

### O padrão fetch+1

Para saber se há uma próxima página sem emitir um `COUNT(*)`:

1. Solicitar `limit + 1` linhas.
2. Se o resultado tiver mais que `limit` linhas, há uma próxima página.
3. Descartar a última linha (`array_pop`) antes de retornar.
4. Usar o `id` da última linha restante como `next_cursor`.

Isso evita uma query extra ao custo de sempre buscar uma linha extra.

### Uso pelo cliente

```
GET /articles/cursor?limit=20
→ { items: [...20 itens], has_more: true, next_cursor: 42 }

GET /articles/cursor?limit=20&after=42
→ { items: [...20 itens], has_more: true, next_cursor: 22 }

GET /articles/cursor?limit=20&after=22
→ { items: [...2 itens], has_more: false, next_cursor: null }
```

## Limitação do limit

Sempre limite o limit a um intervalo razoável para prevenir queries sem limite:

```php
$limit = max(1, min(100, QueryStringParser::int($request, 'limit', 20) ?? 20));
```

Aceita `1–100` e usa `20` como padrão quando o parâmetro está ausente.

## Quando migrar de OFFSET para cursor

Uma orientação aproximada com base no tamanho da tabela e profundidade típica da página:

| Linhas | Profundidade típica | Recomendação |
|--------|--------------------|-|
| < 10.000 | Qualquer | Ambos funcionam; OFFSET é mais simples |
| 10.000–100.000 | Raso (página 1–5) | Ambos; adicionar índice na coluna de ordenação |
| 10.000–100.000 | Profundo (página 10+) | Cursor preferido |
| > 100.000 | Qualquer | Cursor altamente recomendado |

Adicione um índice na coluna de ordenação independente da abordagem escolhida:

```sql
CREATE INDEX idx_articles_id_desc ON articles (id DESC);
```

## Comparando resultados na mesma posição

Ao migrar de OFFSET para cursor, verifique a corretude buscando a mesma "janela" de linhas de ambas as formas:

```php
// OFFSET: linhas 11–20 (offset=10 indexado em 0)
$offsetPage = $get('/articles/offset?limit=10&offset=10');

// Cursor: buscar o id na posição 10 (offset=9), usar como âncora
$anchor     = $get('/articles/offset?limit=1&offset=9');
$anchorId   = $anchor['items'][0]['id'];
$cursorPage = $get("/articles/cursor?limit=10&after={$anchorId}");

// Devem ser idênticos
assert(array_column($offsetPage['items'], 'id') === array_column($cursorPage['items'], 'id'));
```
