# Como Fazer: Operações em Lote com Semântica de Sucesso Parcial

> **Referência FT**: FT258 (`NENE2-FT/bulklog`) — Criação e exclusão em lote com semântica de sucesso parcial e HTTP 207 Multi-Status

Demonstra como tratar operações de API em lote onde alguns itens podem ter sucesso e outros falhar.
Cada item é processado de forma independente — uma falha de validação no item N não interrompe os itens N+1 em diante.
A resposta carrega dois arrays: `created` (bem-sucedidos) e `errors` (com falha e motivos).
HTTP 207 Multi-Status é retornado quando há uma mistura; 201 Created quando todos têm sucesso.

---

## Rotas

| Método   | Caminho         | Descrição                                       |
|----------|-----------------|-------------------------------------------------|
| `POST`   | `/items`        | Criar um único item                             |
| `GET`    | `/items/{id}`   | Obter um único item                             |
| `POST`   | `/items/bulk`   | Criar itens em lote (sucesso parcial)           |
| `DELETE` | `/items/bulk`   | Excluir itens em lote por ID (sucesso parcial)  |

> **Ordem das rotas**: `/items/bulk` deve ser registrado antes de `/items/{id}` para que o segmento
> literal `bulk` não seja capturado como parâmetro de caminho.

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    sku        TEXT NOT NULL UNIQUE,
    name       TEXT NOT NULL,
    price      INTEGER NOT NULL,
    created_at TEXT NOT NULL
);
```

`sku TEXT NOT NULL UNIQUE` previne SKUs duplicadas no nível do banco de dados. `price INTEGER` armazena o preço na
menor unidade de moeda (centavos) para evitar erros de arredondamento de ponto flutuante.

---

## DTO BulkResult

```php
final readonly class BulkResult
{
    /**
     * @param list<array<string, mixed>> $created
     * @param list<array<string, mixed>> $errors
     */
    public function __construct(
        public array $created,
        public array $errors,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
```

`created` contém os registros criados com sucesso. `errors` contém descritores de erro por item.
`hasErrors()` é um predicado simples que o controller usa para escolher o código de status HTTP.

---

## Criação em lote: validação por item

```php
public function bulkCreate(array $inputs, string $now): BulkResult
{
    $created = [];
    $errors  = [];

    foreach ($inputs as $index => $input) {
        $sku   = isset($input['sku'])   && is_string($input['sku'])   ? trim($input['sku'])   : '';
        $name  = isset($input['name'])  && is_string($input['name'])  ? trim($input['name'])  : '';
        $price = isset($input['price']) && is_int($input['price'])    ? $input['price']       : -1;

        $itemErrors = [];
        if ($sku === '') {
            $itemErrors[] = 'sku is required';
        } elseif ($this->skuExists($sku)) {
            $itemErrors[] = "sku \"{$sku}\" already exists";
        }
        if ($name === '') {
            $itemErrors[] = 'name is required';
        }
        if ($price < 0) {
            $itemErrors[] = 'price must be a non-negative integer';
        }

        if ($itemErrors !== []) {
            $errors[] = ['index' => $index, 'sku' => $sku, 'errors' => $itemErrors];
            continue;   // pular inserção, continuar para o próximo item
        }

        $item      = $this->create($sku, $name, $price, $now);
        $created[] = $item->toArray();
    }

    return new BulkResult($created, $errors);
}
```

**Decisões principais**:
- `continue` em falha de validação: itens com falha não interrompem o loop.
- `$index` é incluído na entrada de erro: os clientes sabem qual posição no array de entrada falhou.
- A unicidade de SKU é verificada no PHP (`skuExists()`) antes do INSERT, não capturada de exceções de banco de dados.
  Isso fornece uma mensagem de erro mais limpa no nível da aplicação em vez de uma violação de restrição bruta.
- Todos os INSERTs bem-sucedidos compartilham o mesmo timestamp `$now`: o lote é tratado como um único ponto no tempo.

---

## Exclusão em lote: rastreamento de não encontrado

```php
public function bulkDelete(array $ids): array
{
    $deleted  = [];
    $notFound = [];

    foreach ($ids as $id) {
        $item = $this->findById($id);
        if ($item === null) {
            $notFound[] = $id;
            continue;
        }
        $this->executor->execute('DELETE FROM items WHERE id = ?', [$id]);
        $deleted[] = $id;
    }

    return ['deleted' => $deleted, 'not_found' => $notFound];
}
```

IDs não encontrados são rastreados mas não interrompem a operação. A resposta permite ao chamador auditar
quais IDs foram realmente excluídos e quais já estavam ausentes. Retornar 200 (não 207) é razoável
aqui porque todas as exclusões solicitadas ou tiveram sucesso ou já estavam ausentes — não há estado de "erro".

---

## Controller: HTTP 207 Multi-Status

```php
private function bulkCreate(ServerRequestInterface $request): ResponseInterface
{
    $body = JsonRequestBodyParser::parse($request);

    if (!isset($body['items']) || !is_array($body['items'])) {
        return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
            'errors' => [['field' => 'items', 'code' => 'required', 'message' => 'items array is required.']],
        ]);
    }

    $inputs = array_values($body['items']);
    $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    $result = $this->repo->bulkCreate($inputs, $now);

    $status = $result->hasErrors() ? 207 : 201;   // ← 207 quando há mistura de sucesso + erro

    return $this->json->create($result->toArray(), $status);
}
```

**Escolha do status HTTP**:

| Resultado | Status | Significado |
|---|---|---|
| Todos criados | `201 Created` | Sucesso total |
| Alguns criados, alguns falharam | `207 Multi-Status` | Sucesso parcial — cliente deve inspecionar o corpo |
| Todos falharam | `207 Multi-Status` | Falha total — array `created` está vazio |
| Sem array `items` | `422 Unprocessable Entity` | Requisição malformada |

`207` sinaliza ao cliente: _não assuma sucesso — inspecione o corpo_. Um cliente que vê `201`
pode assumir que todos os itens foram processados; um cliente que vê `207` deve verificar `errors`.

**Por que não 422 para falha parcial?** `422` significa que toda a requisição é rejeitada. Endpoints de lote
com sucesso parcial processam alguns inputs com sucesso, então `422` seria enganoso.

---

## Controller de exclusão em lote

```php
private function bulkDelete(ServerRequestInterface $request): ResponseInterface
{
    $body = JsonRequestBodyParser::parse($request);

    if (!isset($body['ids']) || !is_array($body['ids'])) {
        return $this->problems->create($request, 'validation-failed', 'Validation Failed', 422, null, [
            'errors' => [['field' => 'ids', 'code' => 'required', 'message' => 'ids array is required.']],
        ]);
    }

    $ids    = array_values(array_filter($body['ids'], 'is_int'));
    $result = $this->repo->bulkDelete($ids);

    return $this->json->create($result);   // sempre 200
}
```

`array_filter($body['ids'], 'is_int')` silenciosamente descarta valores não inteiros do array de IDs.
Esta é uma escolha de design: IDs malformados são ignorados em vez de causar um 422. Uma abordagem alternativa
é rejeitar toda a requisição se qualquer ID não for inteiro.

---

## Exemplo de requisição e resposta

### Criação em lote — sucesso parcial

**Requisição** `POST /items/bulk`:
```json
{
  "items": [
    {"sku": "A001", "name": "Widget A", "price": 1000},
    {"sku": "",     "name": "Bad Item",  "price": 500},
    {"sku": "A001", "name": "Duplicate", "price": 200}
  ]
}
```

**Resposta** `207 Multi-Status`:
```json
{
  "created": [
    {"id": 1, "sku": "A001", "name": "Widget A", "price": 1000, "created_at": "2026-01-01 00:00:00"}
  ],
  "errors": [
    {"index": 1, "sku": "", "errors": ["sku is required"]},
    {"index": 2, "sku": "A001", "errors": ["sku \"A001\" already exists"]}
  ]
}
```

`index` refere-se à posição no array `items` de entrada (base 0). O cliente pode correlacionar
cada erro de volta ao input original sem varrer o payload.

### Exclusão em lote — sucesso parcial

**Requisição** `DELETE /items/bulk`:
```json
{"ids": [1, 999, 2]}
```

**Resposta** `200 OK`:
```json
{
  "deleted": [1, 2],
  "not_found": [999]
}
```

---

## Trade-offs de design

| Abordagem | Comportamento | Quando usar |
|---|---|---|
| Tudo ou nada | Reverter tudo se qualquer falhar | Financeiro, inventário — consistência exigida |
| Sucesso parcial (este padrão) | Processar cada um independentemente | Importação/exportação, ingestão de dados |
| Fila fire-and-forget | Processamento assíncrono, resultados diferidos | Grandes lotes, jobs em background |

Sucesso parcial é apropriado quando os itens são independentes entre si. Se o sucesso do item A
depende do sucesso do item B (ex.: transferência de estoque entre itens), use uma
transação tudo-ou-nada.

---

## Howtos relacionados

- [`transaction-scope-pattern.md`](transaction-scope-pattern.md) — múltiplas escritas atômicas tudo-ou-nada
- [`job-queue-with-retry.md`](job-queue-with-retry.md) — processamento em lote assíncrono via fila de jobs
- [`mass-assignment-defence.md`](mass-assignment-defence.md) — whitelist explícita de DTO por item
