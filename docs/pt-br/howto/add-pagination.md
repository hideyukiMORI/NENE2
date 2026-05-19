# Adicionar paginação

Este guia mostra como adicionar paginação `?limit=` / `?offset=` a um endpoint de coleção usando o helper `PaginationQueryParser` do `Nene2\Http`.

## Pré-requisitos

- Um handler de coleção funcional (ex. `ListNotesHandler`).
- O handler retorna um envelope JSON com `items`, `limit` e `offset`.

## Passo 1 — Chamar `PaginationQueryParser::parse()`

Substitua a extração manual de parâmetros de query pelo parser. Ele valida os valores e lança `ValidationException` (→ 422) quando estão fora do intervalo.

```php
use Nene2\Http\PaginationQueryParser;

public function handle(ServerRequestInterface $request): ResponseInterface
{
    $pagination = PaginationQueryParser::parse($request); // padrão: limit=20, max=100

    $output = $this->useCase->execute(
        new ListWidgetsInput($pagination->limit, $pagination->offset),
    );

    return $this->response->create([
        'items'  => /* mapear $output->items */,
        'limit'  => $output->limit,
        'offset' => $output->offset,
    ]);
}
```

`PaginationQuery` é um DTO readonly com duas propriedades: `limit: int` e `offset: int`.

## Passo 2 — Personalizar os limites (opcional)

Passe `$defaultLimit` e `$maxLimit` para substituir os padrões (20 e 100):

```php
$pagination = PaginationQueryParser::parse($request, defaultLimit: 10, maxLimit: 50);
```

| Parâmetro | Padrão | Significado |
|---|---|---|
| `$defaultLimit` | `20` | Usado quando `?limit=` está ausente |
| `$maxLimit` | `100` | Valor máximo permitido; retorna 422 se excedido |

## Passo 3 — Tratar o erro 422

`PaginationQueryParser::parse()` lança `ValidationException` quando:

- `limit < 1` ou `limit > $maxLimit`
- `offset < 0`

`ErrorHandlerMiddleware` mapeia automaticamente `ValidationException` → `422 validation-failed`.
Nenhum tratamento de erro adicional é necessário no handler.

**Exemplo de resposta 422:**

```json
{
  "type": "https://nene2.dev/problems/validation-failed",
  "title": "Validation Failed",
  "status": 422,
  "detail": "The request body contains invalid values.",
  "errors": [
    { "field": "limit", "message": "limit must be between 1 and 100.", "code": "out_of_range" }
  ]
}
```

## Como funciona

`PaginationQueryParser::parse()` lê `getQueryParams()` da requisição PSR-7, converte os valores para `int`, valida-os e retorna um DTO `PaginationQuery`. Valores não numéricos são convertidos para `0` (comportamento de cast `(int)` do PHP) e então capturados pela verificação `limit < 1`.

## Passo 4 — Usar `PaginationResponse` para padronizar o envelope

`PaginationResponse` é um DTO readonly que constrói o envelope de lista padrão:

```php
use Nene2\Http\PaginationResponse;

return $this->response->create(
    (new PaginationResponse(
        items:  array_map(fn ($item) => ['id' => $item->id, 'name' => $item->name], $output->items),
        limit:  $output->limit,
        offset: $output->offset,
    ))->toArray(),
);
```

## Passo 5 — Incluir o total de registros (opcional)

Passe `total` quando o repositório suportar uma consulta COUNT:

```php
$total = $this->repository->countAll(); // SELECT COUNT(*) AS n FROM ...

return $this->response->create(
    (new PaginationResponse(items: /* ... */, limit: $output->limit, offset: $output->offset, total: $total))->toArray(),
);
```

Quando `total` é `null` (padrão), a chave é omitida da resposta.

> **Compromisso**: `COUNT(*)` adiciona uma consulta por chamada. Omita `total` se o overhead
> for inaceitável e deixe os clientes detectar a última página com `items.length < limit`.

## Veja também

- `src/Example/Note/ListNotesHandler.php` — implementação de referência com `PaginationResponse`
- `src/Example/Tag/ListTagsHandler.php` — segundo exemplo
- `Nene2\Http\PaginationQuery` — DTO readonly para parâmetros analisados
- `Nene2\Http\PaginationQueryParser` — a classe parser
- `Nene2\Http\PaginationResponse` — o DTO de envelope de lista
