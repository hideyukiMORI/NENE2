# Como adicionar um handler de exceção de domínio

Quando um handler de rota lança uma exceção de domínio (ex.: `OrderNotFoundException`, `InsufficientStockException`),
o `ErrorHandlerMiddleware` do NENE2 delega para o primeiro `DomainExceptionHandlerInterface` registrado
que declare que consegue lidar com aquele tipo de exceção. Isso mantém os handlers de rota livres de
blocos try/catch e concentra a serialização de erros em um único lugar.

## 1. Definir a exceção de domínio

```php
// src/Order/OrderNotFoundException.php
final class OrderNotFoundException extends \RuntimeException
{
    public function __construct(int $id)
    {
        parent::__construct("Order #{$id} not found.");
    }
}
```

## 2. Implementar DomainExceptionHandlerInterface

```php
// src/Order/OrderNotFoundExceptionHandler.php
use Nene2\Error\DomainExceptionHandlerInterface;
use Nene2\Error\ProblemDetailsResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final readonly class OrderNotFoundExceptionHandler implements DomainExceptionHandlerInterface
{
    public function __construct(
        private ProblemDetailsResponseFactory $probs,
    ) {}

    public function supports(Throwable $exception): bool
    {
        return $exception instanceof OrderNotFoundException;
    }

    public function handle(Throwable $exception, ServerRequestInterface $request): ResponseInterface
    {
        return $this->probs->create(
            request: $request,        // ← obrigatório: usado para preencher 'instance' na resposta
            type: 'not-found',        // ← apenas o slug — a factory adiciona o URL base automaticamente
            title: 'Not Found',
            status: 404,
            detail: $exception->getMessage(),
        );
    }
}
```

### Erros comuns

**`$request` ausente** — `ProblemDetailsResponseFactory::create()` exige a requisição PSR-7
como primeiro argumento. Omiti-la causa um `ArgumentCountError` em runtime.

**URL completo em `type`** — `type` recebe um slug (ex.: `'not-found'`), não o URI completo.
A factory adiciona `https://nene2.dev/problems/` (ou o URL base configurado) automaticamente.
Passar o URL completo produz um caminho duplicado como
`https://nene2.dev/problems/https://nene2.dev/problems/not-found`.

**Assinatura correta:**
```php
$this->probs->create(
    request: $request,   // ServerRequestInterface
    type: 'not-found',   // slug
    title: 'Not Found',  // título legível por humanos
    status: 404,         // código de status HTTP
    detail: '...',       // string de detalhe opcional
);
```

## 3. Registrar o handler em RuntimeApplicationFactory

```php
$application = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    domainExceptionHandlers: [
        new OrderNotFoundExceptionHandler($probs),
        new InsufficientStockExceptionHandler($probs),
        // handlers são testados em ordem — o primeiro que corresponder vence
    ],
))->create();
```

## 4. Lançar a exceção no handler da rota

```php
$router->get('/orders/{id}', static function (ServerRequestInterface $request) use ($orders): ResponseInterface {
    /** @var array<string, string> $params */
    $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id     = (int) ($params['id'] ?? 0);

    $order = $orders->findById($id) ?? throw new OrderNotFoundException($id);

    return $json->create(['id' => $order->id]);
});
```

O `ErrorHandlerMiddleware` captura a exceção, percorre a lista `$domainExceptionHandlers`, chama
`supports()` em cada um, e delega para o primeiro que corresponder. Se nenhum handler corresponder, a exceção
é tratada como um erro inesperado do servidor (500).

## Formato da resposta

Uma resposta 404 produzida pelo exemplo acima:

```json
{
    "type": "https://nene2.dev/problems/not-found",
    "title": "Not Found",
    "status": 404,
    "detail": "Order #42 not found.",
    "instance": "/orders/42"
}
```

`instance` é preenchido automaticamente a partir de `$request->getUri()->getPath()`.

## Solução de problemas: recebendo 500 em vez do código de erro esperado

Se uma exceção de domínio produz um **500 Internal Server Error** em vez da resposta
4xx esperada, a causa mais comum é um handler ausente ou mal registrado:

1. **Handler não adicionado a `domainExceptionHandlers`** — verifique novamente se a classe do handler
   está incluída no array passado para `RuntimeApplicationFactory`.
2. **Incompatibilidade no método `supports()`** — certifique-se de que `supports()` verifica exatamente a classe
   de exceção que é realmente lançada. Se a exceção lançada for uma subclasse e `supports()`
   usar `instanceof ExactClass`, exceções de classes filhas ainda corresponderão. Mas se a
   hierarquia de classes estiver invertida (o handler verifica um pai, a exceção é um ramo diferente),
   nenhum handler vai corresponder.
3. **Handler registrado mas na ordem errada** — os handlers são testados em ordem. Se um handler
   catch-all aparecer primeiro e seu `supports()` for muito abrangente, ele pode capturar exceções
   que um handler posterior deveria tratar.

Diagnóstico rápido: adicione temporariamente `error_log(get_class($exception))` antes da
verificação `supports()` para imprimir o nome real da classe de exceção.
