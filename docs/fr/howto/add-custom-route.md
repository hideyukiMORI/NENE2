# Ajouter une route personnalisée

Ce guide montre comment ajouter des routes GET et POST avec des paramètres de chemin à une application NENE2.

**Prérequis** : Vous avez une application NENE2 fonctionnelle. Sinon, commencez par le [Tutoriel](../tutorial/first-api.md).

---

## Ajouter une route GET simple

Les routes sont enregistrées via `routeRegistrars` — un tableau de fonctions qui reçoivent chacune le routeur et y enregistrent des routes.

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

Dans Express ce serait `app.get('/items', (req, res) => res.json(...))`. Le modèle est identique — route, handler, réponse.

---

## Ajouter un paramètre de chemin

Utilisez la syntaxe `{name}` dans le chemin de route. Dans le handler, lisez tous les paramètres de chemin depuis l'attribut de requête `Router::PARAMETERS_ATTRIBUTE` — ils sont stockés dans un tableau nommé, pas en tant qu'attributs individuels.

```php
use Nene2\Routing\Router;

$router->get('/items/{id}', static function (ServerRequestInterface $req) use ($json) {
    // Les paramètres de chemin sont dans un seul attribut tableau — pas des attributs individuels.
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id     = (int) ($params['id'] ?? 0);

    return $json->create(['id' => $id]);
});
```

> **Erreur fréquente** : `$req->getAttribute('id')` retourne toujours `null`.
> Utilisez toujours `$req->getAttribute(Router::PARAMETERS_ATTRIBUTE, [])['id']` à la place.

Dans Express c'est `req.params.id`. Dans FastAPI c'est un argument de fonction typé. Dans NENE2 c'est une lecture explicite de tableau — plus verbeux mais impossible de confondre avec les paramètres de query string.

### Paramètres multiples

```php
$router->get('/users/{userId}/posts/{postId}', static function (ServerRequestInterface $req) use ($json) {
    $params = $req->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $userId = (int) ($params['userId'] ?? 0);
    $postId = (int) ($params['postId'] ?? 0);

    return $json->create(['userId' => $userId, 'postId' => $postId]);
});
```

---

## Ajouter un paramètre de query string

Les paramètres de query string sont lus depuis le tableau de query parsé, pas depuis le pattern de route.

```php
$router->get('/items', static function (ServerRequestInterface $req) use ($json) {
    $query  = $req->getQueryParams();          // ['limit' => '20', 'offset' => '0']
    $limit  = (int) ($query['limit']  ?? 20);
    $offset = (int) ($query['offset'] ?? 0);

    return $json->create(['limit' => $limit, 'offset' => $offset]);
});
```

C'est équivalent à `req.query.limit` dans Express ou `request.query_params['limit']` dans FastAPI.

---

## Ajouter une route POST

```php
$router->post('/items', static function (ServerRequestInterface $req) use ($json, $psr17) {
    $body  = json_decode((string) $req->getBody(), true) ?? [];
    $name  = (string) ($body['name'] ?? '');

    if ($name === '') {
        // Retourner 422 Validation Failed — voir docs/development/endpoint-scaffold.md
        // pour le pattern de validation complet avec ValidationException.
        return $json->create(['error' => 'name is required'], 422);
    }

    // Dans un vrai endpoint, vous sauvegarderiez en base de données ici.
    return $json->create(['name' => $name], 201);
});
```

> Pour les endpoints de production, utilisez `ValidationException` et le pattern de couche domaine
> plutôt que la validation inline. Voir [Ajouter un endpoint avec base de données](./add-database-endpoint.md).

---

## Plusieurs routes dans un seul registrar

Vous pouvez enregistrer autant de routes que vous voulez dans une seule fonction registrar :

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

Ou répartissez sur plusieurs fonctions registrar pour plus de clarté quand la liste de routes devient longue.

---

## Méthodes HTTP disponibles

| Méthode | Méthode Router | Usage typique |
|---|---|---|
| GET | `$router->get()` | Lire une ressource |
| POST | `$router->post()` | Créer une ressource |
| PUT | `$router->put()` | Remplacer une ressource (mise à jour complète) |
| DELETE | `$router->delete()` | Supprimer une ressource |

---

## Étape suivante

Si votre route doit lire depuis ou écrire dans une base de données, voir
[Ajouter un endpoint avec base de données](./add-database-endpoint.md).
