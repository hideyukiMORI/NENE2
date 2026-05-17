# Eine zweite Domain-Entität hinzufügen

Diese Anleitung zeigt, wie eine zweite Domain-Entität nach demselben Muster wie die eingebauten Beispiele `Note` und `Tag` hinzugefügt wird.

**Voraussetzung**: [Datenbankendpunkt hinzufügen](./add-database-endpoint.md) abgeschlossen haben.

---

## Übersicht

Jede Entität folgt derselben Struktur:

```
src/Example/YourEntity/
  YourEntity.php
  YourEntityRepositoryInterface.php
  PdoYourEntityRepository.php
  GetYourEntityByIdHandler.php
  YourEntityRouteRegistrar.php   ← registriert Routen via __invoke(Router)
  YourEntityServiceProvider.php  ← DI-Konfiguration + Routen-Registrar
```

Nur `RuntimeServiceProvider` erhält den neuen Registrar und Exception-Handler — **`RuntimeApplicationFactory` bleibt unverändert**.

---

## Wichtige Schritte

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

Registrar unter dem Schlüssel `nene2.route_registrar.product` in `register()` eintragen.

### 3 — In RuntimeServiceProvider verdrahten

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

Details siehe englische Version oder die Beispiele `Note`/`Tag` in `src/Example/`.
