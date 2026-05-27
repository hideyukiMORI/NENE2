# Transações de Banco de Dados

Use `DatabaseTransactionManagerInterface::transactional()` para operações atômicas com múltiplos passos.
Se qualquer passo lançar uma exceção, todas as alterações no callback são revertidas automaticamente.

## A Regra Crítica: Instanciar Repositórios Dentro do Callback

> **Atenção:** Repositórios injetados no momento da construção usam uma conexão PDO *diferente* e executam **fora da transação**. Rollbacks não desfazem suas alterações.

`PdoConnectionFactory` cria uma nova conexão a cada chamada. Quando `transactional()` abre uma transação, ela a abre em uma *nova* conexão. Qualquer repositório criado antes dessa chamada (por exemplo, injetado via DI no construtor) mantém uma conexão diferente — uma que não faz parte da transação.

### Padrão correto

```php
final class OrderService
{
    public function __construct(
        private readonly DatabaseTransactionManagerInterface $tx,
    ) {}

    public function placeOrder(array $items): int
    {
        return $this->tx->transactional(function ($executor) use ($items): int {
            // ✅ Instancie os repositórios DENTRO do callback com o executor com escopo de tx
            $inventory = new InventoryRepository($executor);
            $orders    = new OrderRepository($executor);

            foreach ($items as $item) {
                // lança InsufficientStockException → rollback acionado automaticamente
                $inventory->decrement($item['product_id'], $item['quantity']);
            }

            return $orders->create($items);
        });
    }
}
```

### Padrão errado (alterações NÃO são revertidas)

```php
// ❌ Esses repositórios mantêm uma conexão diferente — não fazem parte da transação
public function __construct(
    private readonly InventoryRepository $inventory,
    private readonly OrderRepository $orders,
    private readonly DatabaseTransactionManagerInterface $tx,
) {}

public function placeOrder(array $items): int
{
    return $this->tx->transactional(function ($executor) use ($items): int {
        foreach ($items as $item) {
            $this->inventory->decrement(...); // ← conexão diferente, fora da transação!
        }
        return $this->orders->create($items); // ← mesmo problema
        // Se uma exceção for lançada, as alterações em $this->inventory NÃO são desfeitas
    });
}
```

Esta é uma falha silenciosa: o código compila, o PHPStan não consegue detectá-la, e os testes podem passar a menos que verifiquem especificamente o comportamento de rollback.

---

## Comportamento de Rollback

`transactional()` captura `Throwable`, reverte, então relança. O chamador recebe a exceção original.

```php
try {
    $this->orderService->placeOrder($items);
} catch (InsufficientStockException $e) {
    // Todos os decrementos de estoque desta chamada são desfeitos
    // Nenhum pedido foi criado
}
```

---

## Padrão de Pré-Validação + Operação Atômica

O padrão fail-fast acima para no primeiro item sem estoque, deixando o cliente sem saber das outras falhas.
Quando a UX importa, colete todos os erros primeiro, então execute atomicamente:

```php
public function placeOrder(array $items): int
{
    // Fase 1: validar todos os itens fora da transação (somente leitura)
    $errors = [];
    foreach ($items as $item) {
        $stock = $this->getStockSnapshot($item['product_id']);
        if ($stock < $item['quantity']) {
            $errors[] = [
                'product_id' => $item['product_id'],
                'requested'  => $item['quantity'],
                'available'  => $stock,
            ];
        }
    }

    if ($errors !== []) {
        throw new InsufficientStockException($errors);
    }

    // Fase 2: decremento atômico — ainda use o executor com escopo de tx dentro
    return $this->tx->transactional(function ($executor) use ($items): int {
        $inventory = new InventoryRepository($executor);
        $orders    = new OrderRepository($executor);

        foreach ($items as $item) {
            // A restrição CHECK do banco é a última proteção para corridas concorrentes
            $inventory->decrement($item['product_id'], $item['quantity']);
        }

        return $orders->create($items);
    });
}
```

**Trade-offs:**
- A leitura na Fase 1 não faz parte da transação, então uma requisição concorrente pode esgotar o estoque entre a Fase 1 e a Fase 2. A restrição `CHECK (stock >= 0)` do banco (ou `decrement()` lançando em estoque negativo) captura essa corrida.
- Para a maioria das aplicações isso é aceitável. Para correção estrita, use `SELECT ... FOR UPDATE` ou nível de isolamento serializável (não disponível no SQLite).

---

## Testando a Correção do Rollback

Sempre verifique que o estoque está inalterado após um pedido com falha:

```php
$this->inventory->seed(1, 'Widget', 10);
$this->inventory->seed(2, 'Gadget', 1);

// O decremento de Widget teria sucesso, mas Gadget falha → ambos devem ser revertidos
$res = $this->post('/orders', ['items' => [
    ['product_id' => 1, 'quantity' => 3],
    ['product_id' => 2, 'quantity' => 5], // apenas 1 em estoque
]]);

assertSame(422, $res->getStatusCode());
assertSame(10, $this->inventory->getStock(1), 'Widget deve ser revertido para 10');
assertSame(0, $this->orders->count(), 'Nenhum pedido criado');
```

Testes unitários que mockam repositórios não conseguem capturar esta classe de bug — apenas testes de integração que compartilham a mesma conexão SQLite/MySQL conseguem verificar a correção do rollback.

---

## Laravel vs NENE2

O `DB::transaction()` do Laravel funciona com models injetados porque transparentemente compartilha uma conexão por requisição. O `PdoConnectionFactory` do NENE2 retorna uma nova conexão a cada chamada — uma escolha de design deliberada para testabilidade e controle explícito de conexão. A consequência é que o padrão de executor com escopo no callback é **obrigatório** no NENE2.
