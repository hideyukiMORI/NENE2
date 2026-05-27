# Como Passar Estado com Escopo de Requisição entre Middleware e Handlers

Alguns middlewares extraem um valor da requisição de entrada — um ID de tenant, um claim JWT decodificado, um contexto de trace — e os handlers de rota precisam desse valor mais adiante. Este guia mostra o padrão recomendado usando `RequestScopedHolder`.

## O padrão holder

`RequestScopedHolder<T>` é um pequeno container mutável. Injete **uma instância compartilhada** tanto no middleware que o escreve quanto no handler (ou repositório) que o lê:

```php
use Nene2\Http\RequestScopedHolder;

// Instância compartilhada — conectada uma vez na raiz de composição.
/** @var RequestScopedHolder<int> $teamId */
$teamId = new RequestScopedHolder();

// Middleware escreve nele.
$tenantMiddleware = new TenantMiddleware($teamId, $problemDetails);

// Handler de rota lê dele.
$routeRegistrar = new TaskRouteRegistrar($repository, $teamId, $json);
```

Dentro do middleware:

```php
public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
{
    $raw = $request->getHeaderLine('X-Team-Id');
    // ... validar ...
    $this->teamId->set((int) $raw);   // escrever
    return $handler->handle($request);
}
```

Dentro do handler de rota:

```php
$id = $this->teamId->get();  // ler — lança LogicException se o middleware não executou
```

## Por que não usar atributos de requisição PSR-7?

Os atributos de requisição PSR-7 são imutáveis — cada chamada `withAttribute()` retorna uma nova instância. Para passar um atributo de um middleware para um handler você deve encaminhar o novo objeto de requisição por toda a cadeia de chamadas, o que o dispatcher do NENE2 já faz. Usar `withAttribute()` é adequado quando o código downstream recebe o `$request` diretamente.

`RequestScopedHolder` é a ferramenta certa quando o consumidor downstream **não** recebe o
`$request` — por exemplo, um repositório que só conhece seus tipos de domínio e não pode aceitar uma requisição PSR-7 como dependência.

## Empilhando múltiplos middlewares

Passe uma lista para `RuntimeApplicationFactory::$authMiddleware` para executar vários middlewares em sequência:

```php
$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    authMiddleware: [
        new TenantMiddleware($teamId, $probs),        // executa primeiro
        new BearerTokenMiddleware($probs, $verifier), // executa segundo
    ],
))->create();
```

Ambos os middlewares compartilham a mesma posição no pipeline (após o limite de tamanho de requisição, antes da limitação de taxa). O primeiro item da lista processa a requisição antes do segundo.

## Segurança: modelo shared-nothing do PHP

No PHP-FPM e CLI, cada requisição executa em um processo novo. O valor `RequestScopedHolder` definido durante a requisição A nunca é visível para a requisição B porque cada processo termina após tratar uma requisição. O holder é seguro para usar como está sob este modelo.

### Runtimes assíncronos (Swoole, ReactPHP, modo worker do FrankenPHP)

Quando múltiplas requisições compartilham o mesmo processo PHP, um holder escrito durante a requisição A reterá seu valor na requisição B a menos que seja explicitamente limpo. Chame `reset()` no início (ou fim) de cada ciclo de requisição:

```php
// Exemplo de handler de requisição Swoole
$server->on('request', function ($request, $response) use ($app, $teamId) {
    $teamId->reset();            // limpar valor da requisição anterior
    $psrRequest = /* converter */;
    $psrResponse = $app->handle($psrRequest);
    // emitir $psrResponse ...
});
```

O NENE2 atualmente tem como alvo PHP-FPM / CLI e não inclui suporte assíncrono integrado. Se você executar um runtime assíncrono, você é responsável por resetar holders compartilhados entre requisições.
