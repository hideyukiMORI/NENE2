# Usar Transações de Banco de Dados

Este guia explica como realizar operações atômicas com múltiplos passos usando
`DatabaseTransactionManagerInterface` no NENE2.

**Pré-requisito**: Você tem um repositório apoiado por `DatabaseQueryExecutorInterface`.
Caso contrário, comece com [Adicionar um endpoint com banco de dados](./add-database-endpoint.md).

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

Use um **arquivo temporário** em vez disso:

```php
protected function setUp(): void
{
    $this->dbFile = sys_get_temp_dir() . '/test-' . bin2hex(random_bytes(8)) . '.sqlite';
    $pdo = new \PDO('sqlite:' . $this->dbFile, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    unset($pdo); // fechar a conexão de inicialização antes da fábrica abrir a sua própria

    $dbConfig = new DatabaseConfig(
        url:         null,
        environment: 'test',
        adapter:     'sqlite',
        host:        '',      // não usado para SQLite
        port:        1,       // não usado para SQLite
        name:        $this->dbFile,
        user:        '',      // não usado para SQLite
        password:    '',      // não usado para SQLite
        charset:     '',      // não usado para SQLite
    );

    $factory   = new PdoConnectionFactory($dbConfig);
    $executor  = new PdoDatabaseQueryExecutor($factory);
    $txManager = new PdoDatabaseTransactionManager($factory);
    // ... conectar repositórios e casos de uso
}

protected function tearDown(): void
{
    if (is_file($this->dbFile)) {
        unlink($this->dbFile);
    }
}
```

Cada teste recebe um arquivo fresco, tanto `PdoDatabaseQueryExecutor` quanto
`PdoDatabaseTransactionManager` conectam ao mesmo arquivo, e `tearDown` o deleta.

> **Nota sobre campos `DatabaseConfig` para SQLite**: Para SQLite, apenas `adapter` e `name`
> são obrigatórios. Passe strings vazias para `host`, `user`, `password` e `charset` — eles
> não são validados quando `adapter` é `'sqlite'`.

> **Nota**: `PdoDatabaseQueryExecutor` não aceita um `PDO` bruto como argumento do construtor
> — ele requer um `DatabaseConnectionFactoryInterface`. Use `PdoConnectionFactory`
> (mostrado acima) para conectar uma configuração `PDO` bruta ao executor.

---

## Verificar comportamento de rollback

Teste que uma falha no meio da transação desfaz todas as alterações anteriores:

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
