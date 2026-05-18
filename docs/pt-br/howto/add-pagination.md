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

## Veja também

- `src/Example/Note/ListNotesHandler.php` — implementação de referência usando o parser
- `src/Example/Tag/ListTagsHandler.php` — segundo exemplo
- `Nene2\Http\PaginationQuery` — DTO readonly
- `Nene2\Http\PaginationQueryParser` — a classe parser
