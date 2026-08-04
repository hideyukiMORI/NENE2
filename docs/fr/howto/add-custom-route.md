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

> **Ordre d'enregistrement des routes** : le routeur effectue la correspondance dans l'**ordre
> d'enregistrement**. Enregistrez **toujours les routes statiques avant les routes paramétrées**
> lorsqu'elles partagent le même préfixe. Si vous enregistrez `GET /items/{id}` puis
> `GET /items/summary`, une requête vers `/items/summary` correspondra à la route `{id}` avec
> `id = "summary"` — produisant un 404 spécifique au domaine déroutant plutôt qu'une erreur de routage.
>
> ```php
> // INCORRECT — /items/summary correspond à {id} avec id="summary"
> $router->get('/items/{id}',    $this->show(...));
> $router->get('/items/summary', $this->summary(...)); // jamais atteint
>
> // CORRECT — le segment statique est mis en correspondance en premier
> $router->get('/items/summary', $this->summary(...));
> $router->get('/items/{id}',    $this->show(...));
> ```

---

## Endpoints d'action (opérations non-CRUD)

Certaines opérations n'entrent pas dans la forme CRUD standard — archiver, publier, approuver,
restaurer. Utilisez `POST /resource/{id}/action` pour celles-ci. La réponse est `200 OK` avec le
corps de la ressource mise à jour.

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

> **Ordre d'enregistrement** : les routes d'action (`/items/{id}/archive`) partagent le segment
> `{id}` avec les routes show/update — elles fonctionnent correctement car le chemin d'action
> comporte un segment statique supplémentaire après `{id}`, ce qui les rend non ambiguës quel que
> soit l'ordre d'enregistrement.

### Endpoints d'action avec un corps optionnel

Certaines actions acceptent un corps JSON optionnel (par exemple une action `reject` avec un
`reason` optionnel). `JsonRequestBodyParser::parse()` lève une erreur 400 si le corps est vide —
vérifiez donc que le corps n'est pas vide avant d'appeler le parseur :

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

## Méthodes HTTP disponibles

| Méthode | Méthode Router | Usage typique |
|---|---|---|
| GET | `$router->get()` | Lire une ressource |
| POST | `$router->post()` | Créer une ressource ou déclencher une action |
| PUT | `$router->put()` | Remplacer une ressource (mise à jour complète) |
| PATCH | `$router->patch()` | Mise à jour partielle |
| DELETE | `$router->delete()` | Supprimer une ressource |

---

## Chemins réservés par le framework

Les chemins suivants sont enregistrés par `RuntimeApplicationFactory` **avant** l'exécution de vos
registrars de routes. Les routes enregistrées par l'utilisateur pour ces chemins ne correspondront
jamais, car les routes du framework sont vérifiées en premier.

| Chemin | Méthode | Description |
|---|---|---|
| `/` | GET | Endpoint de smoke du framework (nom, description, statut) |
| `/health` | GET | Vérification de santé |
| `/machine/health` | GET | Vérification de santé pour clients machine (clé API requise) |
| `/examples/ping` | GET | Exemple de ping |
| `/examples/protected` | GET | Exemple d'endpoint protégé (quand JWT est configuré) |

Si votre application a besoin d'une page d'accueil, utilisez un chemin différent (par exemple
`/welcome`, `/home`) ou servez le HTML via la réponse de smoke du framework en enregistrant une
vérification de santé.

---

## Renvoyer 204 No Content (endpoints DELETE)

Utilisez `JsonResponseFactory::createEmpty()` pour renvoyer une réponse 204 sans corps :

```php
private function delete(ServerRequestInterface $request): ResponseInterface
{
    $params = (array) $request->getAttribute(Router::PARAMETERS_ATTRIBUTE);
    $id     = (int) ($params['id'] ?? 0);
    $this->repository->delete($id); // lève NotFoundException si introuvable
    return $this->json->createEmpty(204);
}
```

> **Pourquoi pas `create([], 204)` ?** Passer un tableau vide produit un corps JSON `{}`.
> `createEmpty()` renvoie une réponse réellement sans corps, ce qui est correct pour 204 No Content.

---

## Étape suivante

Si votre route doit lire depuis ou écrire dans une base de données, voir
[Ajouter un endpoint avec base de données](./add-database-endpoint.md).
