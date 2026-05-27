# Comment ajouter un gestionnaire d'exception de domaine

Lorsqu'un gestionnaire de route lève une exception de domaine (par exemple `OrderNotFoundException`, `InsufficientStockException`),
le `ErrorHandlerMiddleware` de NENE2 délègue au premier `DomainExceptionHandlerInterface` enregistré
qui déclare pouvoir gérer ce type d'exception. Cela libère les gestionnaires de routes des blocs
try/catch et centralise la sérialisation des erreurs en un seul endroit.

## 1. Définir l'exception de domaine

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

## 2. Implémenter DomainExceptionHandlerInterface

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
            request: $request,        // ← requis : utilisé pour remplir 'instance' dans la réponse
            type: 'not-found',        // ← slug uniquement — la factory ajoute l'URL de base en préfixe
            title: 'Not Found',
            status: 404,
            detail: $exception->getMessage(),
        );
    }
}
```

### Erreurs courantes

**`$request` manquant** — `ProblemDetailsResponseFactory::create()` requiert la requête PSR-7
comme premier argument. Son omission cause une `ArgumentCountError` au runtime.

**URL complète dans `type`** — `type` prend un slug (par ex. `'not-found'`), pas l'URI complète.
La factory ajoute automatiquement `https://nene2.dev/problems/` (ou l'URL de base configurée) en préfixe.
Passer l'URL complète produit un chemin doublé comme
`https://nene2.dev/problems/https://nene2.dev/problems/not-found`.

**Signature correcte :**
```php
$this->probs->create(
    request: $request,   // ServerRequestInterface
    type: 'not-found',   // slug
    title: 'Not Found',  // titre lisible par un humain
    status: 404,         // code de statut HTTP
    detail: '...',       // chaîne de détail optionnelle
);
```

## 3. Enregistrer le gestionnaire dans RuntimeApplicationFactory

```php
$application = (new RuntimeApplicationFactory(
    $psr17,
    $psr17,
    domainExceptionHandlers: [
        new OrderNotFoundExceptionHandler($probs),
        new InsufficientStockExceptionHandler($probs),
        // les gestionnaires sont essayés dans l'ordre — le premier qui correspond gagne
    ],
))->create();
```

## 4. Lever l'exception depuis le gestionnaire de route

```php
$router->get('/orders/{id}', static function (ServerRequestInterface $request) use ($orders): ResponseInterface {
    /** @var array<string, string> $params */
    $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
    $id     = (int) ($params['id'] ?? 0);

    $order = $orders->findById($id) ?? throw new OrderNotFoundException($id);

    return $json->create(['id' => $order->id]);
});
```

`ErrorHandlerMiddleware` attrape l'exception, parcourt la liste `$domainExceptionHandlers`, appelle
`supports()` sur chacun, et délègue au premier qui correspond. Si aucun gestionnaire ne correspond, l'exception
est traitée comme une erreur serveur inattendue (500).

## Forme de la réponse

Une réponse 404 produite par l'exemple ci-dessus :

```json
{
    "type": "https://nene2.dev/problems/not-found",
    "title": "Not Found",
    "status": 404,
    "detail": "Order #42 not found.",
    "instance": "/orders/42"
}
```

`instance` est rempli automatiquement depuis `$request->getUri()->getPath()`.

## Dépannage : obtenir 500 au lieu du code d'erreur attendu

Si une exception de domaine produit une **erreur interne du serveur 500** au lieu de la réponse
4xx attendue, la cause la plus courante est un gestionnaire manquant ou mal enregistré :

1. **Gestionnaire non ajouté à `domainExceptionHandlers`** — vérifiez que la classe du gestionnaire
   est bien incluse dans le tableau passé à `RuntimeApplicationFactory`.
2. **Méthode `supports()` incorrecte** — assurez-vous que `supports()` vérifie la classe d'exception exacte
   qui est réellement levée. Si l'exception levée est une sous-classe et que `supports()` utilise
   `instanceof ExactClass`, les exceptions de classe enfant correspondront quand même. Mais si la hiérarchie
   de classes est inversée (le gestionnaire vérifie un parent, l'exception est une branche différente),
   aucun gestionnaire ne correspondra.
3. **Gestionnaire enregistré mais dans le mauvais ordre** — les gestionnaires sont essayés dans l'ordre. Si un gestionnaire
   fourre-tout apparaît en premier et que son `supports()` est trop large, il peut absorber des exceptions
   qu'un gestionnaire ultérieur devrait traiter.

Pour un diagnostic rapide : ajoutez temporairement `error_log(get_class($exception))` avant la
vérification `supports()` pour afficher le nom réel de la classe d'exception.
