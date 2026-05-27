# Como Fazer: Padrão CQRS

> **Referência FT**: FT233 (`NENE2-FT/cqrslog`) — API de Padrão CQRS

Demonstra a Segregação de Responsabilidade de Comando e Consulta (CQRS): o lado de escrita aceita
Commands e muta o modelo de escrita; o lado de leitura aceita Queries e lê de um modelo de leitura
desnormalizado (SQL VIEW). Os dois lados compartilham o mesmo banco de dados SQLite, mas têm
classes de handler separadas, objetos de modelo separados e nenhum estado compartilhado.

---

## Conceitos fundamentais do CQRS

| Conceito | Descrição |
|---------|-----------|
| **Command** | Uma intenção de mudar estado — `PlaceOrderCommand`, `UpdateOrderStatusCommand` |
| **Query** | Uma solicitação de dados — `GetOrderSummaryQuery`, `ListOrderSummariesQuery` |
| **CommandHandler** | Executa um command contra o modelo de escrita (tabelas normalizadas) |
| **QueryHandler** | Executa uma query contra o modelo de leitura (view desnormalizada) |
| **Modelo de escrita** | Tabelas normalizadas otimizadas para escritas transacionais |
| **Modelo de leitura** | View desnormalizada otimizada para o formato de saída da consulta |

---

## Rotas

| Método  | Caminho               | Lado   | Descrição                      |
|---------|-----------------------|--------|--------------------------------|
| `POST`  | `/orders`             | Escrita | Fazer um novo pedido (command) |
| `PATCH` | `/orders/{id}/status` | Escrita | Atualizar status do pedido (command) |
| `GET`   | `/orders`             | Leitura | Listar resumos de pedidos (query) |
| `GET`   | `/orders/{id}`        | Leitura | Obter um resumo de pedido (query) |

---

## Objetos Command (lado de escrita)

Commands são value objects imutáveis que carregam dados validados para o handler:

```php
final readonly class PlaceOrderCommand
{
    /**
     * @param list<array{product: string, quantity: int, unit_price: int}> $items
     */
    public function __construct(
        public string $customer,
        public array  $items,
    ) {
    }
}

final readonly class UpdateOrderStatusCommand
{
    public function __construct(
        public int    $orderId,
        public string $newStatus,
    ) {
    }
}
```

Commands não contêm lógica de negócio — são containers tipados para o input validado do controller.
Usar `readonly` previne mutação após a construção.

---

## Command handler (modelo de escrita)

`OrderCommandHandler` é dono de todas as mutações. Escreve em tabelas normalizadas:

```php
final readonly class OrderCommandHandler
{
    public function __construct(private DatabaseQueryExecutorInterface $executor) {}

    public function place(PlaceOrderCommand $command, string $now): int
    {
        $orderId = $this->executor->insert(
            'INSERT INTO orders (customer, status, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$command->customer, 'pending', $now, $now],
        );

        foreach ($command->items as $item) {
            $this->executor->insert(
                'INSERT INTO order_items (order_id, product, quantity, unit_price) VALUES (?, ?, ?, ?)',
                [$orderId, $item['product'], $item['quantity'], $item['unit_price']],
            );
        }

        return $orderId;
    }

    public function updateStatus(UpdateOrderStatusCommand $command, string $now): bool
    {
        $affected = $this->executor->execute(
            'UPDATE orders SET status = ?, updated_at = ? WHERE id = ?',
            [$command->newStatus, $now, $command->orderId],
        );

        return $affected > 0;
    }
}
```

O handler retorna valores primitivos (`int` orderId, `bool` sucesso) — não objetos do modelo de leitura.
Após um command ter sucesso, o controller re-consulta o lado de leitura para obter o formato de resposta.

---

## Objetos Query (lado de leitura)

Queries são wrappers tipados para parâmetros de consulta:

```php
final readonly class GetOrderSummaryQuery
{
    public function __construct(public int $orderId) {}
}

final readonly class ListOrderSummariesQuery
{
    public function __construct(public ?string $status) {}
}
```

Encapsular parâmetros de query em objetos torna o contrato do query handler explícito e
evita primitive-obsession nas assinaturas dos handlers.

---

## Query handler (modelo de leitura)

`OrderQueryHandler` lê de `order_summary`, uma SQL VIEW que desnormaliza o
join na camada do BD:

```php
final readonly class OrderQueryHandler
{
    public function __construct(private DatabaseQueryExecutorInterface $executor) {}

    public function get(GetOrderSummaryQuery $query): ?OrderSummary
    {
        $rows = $this->executor->fetchAll(
            'SELECT * FROM order_summary WHERE id = ?',
            [$query->orderId],
        );

        return $rows !== [] ? $this->hydrate($rows[0]) : null;
    }

    /** @return list<OrderSummary> */
    public function list(ListOrderSummariesQuery $query): array
    {
        if ($query->status !== null) {
            $rows = $this->executor->fetchAll(
                'SELECT * FROM order_summary WHERE status = ? ORDER BY created_at DESC',
                [$query->status],
            );
        } else {
            $rows = $this->executor->fetchAll(
                'SELECT * FROM order_summary ORDER BY created_at DESC',
                [],
            );
        }

        return array_map($this->hydrate(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): OrderSummary
    {
        return new OrderSummary(
            id:         (int) $row['id'],
            customer:   (string) $row['customer'],
            status:     (string) $row['status'],
            createdAt:  (string) $row['created_at'],
            itemCount:  (int) $row['item_count'],
            totalCents: (int) ($row['total_cents'] ?? 0),
        );
    }
}
```

