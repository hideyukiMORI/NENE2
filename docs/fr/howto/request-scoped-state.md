# Comment passer un état scopé par requête entre les middlewares et les handlers

Certains middlewares extraient une valeur de la requête entrante — un ID de tenant, un claim JWT décodé, un contexte de trace — et les handlers de routes ont besoin de cette valeur en aval. Ce guide présente le pattern recommandé en utilisant `RequestScopedHolder`.

## Le pattern holder

`RequestScopedHolder<T>` est un petit conteneur mutable. Injectez **une seule instance partagée** à la fois dans le middleware qui l'écrit et dans le handler (ou repository) qui la lit :

```php
use Nene2\Http\RequestScopedHolder;

// Instance partagée — câblée une seule fois à la racine de composition.
/** @var RequestScopedHolder<int> $teamId */
$teamId = new RequestScopedHolder();

// Le middleware l'écrit.
$tenantMiddleware = new TenantMiddleware($teamId, $problemDetails);

// Le handler de route le lit.
$routeRegistrar = new TaskRouteRegistrar($repository, $teamId, $json);
```

À l'intérieur du middleware :

```php
public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
{
    $raw = $request->getHeaderLine('X-Team-Id');
    // ... valider ...
    $this->teamId->set((int) $raw);   // écrire
    return $handler->handle($request);
}
```

À l'intérieur du handler de route :

```php
$id = $this->teamId->get();  // lire — lève LogicException si le middleware n'a pas tourné
```

## Pourquoi pas les attributs de requête PSR-7 ?

Les attributs de requête PSR-7 sont immuables — chaque appel `withAttribute()` retourne une nouvelle instance. Pour passer un attribut d'un middleware vers un handler, vous devez faire passer le nouvel objet de requête dans toute la chaîne d'appel, ce que le dispatcher NENE2 fait déjà. Utiliser `withAttribute()` est bien quand le code en aval reçoit directement `$request`.

`RequestScopedHolder` est le bon outil quand le consommateur en aval ne reçoit **pas** le `$request` — par exemple, un repository qui ne connaît que vos types de domaine et ne peut pas accepter une requête PSR-7 comme dépendance.

## Empiler plusieurs middlewares

Passer une liste à `RuntimeApplicationFactory::$authMiddleware` pour exécuter plusieurs middlewares en séquence :

```php
$app = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    authMiddleware: [
        new TenantMiddleware($teamId, $probs),        // s'exécute en premier
        new BearerTokenMiddleware($probs, $verifier), // s'exécute en deuxième
    ],
))->create();
```

Les deux middlewares partagent la même position dans le pipeline (après la limite de taille de requête, avant la limitation de débit). Le premier élément de la liste traite la requête avant le second.

## Sécurité : modèle PHP sans partage

Dans PHP-FPM et CLI, chaque requête s'exécute dans un processus frais. La valeur `RequestScopedHolder` définie pendant la requête A n'est jamais visible pour la requête B parce que chaque processus se termine après avoir traité une requête. Le holder est sûr à utiliser tel quel sous ce modèle.

### Runtimes asynchrones (Swoole, ReactPHP, mode worker FrankenPHP)

Quand plusieurs requêtes partagent le même processus PHP, un holder écrit pendant la requête A conservera sa valeur dans la requête B sauf si explicitement effacé. Appelez `reset()` au début (ou à la fin) de chaque cycle de requête :

```php
// Exemple de handler de requête Swoole
$server->on('request', function ($request, $response) use ($app, $teamId) {
    $teamId->reset();            // effacer la valeur de la requête précédente
    $psrRequest = /* convertir */;
    $psrResponse = $app->handle($psrRequest);
    // émettre $psrResponse ...
});
```

NENE2 cible actuellement PHP-FPM / CLI et ne fournit pas de support async intégré. Si vous exécutez un runtime async, vous êtes responsable de la réinitialisation des holders partagés entre les requêtes.
