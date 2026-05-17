# Ajouter une deuxième entité de domaine

Ce guide explique comment ajouter une deuxième entité de domaine en suivant le même modèle que les exemples intégrés `Note` et `Tag`.

**Prérequis** : Avoir complété [Ajouter un endpoint avec BDD](./add-database-endpoint.md).

---

## Vue d'ensemble

Chaque entité suit la même structure :

```
src/Example/YourEntity/
  YourEntity.php
  YourEntityRepositoryInterface.php
  PdoYourEntityRepository.php
  GetYourEntityByIdHandler.php
  YourEntityRouteRegistrar.php   ← enregistre les routes via __invoke(Router)
  YourEntityServiceProvider.php  ← câblage DI + registrar de routes
```

Seul `RuntimeServiceProvider` reçoit le nouveau registrar et le gestionnaire d'exception — **`RuntimeApplicationFactory` ne change pas**.

---

## Étapes clés

### 1 — RouteRegistrar

```php
final readonly class ProductRouteRegistrar
{
    public function __construct(
        private GetProductByIdHandler $getHandler,
        private ListProductsHandler $listHandler,
    ) {}

    public function __invoke(Router $router): void
    {
        $get  = $this->getHandler;
        $list = $this->listHandler;
        $router->get('/examples/products', static fn ($r) => $list->handle($r));
        $router->get('/examples/products/{id}', static fn ($r) => $get->handle($r));
    }
}
```

### 2 — ServiceProvider

Enregistrez le registrar sous la clé `nene2.route_registrar.product` dans `register()`.

### 3 — Câblage dans RuntimeServiceProvider

```php
$builder->addProvider(new ProductServiceProvider());

return new RuntimeApplicationFactory(
    /* ... */,
    [$noteNotFoundHandler, $tagNotFoundHandler, $productNotFoundHandler],
    $requestIdHolder,
    [$noteRegistrar, $tagRegistrar, $productRegistrar],
    $bearerMiddleware,
);
```

Pour plus de détails, voir la version anglaise ou les exemples `Note` / `Tag` dans `src/Example/`.
