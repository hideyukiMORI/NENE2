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

> **Ordem de registro das rotas**: o roteador faz a correspondência na **ordem de registro**.
> Registre **sempre as rotas estáticas antes das parametrizadas** quando ambas compartilham o mesmo
> prefixo. Se você registrar `GET /items/{id}` primeiro e depois `GET /items/summary`, uma requisição
> para `/items/summary` corresponderá à rota `{id}` com `id = "summary"` — produzindo um 404
> específico do domínio confuso, em vez de um erro de roteamento.
>
> ```php
> // ERRADO — /items/summary corresponde a {id} com id="summary"
> $router->get('/items/{id}',    $this->show(...));
> $router->get('/items/summary', $this->summary(...)); // nunca alcançado
>
> // CORRETO — o segmento estático é correspondido primeiro
> $router->get('/items/summary', $this->summary(...));
> $router->get('/items/{id}',    $this->show(...));
> ```

---

## Endpoints de ação (operações não-CRUD)

Algumas operações não se encaixam no formato CRUD padrão — arquivar, publicar, aprovar, restaurar.
Use `POST /resource/{id}/action` para elas. A resposta é `200 OK` com o corpo do recurso atualizado.

```php
$router->post('/items/{id}/archive', static function (ServerRequestInterface $req) use ($repo, $json): ResponseInterface {
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id     = (int) ($params['id'] ?? 0);
    $item   = $repo->archive($id, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));

    return $json->create(self::serialize($item)); // 200 OK
});

$router->post('/items/{id}/restore', static function (ServerRequestInterface $req) use ($repo, $json): ResponseInterface {
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id     = (int) ($params['id'] ?? 0);
    $item   = $repo->restore($id, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));

    return $json->create(self::serialize($item)); // 200 OK
});
```

> **Ordem de registro**: rotas de ação (`/items/{id}/archive`) compartilham o segmento `{id}` com as
> rotas de show/update — elas funcionam corretamente porque o caminho da ação tem um segmento
> estático adicional após `{id}`, tornando-as inequívocas independentemente da ordem de registro.

### Endpoints de ação com corpo opcional

Algumas ações aceitam um corpo JSON opcional (por exemplo, uma ação `reject` com um `reason`
opcional). `JsonRequestBodyParser::parse()` lança um 400 se o corpo estiver vazio — verifique se o
corpo está vazio antes de chamar o parser:

```php
$router->post('/items/{id}/reject', static function (ServerRequestInterface $req) use ($repo, $json): ResponseInterface {
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id     = (int) ($params['id'] ?? 0);
    $raw    = (string) $req->getBody();
    $reason = null;

    if ($raw !== '') {
        $body   = JsonRequestBodyParser::parse($req);
        $reason = isset($body['reason']) && is_string($body['reason']) ? trim($body['reason']) : null;
    }

    $item = $repo->reject($id, $reason, (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'));

    return $json->create(self::serialize($item));
});
```

---

## Métodos HTTP disponíveis

| Método | Método do Router | Uso típico |
|---|---|---|
| GET | `$router->get()` | Ler um recurso |
| POST | `$router->post()` | Criar um recurso ou disparar uma ação |
| PUT | `$router->put()` | Substituir um recurso (atualização completa) |
| PATCH | `$router->patch()` | Atualização parcial |
| DELETE | `$router->delete()` | Remover um recurso |

---

## Caminhos reservados pelo framework

Os caminhos a seguir são registrados por `RuntimeApplicationFactory` **antes** que seus registrars de
rota sejam executados. Rotas registradas pelo usuário para esses caminhos nunca corresponderão,
porque as rotas do framework são verificadas primeiro.

| Caminho | Método | Descrição |
|---|---|---|
| `/` | GET | Endpoint de smoke do framework (nome, descrição, status) |
| `/health` | GET | Verificação de saúde |
| `/machine/health` | GET | Verificação de saúde para clientes de máquina (requer chave de API) |
| `/examples/ping` | GET | Exemplo de ping |
| `/examples/protected` | GET | Exemplo de endpoint protegido (quando JWT está configurado) |

Se sua aplicação precisar de uma página inicial, use um caminho diferente (por exemplo, `/welcome`,
`/home`) ou sirva o HTML pela resposta de smoke do framework registrando uma verificação de saúde.

---

## Retornando 204 No Content (endpoints DELETE)

Use `JsonResponseFactory::createEmpty()` para retornar uma resposta 204 sem corpo:

```php
private function delete(ServerRequestInterface $request): ResponseInterface
{
    $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $id     = (int) ($params['id'] ?? 0);
    $this->repository->delete($id); // lança NotFoundException se não encontrado
    return $this->json->createEmpty(204);
}
```

> **Por que não `create([], 204)`?** Passar um array vazio produz um corpo JSON `{}`.
> `createEmpty()` retorna uma resposta realmente sem corpo, o que é correto para 204 No Content.

---

## Próximo passo

Se sua rota precisar ler ou escrever em um banco de dados, veja
[Adicionar um endpoint com banco de dados](./add-database-endpoint.md).
