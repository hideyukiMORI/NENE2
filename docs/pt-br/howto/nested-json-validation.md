# Como Fazer: Validação de JSON Aninhado

> **Referência FT**: FT322 (`NENE2-FT/nestedlog`) — API de pedidos com validação de itens aninhados, caminhos de erro `items.N.field`, múltiplos erros em uma única resposta, códigos de erro, cálculo de total, 19 testes / 43 asserções PASS.

Este guia mostra como validar arrays JSON aninhados (por ex., itens de linha de pedido) e retornar caminhos de erro estruturados que identificam exatamente qual campo aninhado falhou.

## Schema

```sql
CREATE TABLE orders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    customer   TEXT    NOT NULL,
    total      REAL    NOT NULL DEFAULT 0.0,
    created_at TEXT    NOT NULL
);

CREATE TABLE order_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id   INTEGER NOT NULL REFERENCES orders(id),
    product_id INTEGER NOT NULL,
    quantity   INTEGER NOT NULL,
    unit_price REAL    NOT NULL
);
```

## Endpoints

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/orders` | Criar pedido com itens |
| `GET`  | `/orders` | Listar pedidos |
| `GET`  | `/orders/{id}` | Obter pedido com itens |

## Criar Pedido

```php
POST /orders
{
  "customer": "Alice",
  "items": [
    {"product_id": 1, "quantity": 2, "unit_price": 9.99},
    {"product_id": 2, "quantity": 1, "unit_price": 4.50}
  ]
}
→ 201
{
  "id": 1,
  "customer": "Alice",
  "items": [...],
  "total": 24.48      // 2×9.99 + 1×4.50
}
```

## Caminhos de Erro Aninhados — `items.N.field`

Cada erro de item inclui o índice do array no caminho do campo:

```php
POST /orders
{
  "customer": "Alice",
  "items": [
    {"product_id": "not-an-int", "quantity": 2, "unit_price": 9.99},
    {"product_id": 1, "quantity": 1, "unit_price": -5.0}
  ]
}
→ 422
{
  "errors": [
    {"field": "items.0.product_id", "message": "...", "code": "invalid-type"},
    {"field": "items.1.unit_price",  "message": "...", "code": "min-value"}
  ]
}
```

## Todos os Erros em Uma Resposta

Todas as falhas de validação — tanto no nível superior quanto aninhadas — são coletadas e retornadas juntas. Nunca retorne um erro por vez para submissões em lote:

```php
POST /orders
{
  "customer": "",      // erro: obrigatório
  "items": [
    {"product_id": 0, "quantity": -1, "unit_price": 1.0}  // 2 erros
  ]
}
→ 422
{
  "errors": [
    {"field": "customer",          "code": "required"},
    {"field": "items.0.product_id","code": "min-value"},
    {"field": "items.0.quantity",  "code": "min-value"}
  ]
}
```

## Regras de Validação

| Campo | Regra |
|-------|-------|
| `customer` | Obrigatório, não-vazio, máx 200 chars |
| `items` | Obrigatório, array não-vazio |
| `items[].product_id` | Inteiro, ≥ 1 |
| `items[].quantity` | Inteiro, ≥ 1 |
| `items[].unit_price` | Número (int ou float), > 0 |

## Padrão de Implementação

```php
final class OrderValidator
{
    /** @return list<ValidationError> */
    public function validate(array $data): array
    {
        $errors = [];

        // Validação no nível superior
        $customer = trim($data['customer'] ?? '');
        if ($customer === '') {
            $errors[] = new ValidationError('customer', 'required', 'required');
        } elseif (strlen($customer) > 200) {
            $errors[] = new ValidationError('customer', 'max 200 chars', 'max-length');
        }

        $items = $data['items'] ?? null;
        if (!is_array($items) || $items === []) {
            $errors[] = new ValidationError('items', 'required non-empty array', 'required');
            return $errors;  // não pode validar itens mais
        }

        // Validação de item aninhado com índice
        foreach ($items as $i => $item) {
            $prefix = "items.{$i}";

            $productId = $item['product_id'] ?? null;
            if (!is_int($productId) || $productId < 1) {
                $errors[] = new ValidationError("{$prefix}.product_id", 'must be int >= 1', 'min-value');
            }

            $quantity = $item['quantity'] ?? null;
            if (!is_int($quantity) || $quantity < 1) {
                $errors[] = new ValidationError("{$prefix}.quantity", 'must be int >= 1', 'min-value');
            }

            $price = $item['unit_price'] ?? null;
            if ((!is_int($price) && !is_float($price)) || $price <= 0) {
                $errors[] = new ValidationError("{$prefix}.unit_price", 'must be number > 0', 'min-value');
            }
        }

        return $errors;
    }
}
```

## Códigos de Erro

| Código | Significado |
|--------|-------------|
| `required` | Campo ausente ou vazio |
| `max-length` | Excede o comprimento máximo |
| `min-value` | Abaixo do valor mínimo (int/float) |
| `invalid-type` | Tipo errado (por ex. string onde int era esperado) |

---

## O Que NÃO Fazer

| Anti-padrão | Risco |
|---|---|
| Retornar apenas o primeiro erro | Cliente deve submeter, receber erro, corrigir, resubmeter N vezes — UX terrível para formulários em lote |
| Caminho de erro plano `"product_id"` para itens aninhados | Cliente não consegue identificar qual item (índice 0, 1, ...) falhou |
| Aceitar `unit_price: 0` silenciosamente | Itens com preço zero corrompem totais do pedido |
| Validar itens somente após o nível superior passar | Atrasa o feedback; colete todos os erros em uma única passagem |
| Parar validação no primeiro erro de item | Mascarar outros erros nos itens restantes |
