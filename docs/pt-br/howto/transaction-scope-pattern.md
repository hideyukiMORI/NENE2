# Como fazer: Padrão de Escopo de Transação

> **Referência FT**: FT253 (`NENE2-FT/txlog`) — Fronteiras de transação de banco de dados: pedido atômico com dedução de estoque

Demonstra o padrão correto para `DatabaseTransactionManagerInterface::transactional()`
no NENE2. Os repositórios devem ser instanciados **dentro** do callback com o
executor com escopo de transação — repositórios pré-injetados operam em uma conexão diferente
e seus writes **não são** revertidos se a transação falhar.

---

## Rotas

| Método | Caminho    | Descrição                                          |
|--------|------------|----------------------------------------------------|
| `POST` | `/orders`  | Fazer um pedido (dedução atômica de estoque + criação do pedido) |

---

## Schema

```sql
CREATE TABLE IF NOT EXISTS inventory (
    product_id   INTEGER PRIMARY KEY,
    product_name TEXT    NOT NULL,
    stock        INTEGER NOT NULL CHECK (stock >= 0)
);

CREATE TABLE IF NOT EXISTS orders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    status     TEXT    NOT NULL DEFAULT 'placed',
    created_at TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS order_items (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id   INTEGER NOT NULL REFERENCES orders(id),
    product_id INTEGER NOT NULL,
    quantity   INTEGER NOT NULL
);
```

`CHECK(stock >= 0)` é uma proteção no nível do banco que previne que o estoque fique negativo
mesmo que a lógica de aplicação tenha um bug. A aplicação também valida o estoque antes de
decrementá-lo, então em operação normal a restrição nunca é acionada.

---

## A armadilha do escopo do executor

`DatabaseTransactionManagerInterface::transactional()` abre uma transação e passa um
**executor com escopo de transação** para o callback. Writes realizados através desse executor fazem
parte da transação e são revertidos se uma exceção for lançada.

**❌ Errado: repositório injetado antes da transação**

```php
final class OrderService
{
    public function __construct(
        private readonly InventoryRepository $inventory, // usa executor externo
        private readonly OrderRepository     $orders,    // usa executor externo
        private readonly DatabaseTransactionManagerInterface $tx,
    ) {}

    public function placeOrder(array $items): int
    {
        return $this->tx->transactional(function ($txExecutor) use ($items): int {
            // BUG: $this->inventory e $this->orders usam o executor externo,
            // não $txExecutor. Seus writes estão em uma conexão diferente.
            // Se a transação for revertida, suas alterações NÃO são desfeitas.
            foreach ($items as $item) {
                $this->inventory->decrement($item['product_id'], $item['quantity']);
            }
            return $this->orders->create($items);
        });
    }
}
```

**✅ Correto: repositórios criados dentro do callback**

```php
final class OrderService
{
    public function __construct(
        private readonly DatabaseTransactionManagerInterface $tx,
        // Injete apenas o gerenciador de transação, não os repositórios.
    ) {}

    public function placeOrder(array $items): int
    {
        return $this->tx->transactional(function ($executor) use ($items): int {
            // Os repositórios são instanciados com o executor com escopo de transação.
            // Todas as leituras e writes passam pela mesma conexão, na mesma transação.
            $inventory = new InventoryRepository($executor);
            $orders    = new OrderRepository($executor);

            foreach ($items as $item) {
                // Lança InsufficientStockException → aciona rollback
                $inventory->decrement($item['product_id'], $item['quantity']);
            }

            return $orders->create($items);
        });
    }
}
```

O `$executor` com escopo de transação passado pelo `transactional()` compartilha a mesma conexão PDO
e contexto de transação. Criar repositórios dentro do callback com
esse executor garante que todos os seus writes participem da mesma transação atômica.

---

## Decremento de estoque com verificação no nível da aplicação

```php
public function decrement(int $productId, int $qty): void
{
    $current = $this->getStock($productId);
    if ($current < $qty) {
        throw new InsufficientStockException($productId, $qty, $current);
    }
    $this->executor->execute(
        'UPDATE inventory SET stock = stock - ? WHERE product_id = ?',
        [$qty, $productId],
    );
}
```

