# Usar Transações de Banco de Dados

Este guia explica como realizar operações atômicas com múltiplos passos usando
`DatabaseTransactionManagerInterface` no NENE2.

**Pré-requisito**: Você tem um repositório apoiado por `DatabaseQueryExecutorInterface`.
Caso contrário, comece com [Adicionar um endpoint com banco de dados](./add-database-endpoint.md).

> **🚫 O SQLite `:memory:` é incompatível com `transactional()`.**
>
> `PdoDatabaseTransactionManager` abre uma *nova* conexão a cada chamada. Cada conexão `:memory:`
> aponta para um banco de dados em memória vazio *diferente*, de modo que o executor e a transação
> veem dados distintos e os rollbacks não têm efeito algum sobre a visão do executor. O sintoma é
> silencioso: consultas retornam `null` no meio do callback, ou rollbacks não desfazem escritas que
> o teste realizou por meio de um executor separado.
>
> Use um banco SQLite **baseado em arquivo** nos testes (veja "[Testar com um banco de dados SQLite
> baseado em arquivo](#testar-com-um-banco-de-dados-sqlite-baseado-em-arquivo)" abaixo).
> `Nene2\Testing\DatabaseTestKit::sqlite(':memory:')` rejeita `:memory:` com
> `InvalidArgumentException` para que a falha ocorra imediatamente.

---

## Por que usar transações no NENE2

`DatabaseTransactionManagerInterface` envolve múltiplas instruções SQL em uma única transação:
ou todas têm sucesso (commit) ou todas são revertidas em qualquer `Throwable`.

A interface tem um método:

```php
public function transactional(callable $callback): mixed;
```

O callback recebe um `DatabaseQueryExecutorInterface` **fresco** vinculado à transação aberta.
**Este executor é diferente do que você injeta no momento da construção.**

---

## O padrão de repositório transacional

> **Atenção — não reutilize repositórios injetados dentro do callback.**
>
> Repositórios injetados no momento da construção mantêm uma **conexão diferente** da que
> a transação usa. Usá-los dentro do callback significa que suas queries executam
> fora da transação: rollbacks não desfazem essas alterações, e linhas não commitadas
> escritas dentro do callback podem não ser visíveis para eles.
>
> Esse erro compila e os testes podem passar — o bug só aparece em escritas concorrentes
> ou quando você depende do comportamento de rollback.

Como o callback fornece seu próprio executor, você deve **instanciar classes de repositório
dentro do callback** usando o executor fornecido pelo callback.

```php
<?php

declare(strict_types=1);

namespace MyApp\Order;

use MyApp\Product\ProductNotFoundException;
use MyApp\Product\SqliteProductRepository;
use Nene2\Database\DatabaseQueryExecutorInterface;
use Nene2\Database\DatabaseTransactionManagerInterface;

final class CreateOrderUseCase
{
    public function __construct(
        private readonly DatabaseTransactionManagerInterface $transactionManager,
    ) {}

    public function execute(int $productId, int $qty): Order
    {
        return $this->transactionManager->transactional(
            function (DatabaseQueryExecutorInterface $tx) use ($productId, $qty): Order {
                // Deve instanciar classes concretas aqui — o executor $tx está vinculado à
                // conexão desta transação. Instâncias injetadas usam um executor diferente.
                $products = new SqliteProductRepository($tx);
                $orders   = new SqliteOrderRepository($tx);

                $product = $products->findById($productId)
                    ?? throw new ProductNotFoundException($productId);

                $products->decrementStock($productId, $qty);

                return $orders->save($product->price * $qty, [
                    new OrderItem($productId, $qty, $product->price),
                ]);
            },
        );
    }
}
```

### Por que não reutilizar repositórios injetados?

`PdoDatabaseTransactionManager::transactional()` abre uma **nova conexão** via
`DatabaseConnectionFactoryInterface::create()` e inicia uma transação nela.
O executor do callback é vinculado a essa conexão específica.

Um `SqliteProductRepository` injetado mantém um `PdoDatabaseQueryExecutor` separado que
abre sua própria conexão preguiçosamente no primeiro uso. Queries através do repositório injetado
executam nessa outra conexão — fora da transação — então um rollback não as desfará,
e um insert através do repositório injetado pode não ver linhas não commitadas do callback.

---

## Conectar no seu front controller

Você precisa de dois objetos separados:

| Objeto | Propósito |
|---|---|
| `PdoDatabaseQueryExecutor` | Leituras não-transacionais (por exemplo, `GET /products`) |
| `PdoDatabaseTransactionManager` | Envolve escritas com múltiplos passos em uma transação |

Ambos compartilham o mesmo `PdoConnectionFactory`:

```php
$connectionFactory = new PdoConnectionFactory($dbConfig);

$executor  = new PdoDatabaseQueryExecutor($connectionFactory);  // para repositórios de leitura
$txManager = new PdoDatabaseTransactionManager($connectionFactory); // para casos de uso

$products = new SqliteProductRepository($executor);  // usado por GET /products
$createOrder = new CreateOrderUseCase($txManager);   // usa $tx internamente
```

---

## Testar com banco de dados SQLite baseado em arquivo

SQLite em memória (`sqlite::memory:`) cria um **banco de dados separado por conexão**, então
`PdoDatabaseTransactionManager` (que abre uma nova conexão por chamada `transactional()`)
não veria linhas escritas pelo `PdoDatabaseQueryExecutor` e vice-versa.

Use um **arquivo temporário** em vez disso. `Nene2\Testing\DatabaseTestKit` conecta o executor e o
gerenciador de transações ao mesmo arquivo em uma linha:

```php
use Nene2\Testing\DatabaseTestKit;

protected function setUp(): void
{
    $this->dbFile = sys_get_temp_dir() . '/' . uniqid('test-', true) . '.sqlite';

    // Aplique o schema em uma conexão descartável e feche-a antes que o kit abra a sua.
    $pdo = new \PDO('sqlite:' . $this->dbFile, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    unset($pdo);

    $this->kit = DatabaseTestKit::sqlite($this->dbFile);
    // $this->kit->queryExecutor       — para repositórios de leitura
    // $this->kit->transactionManager  — para casos de uso
    // $this->kit->connectionFactory   — se precisar construir executores adicionais
}

protected function tearDown(): void
{
    if (is_file($this->dbFile)) {
        unlink($this->dbFile);
    }
}
```

O kit fica em `Nene2\Testing\DatabaseTestKit` (ADR 0012, API pública). Ele conecta internamente
`PdoConnectionFactory` + `PdoDatabaseQueryExecutor` + `PdoDatabaseTransactionManager`, todos
compartilhando o mesmo arquivo, de modo que os testes não precisam referenciar nenhuma classe
`@internal` pelo nome. Tanto `DatabaseTestKit::sqlite(':memory:')` quanto as combinações de
configuração subjacentes são bloqueadas na fábrica.

> **`DatabaseConfig::sqlite(string $path)`** é o atalho equivalente quando você quer manter a
> conexão explícita (por exemplo, para injetar sua própria subclasse de `PdoConnectionFactory`).
> Ele substitui a forma de 9 argumentos `new DatabaseConfig(...)` mostrada em guias mais antigos.

---

## Verificar comportamento de rollback

Um caso de uso que envolve múltiplas escritas só está correto se o caminho de rollback
**realmente desfizer** as escritas anteriores. Um teste que cobre apenas o caminho feliz passará
mesmo quando o caso de uso esquecer de usar `$tx` — `$this->products` será executado fora da
transação e comitado silenciosamente. O teste de rollback é o que pega o bug.

### Rollback em nível de unidade

Conduza o caso de uso diretamente com `DatabaseTestKit` e verifique o estado do banco após a
exceção:

```php
public function testRollbackUndoesStockDecrementWhenOrderInsertFails(): void
{
    $kit = DatabaseTestKit::sqlite($this->dbFile);
    $kit->queryExecutor->execute(/* seed: produto 1 com stock=10 */);

    $useCase = new CreateOrderUseCase($kit->transactionManager);

    try {
        // Passe uma quantidade que tenha sucesso em decrementStock mas dispare uma violação
        // de restrição única em orders.save (ex.: chave de idempotência duplicada).
        $useCase->execute(productId: 1, qty: 3, idempotencyKey: $existingKey);
        self::fail('Expected order creation to fail.');
    } catch (DatabaseConstraintException) {
        // esperado
    }

    // O decrementStock do passo 1 precisa ter sido revertido.
    $row = $kit->queryExecutor->fetchOne('SELECT stock FROM products WHERE id = ?', [1]);
    self::assertSame(10, $row['stock']);
}
```

Este teste falha imediatamente se o caso de uso chamar `$this->products->decrementStock()` em vez
de construir um repositório a partir de `$tx` — a redução de estoque escapa do rollback e a
asserção a detecta.

### Rollback em nível HTTP

A mesma propriedade no nível de integração:

```php
public function testTransactionRollsBackOnDomainException(): void
{
    // popular dois produtos
    // ...

    // Pedido que falhará no segundo produto (estoque insuficiente)
    $response = $this->request('POST', '/orders', [
        'items' => [
            ['product_id' => $p1Id, 'qty' => 3],   // teria sucesso
            ['product_id' => $p2Id, 'qty' => 99],  // falhará
        ],
    ]);

    self::assertSame(409, $response->getStatusCode());

    // Estoque do produto 1 deve estar inalterado — transação foi revertida
    $products = $this->json($this->request('GET', '/products'))['items'];
    self::assertSame($originalStock1, $products[0]['stock']);
}
```

---

## Direção futura

O padrão atual requer instanciar classes concretas de repositório dentro do
callback, o que significa que o caso de uso conhece a implementação do repositório
(`SqliteProductRepository`) em vez de sua interface. Esta é uma limitação conhecida.

Uma abstração `RepositoryFactory` — uma interface aceita por casos de uso que pode produzir
um repositório para um dado executor — restauraria a dependência apenas de interfaces.
Isso está sendo considerado para uma futura versão do NENE2.