`OrderSummary` é um DTO do modelo de leitura — nunca é escrito; representa apenas
um resultado de consulta. Mantê-lo separado de qualquer entidade `Order` do lado de escrita previne
que preocupações do lado de leitura vazem para o modelo de escrita.

---

## Modelo de leitura: SQL VIEW como projeção desnormalizada

O modelo de leitura é uma SQLite `VIEW` que pré-computa o join e a agregação:

```sql
CREATE VIEW IF NOT EXISTS order_summary AS
SELECT
    o.id,
    o.customer,
    o.status,
    o.created_at,
    COUNT(oi.id)                     AS item_count,
    SUM(oi.quantity * oi.unit_price) AS total_cents
FROM orders o
LEFT JOIN order_items oi ON oi.order_id = o.id
GROUP BY o.id;
```

A view fornece uma superfície de consulta estável — o query handler não precisa conhecer
o join normalizado `orders`/`order_items`. Se o modelo de escrita mudar sua estrutura de
tabelas, apenas a definição da view precisa ser atualizada, não o query handler.

`total_cents` armazena valores monetários como centavos inteiros (sem erros de arredondamento de ponto flutuante).
`?? 0` protege contra `NULL` quando nenhum item existe.

---

## Schema do modelo de escrita

```sql
CREATE TABLE IF NOT EXISTS orders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    customer   TEXT    NOT NULL,
    status     TEXT    NOT NULL DEFAULT 'pending',
    created_at TEXT    NOT NULL,
    updated_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS order_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id   INTEGER NOT NULL REFERENCES orders(id),
    product    TEXT    NOT NULL,
    quantity   INTEGER NOT NULL,
    unit_price INTEGER NOT NULL
);
```

O modelo de escrita é normalizado: `orders` + `order_items` em um relacionamento 1:N. Sem
colunas computadas — a projeção de leitura está na view.

---

## Controller: conectando commands e queries

Após um command de escrita ter sucesso, o controller usa o lado de leitura para construir a resposta:

```php
private function placeOrder(ServerRequestInterface $request): ResponseInterface
{
    // ... validar input ...
    $orderId = $this->commands->place(new PlaceOrderCommand($customer, $items), $now);

    // Re-consultar via modelo de leitura para obter o formato de resposta
    $summary = $this->queries->get(new GetOrderSummaryQuery($orderId));

    return $this->json->create($summary->toArray(), 201);
}
```

Esse padrão "command then query" mantém o lado de escrita ignorante do formato de resposta e
garante que a resposta sempre reflita a projeção da view (incluindo campos computados
como `total_cents`).

Validação de item antes do command:

```php
foreach ($rawItems as $item) {
    if (!is_array($item)) continue;

    $product   = isset($item['product']) && is_string($item['product']) ? trim($item['product']) : '';
    $quantity  = isset($item['quantity']) && is_int($item['quantity']) && $item['quantity'] > 0 ? $item['quantity'] : 0;
    $unitPrice = isset($item['unit_price']) && is_int($item['unit_price']) && $item['unit_price'] >= 0 ? $item['unit_price'] : -1;

    if ($product === '' || $quantity === 0 || $unitPrice < 0) {
        return $this->json->create(['error' => 'each item needs product, quantity>0, unit_price>=0'], 422);
    }
    $items[] = ['product' => $product, 'quantity' => $quantity, 'unit_price' => $unitPrice];
}
```

Verificação estrita `is_int()` em `quantity` e `unit_price` rejeita floats e strings do
JSON. `unit_price >= 0` permite zero (itens gratuitos); `quantity > 0` exige pelo menos um.

---

## Quando usar CQRS

CQRS adiciona overhead estrutural. Use quando:

- Os formatos de leitura e escrita divergem significativamente (ex.: lista precisa de agregados que o
  modelo de escrita não armazena)
- A carga de leitura supera muito a de escrita e você quer escalá-las independentemente
- O domínio tem invariantes complexas de escrita (transações, validação, domain events) que
  devem ser isoladas das otimizações de leitura
- Você está caminhando para event sourcing (CQRS se encaixa naturalmente com modelos de escrita baseados em eventos)

Evite CQRS quando:
- Os formatos de leitura e escrita são idênticos (um endpoint CRUD simples)
- O codebase é pequeno e a indireção supera o benefício de clareza
- O time não está familiarizado com o padrão (introduz overhead cognitivo)

---

## Howtos relacionados

- [`event-sourcing.md`](event-sourcing.md) — lado de escrita CQRS respaldado por um event store
- [`approval-workflow.md`](approval-workflow.md) — máquina de estados para transições de status de pedido
- [`transactions.md`](transactions.md) — encapsular escritas de command em uma transação
- [`batch-api-partial-success.md`](batch-api-partial-success.md) — commands em lote com resultados por item
