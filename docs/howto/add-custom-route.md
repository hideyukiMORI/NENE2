# Add a Custom Route

This guide shows how to add GET and POST routes with path parameters to a NENE2 application.

**Prerequisite**: You have a working NENE2 application. If not, start with the [Tutorial](../tutorial/first-api.md).

---

## Add a simple GET route

Routes are registered via `routeRegistrars` — an array of functions that each receive the router and register routes on it.

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

In Express this would be `app.get('/items', (req, res) => res.json(...))`. The pattern is identical — route, handler, response.

---

## Add a path parameter

Use `{name}` syntax in the route path. Inside the handler, read all path parameters from the `Router::PARAMETERS_ATTRIBUTE` request attribute — they are stored as a named array, not as individual attributes.

```php
use Nene2\Routing\Router;

$router->get('/items/{id}', static function (ServerRequestInterface $req) use ($json) {
    // Path parameters are in a single array attribute — not individual attributes.
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id     = (int) ($params['id'] ?? 0);

    return $json->create(['id' => $id]);
});
```

> **Common mistake**: `$req->getAttribute('id')` always returns `null`.
> Always use `$req->getAttribute(Router::PARAMETERS_ATTRIBUTE, [])['id']` instead.

In Express this is `req.params.id`. In FastAPI it is a typed function argument. In NENE2 it is an explicit array read — more verbose but impossible to confuse with query string parameters.

### Multiple parameters

```php
$router->get('/users/{userId}/posts/{postId}', static function (ServerRequestInterface $req) use ($json) {
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $userId = (int) ($params['userId'] ?? 0);
    $postId = (int) ($params['postId'] ?? 0);

    return $json->create(['userId' => $userId, 'postId' => $postId]);
});
```

---

## Add a query string parameter

Query string parameters are read from the parsed query array, not from the route pattern.

```php
$router->get('/items', static function (ServerRequestInterface $req) use ($json) {
    $query  = $req->getQueryParams();          // ['limit' => '20', 'offset' => '0']
    $limit  = (int) ($query['limit']  ?? 20);
    $offset = (int) ($query['offset'] ?? 0);

    return $json->create(['limit' => $limit, 'offset' => $offset]);
});
```

This is equivalent to `req.query.limit` in Express or `request.query_params['limit']` in FastAPI.

---

## Add a POST route

```php
$router->post('/items', static function (ServerRequestInterface $req) use ($json, $psr17) {
    $body  = json_decode((string) $req->getBody(), true) ?? [];
    $name  = (string) ($body['name'] ?? '');

    if ($name === '') {
        // Return 422 Validation Failed — see docs/development/endpoint-scaffold.md
        // for the full validation pattern with ValidationException.
        return $json->create(['error' => 'name is required'], 422);
    }

    // In a real endpoint you would save to a database here.
    return $json->create(['name' => $name], 201);
});
```

> For production endpoints, use `ValidationException` and the domain layer pattern
> instead of inline validation. See [Add a database-backed endpoint](./add-database-endpoint.md).

---

## Multiple routes in one registrar

You can register as many routes as you want inside a single registrar function:

```php
routeRegistrars: [
    static function (Router $router) use ($json): void {
        $router->get('/items',      /* handler */);
        $router->get('/items/{id}', /* handler */);
        $router->post('/items',     /* handler */);
        $router->put('/items/{id}', /* handler */);
        $router->delete('/items/{id}', /* handler */);
    },
],
```

Or split across multiple registrar functions for clarity when the route list grows long.

---

## Available HTTP methods

| Method | Router method | Typical use |
|---|---|---|
| GET | `$router->get()` | Read a resource |
| POST | `$router->post()` | Create a resource |
| PUT | `$router->put()` | Replace a resource (full update) |
| DELETE | `$router->delete()` | Remove a resource |

---

## Next step

If your route needs to read from or write to a database, see
[Add a database-backed endpoint](./add-database-endpoint.md).