O padrão leitura-então-escrita (`getStock()` + `UPDATE`) não é atômico por si só —
uma requisição concorrente poderia ler o mesmo estoque e ambas terem sucesso. Para uso em produção,
envolva isso em um `SELECT ... FOR UPDATE` (MySQL) ou use `UPDATE ... WHERE stock >= ?`
como verificação otimista:

```sql
UPDATE inventory SET stock = stock - ? WHERE product_id = ? AND stock >= ?
-- então verifique se linhas afetadas == 1; se 0, lança InsufficientStockException
```

SQLite não suporta `SELECT FOR UPDATE`, então a restrição `CHECK(stock >= 0)`
captura corridas concorrentes no nível do banco — o segundo update lançaria uma violação de restrição,
que se propaga como exceção e aciona o rollback.

---

## Atomicidade multi-item: falha parcial aciona rollback completo

```php
foreach ($items as $item) {
    $inventory->decrement($item['product_id'], $item['quantity']); // pode lançar
}
return $orders->create($items); // só alcançado se todos os decrementos tiverem sucesso
```

Se o **primeiro** item falhar, nenhuma alteração de estoque é feita. Se o **último** item falhar,
todos os decrementos anteriores são revertidos. O conjunto de testes verifica ambos os casos:

```php
// Widget: 10 em estoque; Gadget: 1 em estoque. Pedido [Widget×3, Gadget×5] falha no Gadget.
$this->assertSame(10, $inventory->getStock(1)); // Widget restaurado para 10
$this->assertSame(0, $orders->count());          // Nenhum pedido criado
```

---

## Exceção → rollback → mapeamento 422

A exceção lançada dentro de `transactional()` propaga para o chamador:

```php
try {
    $orderId = $this->service->placeOrder($items);
    return $this->json->create(['order_id' => $orderId, 'status' => 'placed'], 201);
} catch (InsufficientStockException $e) {
    return $this->problems->create(
        $request,
        'insufficient-stock',
        'Insufficient stock.',
        422,
        $e->getMessage(),
    );
}
```

`transactional()` captura a exceção, chama `ROLLBACK`, então relança. O chamador
captura a exceção relançada e a mapeia para uma resposta Problem Details.

---

## Validação de entrada: `is_int` estrito para quantidades

```php
foreach ($body['items'] as $i => $item) {
    if (
        !is_array($item) ||
        !isset($item['product_id'], $item['quantity']) ||
        !is_int($item['product_id']) ||
        !is_int($item['quantity']) ||
        $item['quantity'] < 1
    ) {
        return $this->problems->create(
            $request,
            'validation-failed',
            'Validation failed.',
            422,
            null,
            ['errors' => [['field' => "items.{$i}", 'code' => 'invalid', ...]]],
        );
    }
}
```

`is_int()` rejeita floats JSON (`1.0`) e strings (`"3"`). O decodificador JSON do PHP converte
valores inteiros JSON para `int` do PHP, então `is_int()` funciona corretamente para entrada JSON.
Use `is_numeric()` apenas para parâmetros de query string (que sempre são strings).

---

## Resumo de uso do `transactional()`

| Faça | Não faça |
|---|---|
| Criar repositórios dentro do callback | Injetar repositórios na construção da classe |
| Passar `$executor` do callback para novos repositórios | Usar `$this->executor` injetado no construtor |
| Deixar exceções propagar para acionar rollback | Capturar exceções silenciosamente dentro do callback |
| Retornar um valor do callback | Retornar `void` quando você precisa do ID inserido |

---

## Howtos relacionados

- [`transactions.md`](transactions.md) — referência da API `DatabaseTransactionManagerInterface`
- [`use-transactions.md`](use-transactions.md) — adicionando transações a um endpoint existente
- [`budget-tracking.md`](budget-tracking.md) — transferir fundos com transação (atualização de saldo de duas contas)
- [`order-management.md`](order-management.md) — ciclo de vida completo do pedido (criar, status, cancelar)
- [`optimistic-locking.md`](optimistic-locking.md) — prevenção de condição de corrida sem transações
