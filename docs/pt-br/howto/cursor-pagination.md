# Como Fazer: Paginação Baseada em Cursor

> **Referência FT**: FT242 (`NENE2-FT/cursorlog`) — API de Paginação Baseada em Cursor

Demonstra paginação baseada em cursor (keyset) como alternativa à paginação por offset.
Itens são buscados usando um cursor baseado em ID (`WHERE id < ?`), um truque `limit+1` detecta
`has_more` sem uma consulta COUNT, e a resposta carrega um valor `next_cursor` que o
chamador passa na próxima requisição.

---

## Rotas

| Método | Caminho       | Descrição                                    |
|--------|---------------|----------------------------------------------|
| `POST` | `/posts`      | Criar um post                                |
| `GET`  | `/posts`      | Listar posts com paginação por cursor        |
| `GET`  | `/posts/{id}` | Obter um post                                |

---

## Paginação por offset vs. por cursor

| Preocupação                   | Offset (`LIMIT ? OFFSET ?`)             | Cursor (`WHERE id < ? ORDER BY id`)        |
|-------------------------------|------------------------------------------|--------------------------------------------|
| Desempenho em grandes conjuntos | Degrada — BD deve pular N linhas        | Constante — busca por índice até a posição do cursor |
| Resultados estáveis            | Novas linhas deslocam páginas subsequentes | Estável — ancorado em uma linha específica |
| Acesso aleatório               | Suportado (`?page=5`)                   | Não suportado (somente avançar)            |
| Contagem total                 | Precisa de uma consulta `COUNT(*)` separada | Sem total necessário (use flag `has_more`) |
| Tipo de cursor                 | Offset inteiro (baseado em posição)     | Valor de identidade da linha (baseado em ID) |

Paginação por cursor é preferível para feeds de alto volume e tempo real onde o desvio por
offset (novos itens inseridos entre páginas) causa linhas duplicadas ou ausentes.

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    author     TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_posts_id ON posts(id DESC);
```

Um índice decrescente em `id` suporta `ORDER BY id DESC` de forma eficiente. O
`INTEGER PRIMARY KEY` do SQLite já é um alias para `rowid`, então o índice explícito acelera
consultas de faixa além do que a chave primária sozinha oferece.

---

## Lógica do cursor: `WHERE id < ? ORDER BY id DESC LIMIT ?`

O repositório busca uma linha extra (`limit + 1`) para detectar se existem mais páginas:

```php
/**
 * Busca uma página de posts em ordem decrescente de ID.
 *
 * @param int|null $afterCursor  ID do último post visto; retornar posts com id < afterCursor
 * @param int      $limit        Máximo de itens a retornar (limitado a 100)
 */
