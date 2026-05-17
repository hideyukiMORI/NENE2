# Adicionar uma segunda entidade de domínio

Este guia mostra como adicionar uma segunda entidade de domínio seguindo o mesmo padrão dos exemplos integrados `Note` e `Tag`.

**Pré-requisito**: Ter concluído [Adicionar endpoint com banco de dados](./add-database-endpoint.md).

---

## Visão geral

Cada entidade segue a mesma estrutura:

```
src/Example/YourEntity/
  YourEntity.php
  YourEntityRepositoryInterface.php
  PdoYourEntityRepository.php
  GetYourEntityByIdHandler.php
  YourEntityRouteRegistrar.php   ← registra rotas via __invoke(Router)
  YourEntityServiceProvider.php  ← configuração DI + registrar de rotas
```

Apenas `RuntimeServiceProvider` recebe o novo registrar e o handler de exceção — **`RuntimeApplicationFactory` não precisa ser alterado**.

---

## Etapas principais

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

Registre o registrar com a chave `nene2.route_registrar.product` em `register()`.

### 3 — Configurar no RuntimeServiceProvider

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

Para mais detalhes, consulte a versão em inglês ou os exemplos `Note`/`Tag` em `src/Example/`.
