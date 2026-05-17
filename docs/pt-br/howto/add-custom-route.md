# Adicionar uma rota personalizada

Este guia mostra como adicionar rotas GET e POST com parâmetros de rota a uma aplicação NENE2.

**Pré-requisito**: Você tem uma aplicação NENE2 funcionando. Se não, comece com o [Tutorial](../tutorial/first-api.md).

---

## Adicionar uma rota GET simples

As rotas são registradas via `routeRegistrars` — um array de funções que cada uma recebe o roteador e registra rotas nele.

```php
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;

$psr17 = new Psr17Factory();
$json  = new JsonResponseFactory($psr17, $psr17);

$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    routeRegistrars: [
        static function (Router $router) use ($json): void {
            $router->get('/items', static function (ServerRequestInterface $req) use ($json) {
                return $json->create(['items' => [], 'count' => 0]);
            });
        },
    ],
))->create();
```

No Express seria `app.get('/items', (req, res) => res.json(...))`. O padrão é idêntico — rota, handler, resposta.

---

## Adicionar um parâmetro de rota

Use a sintaxe `{name}` no caminho da rota. Dentro do handler, leia todos os parâmetros de rota do atributo de requisição `Router::PARAMETERS_ATTRIBUTE` — eles são armazenados como um array nomeado, não como atributos individuais.

```php
use Nene2\Routing\Router;

$router->get('/items/{id}', static function (ServerRequestInterface $req) use ($json) {
    // Parâmetros de rota ficam em um único atributo array — não atributos individuais.
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id     = (int) ($params['id'] ?? 0);

    return $json->create(['id' => $id]);
});
```

> **Erro comum**: `$req->getAttribute('id')` sempre retorna `null`.
> Sempre use `$req->getAttribute(Router::PARAMETERS_ATTRIBUTE, [])['id']`.

No Express é `req.params.id`. No FastAPI é um argumento de função tipado. No NENE2 é uma leitura explícita de array — mais verboso mas impossível de confundir com parâmetros de query string.

### Múltiplos parâmetros

```php
$router->get('/users/{userId}/posts/{postId}', static function (ServerRequestInterface $req) use ($json) {
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $userId = (int) ($params['userId'] ?? 0);
    $postId = (int) ($params['postId'] ?? 0);

    return $json->create(['userId' => $userId, 'postId' => $postId]);
});
```

---

## Adicionar um parâmetro de query string

Parâmetros de query string são lidos do array de query parseado, não do padrão da rota.

```php
$router->get('/items', static function (ServerRequestInterface $req) use ($json) {
    $query  = $req->getQueryParams();          // ['limit' => '20', 'offset' => '0']
    $limit  = (int) ($query['limit']  ?? 20);
    $offset = (int) ($query['offset'] ?? 0);

    return $json->create(['limit' => $limit, 'offset' => $offset]);
});
```

Isso é equivalente a `req.query.limit` no Express ou `request.query_params['limit']` no FastAPI.

---

## Adicionar uma rota POST

```php
$router->post('/items', static function (ServerRequestInterface $req) use ($json, $psr17) {
    $body  = json_decode((string) $req->getBody(), true) ?? [];
    $name  = (string) ($body['name'] ?? '');

    if ($name === '') {
        // Retornar 422 Validation Failed — veja docs/development/endpoint-scaffold.md
        // para o padrão de validação completo com ValidationException.
        return $json->create(['error' => 'name is required'], 422);
    }

    // Em um endpoint real, você salvaria no banco de dados aqui.
    return $json->create(['name' => $name], 201);
});
```

> Para endpoints de produção, use `ValidationException` e o padrão de camada de domínio
> em vez de validação inline. Veja [Adicionar um endpoint com banco de dados](./add-database-endpoint.md).

---

## Múltiplas rotas em um único registrar

Você pode registrar quantas rotas quiser dentro de uma única função registrar:

```php
routeRegistrars: [
    static function (Router $router) use ($json): void {
        $router->get('/items',         /* handler */);
        $router->get('/items/{id}',    /* handler */);
        $router->post('/items',        /* handler */);
        $router->put('/items/{id}',    /* handler */);
        $router->delete('/items/{id}', /* handler */);
    },
],
```

Ou divida em múltiplas funções registrar para clareza quando a lista de rotas crescer.

---

## Métodos HTTP disponíveis

| Método | Método do Router | Uso típico |
|---|---|---|
| GET | `$router->get()` | Ler um recurso |
| POST | `$router->post()` | Criar um recurso |
| PUT | `$router->put()` | Substituir um recurso (atualização completa) |
| DELETE | `$router->delete()` | Remover um recurso |

---

## Próximo passo

Se sua rota precisar ler ou escrever em um banco de dados, veja
[Adicionar um endpoint com banco de dados](./add-database-endpoint.md).