public function paginate(?int $afterCursor, int $limit): CursorPage
{
    $limit = max(1, min(100, $limit));
    // Buscar uma extra para detectar se há próxima página
    $fetch = $limit + 1;

    if ($afterCursor !== null) {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM posts WHERE id < ? ORDER BY id DESC LIMIT ?',
            [$afterCursor, $fetch],
        );
    } else {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM posts ORDER BY id DESC LIMIT ?',
            [$fetch],
        );
    }

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);   // descartar a linha extra
    }

    $items      = array_map(fn (array $row): Post => $this->hydrate($row), $rows);
    $nextCursor = $hasMore && $items !== [] ? $items[array_key_last($items)]->id : null;

    return new CursorPage($items, $nextCursor, $hasMore);
}
```

Passos principais:
1. **Limitar**: `max(1, min(100, $limit))` — previne consultas com 0 linhas ou descontroladas.
2. **Buscar `limit + 1`**: Se mais de `$limit` linhas retornarem, uma próxima página existe.
3. **Remover a extra**: `array_pop($rows)` descarta a (limit+1)-ésima linha usada apenas para detecção.
4. **Computar `nextCursor`**: O `id` do último item torna-se o cursor que o chamador envia na próxima requisição.
5. **`$hasMore = false`** quando `$nextCursor === null` — sem mais páginas.

A primeira página não tem cursor (`$afterCursor === null`), retornando os posts mais recentes.
Cada requisição subsequente envia `?cursor=<nextCursor>` para continuar de onde parou.

---

## Value object `CursorPage`

```php
final readonly class CursorPage
{
    /** @param list<Post> $items */
    public function __construct(
        public array $items,
        public ?int  $nextCursor,
        public bool  $hasMore,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'items'       => array_map(static fn (Post $p): array => $p->toArray(), $this->items),
            'next_cursor' => $this->nextCursor,
            'has_more'    => $this->hasMore,
        ];
    }
}
```

`next_cursor` é `null` na última página (sem mais itens). `has_more` reflete isso:
`true` quando `next_cursor` está definido, `false` na última página. Chamadores param quando
`has_more === false` ou `next_cursor === null`.

Formato da resposta:
```json
{
    "items": [...],
    "next_cursor": 42,
    "has_more": true
}
```

---

## Controller: lendo e validando o cursor

```php
private function list(ServerRequestInterface $request): ResponseInterface
{
    $query       = $request->getQueryParams();
    $limit       = isset($query['limit']) ? (int) $query['limit'] : 10;
    $cursorRaw   = isset($query['cursor']) && is_string($query['cursor']) ? $query['cursor'] : null;
    $afterCursor = $cursorRaw !== null && ctype_digit($cursorRaw) ? (int) $cursorRaw : null;

    $page = $this->repo->paginate($afterCursor, $limit);

    return $this->json->create($page->toArray());
}
```

`ctype_digit($cursorRaw)` valida a string do cursor antes de converter para `int`:
- `ctype_digit()` retorna `false` para strings vazias, sinais negativos, floats e
  strings não numéricas — todos tratados como "sem cursor" (primeira página).
- Um cursor inválido cai de volta para a primeira página em vez de retornar um erro —
  chamadores que passam um cursor expirado ou inválido veem a primeira página, não um `400`.

Essa é uma escolha pragmática: cursores inválidos são tratados silenciosamente como ausentes. Para
APIs mais estritas, retorne `422 Unprocessable Entity` quando `$cursorRaw` é não-nulo mas falha
em `ctype_digit()`.

---

## Limitação do limite

```php
$limit = max(1, min(100, $limit));
```

- Mínimo `1`: previne consultas com zero linhas.
- Máximo `100`: limita o tamanho da página para evitar buscas descontroladas.

A limitação acontece no repositório em vez do controller, garantindo que nenhum chamador
de `paginate()` possa contornar os limites. O controller lê `$query['limit']` com
padrão `10` quando ausente.

---

## Resumo do contrato de paginação

| Parâmetro de query | Tipo | Padrão | Comportamento |
|---|---|---|---|
| `?limit=N` | inteiro | 10 | Itens por página (limitado 1–100) |
| `?cursor=ID` | string inteira | ausente | Buscar itens com `id < ID`; ausente = primeira página |

| Campo da resposta | Tipo | Significado |
|---|---|---|
| `items` | array | Itens serializados desta página |
| `next_cursor` | int \| null | Passar como `?cursor=` na próxima requisição; `null` = última página |
| `has_more` | bool | `true` se existem mais páginas |

---

## Comparação com paginação por offset

Os built-ins `PaginationQueryParser` / `PaginationResponse` do NENE2 usam `LIMIT ? OFFSET ?`.
Use-os quando:
- Acesso aleatório a páginas é necessário (`?page=5`).
- A contagem total de itens é exibida para o usuário.
- O conjunto de dados é pequeno e raramente cresce durante a travessia.

Use paginação por cursor quando:
- Dados de feed crescem continuamente (chat, fluxos de atividade, logs).
- Travessia estável sob carga de inserção é necessária.
- O conjunto de dados é grande o suficiente para que `OFFSET N` fique lento.

---

## Howtos relacionados

- [`pagination.md`](pagination.md) — paginação por offset com `PaginationQueryParser` e `PaginationResponse`
- [`activity-feed.md`](activity-feed.md) — padrão de feed em tempo real
- [`add-pagination.md`](add-pagination.md) — adicionar paginação a um endpoint existente
