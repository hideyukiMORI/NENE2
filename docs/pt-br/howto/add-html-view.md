# Adicionar vistas HTML

Este guia explica como adicionar respostas HTML renderizadas no servidor a uma aplicação NENE2
usando `NativePhpViewRenderer` e `HtmlResponseFactory`.

**Pré-requisito**: Você tem uma aplicação NENE2 funcionando com pelo menos uma rota. Caso contrário, comece por [Adicionar uma rota personalizada](./add-custom-route.md).

---

## Visão geral

NENE2 fornece uma camada de renderização HTML mínima sem dependências externas:

| Classe | Função |
|---|---|
| `NativePhpViewRenderer` | Renderiza templates `.php` em escopo isolado |
| `HtmlEscaper` | Escapa valores para saída HTML segura (`htmlspecialchars` / UTF-8 / aspas completas) |
| `HtmlResponseFactory` | Encapsula o HTML renderizado em uma resposta PSR-7 `text/html; charset=utf-8` |
| `TemplateNotFoundException` | Lançada quando o arquivo de template não existe ou o caminho é inválido |

As respostas HTML coexistem com endpoints JSON. Você pode adicioná-las à mesma aplicação sem remover rotas existentes.

---

## 1. Criar os templates

NENE2 espera que os arquivos de template PHP nativo fiquem dentro de um único diretório raiz. O local padrão é `templates/` na raiz do projeto.

```
my-app/
├── templates/
│   ├── layout.php       (layout compartilhado opcional)
│   ├── home.php
│   └── notes/
│       ├── index.php
│       └── show.php
```

O que cada template recebe:

- `$e` — helper de escape (`HtmlEscaper::escape()`). Use sempre para valores do usuário.
- Cada chave do array `$data` passado para `render()` é injetada como variável individual.

**`templates/home.php`**

```php
<!doctype html>
<html lang="pt-BR">
<head><meta charset="utf-8"><title><?= $e($title) ?></title></head>
<body>
  <h1><?= $e($title) ?></h1>
  <p><?= $e($description) ?></p>
</body>
</html>
```

**`templates/notes/index.php`**

```php
<!doctype html>
<html lang="pt-BR">
<head><meta charset="utf-8"><title>Lista de notas</title></head>
<body>
  <h1>Notas</h1>
  <ul>
    <?php foreach ($notes as $note): ?>
      <li><a href="/notes/<?= $e($note['id']) ?>"><?= $e($note['title']) ?></a></li>
    <?php endforeach; ?>
  </ul>
</body>
</html>
```

> **Segurança**: Use sempre `$e(...)` para valores provenientes do usuário, banco de dados ou sistemas externos. Omitir isso cria vulnerabilidades XSS.

---

## 2. Registrar o renderer no ServiceProvider

Registre `NativePhpViewRenderer` e `HtmlResponseFactory` no `ServiceProviderInterface` da sua aplicação:

```php
use Nene2\DependencyInjection\ContainerBuilder;
use Nene2\DependencyInjection\ServiceProviderInterface;
use Nene2\View\HtmlResponseFactory;
use Nene2\View\NativePhpViewRenderer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final readonly class AppServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $builder): void
    {
        $builder
            ->set(
                NativePhpViewRenderer::class,
                static function (ContainerInterface $container): NativePhpViewRenderer {
                    return new NativePhpViewRenderer(dirname(__DIR__) . '/templates');
                },
            )
            ->set(
                HtmlResponseFactory::class,
                static function (ContainerInterface $container): HtmlResponseFactory {
                    $responseFactory = $container->get(ResponseFactoryInterface::class);
                    $streamFactory   = $container->get(StreamFactoryInterface::class);
                    $renderer        = $container->get(NativePhpViewRenderer::class);

                    return new HtmlResponseFactory($responseFactory, $streamFactory, $renderer);
                },
            );
    }
}
```

---

## 3. Usar HtmlResponseFactory em um handler

Injete `HtmlResponseFactory` no seu handler e chame `create()`:

```php
use Nene2\View\HtmlResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class HomeHandler
{
    public function __construct(private HtmlResponseFactory $html) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->html->create('home.php', [
            'title'       => 'Bem-vindo',
            'description' => 'Aplicação construída com NENE2.',
        ]);
    }
}
```

Assinatura de `create()`:

```php
public function create(
    string $template,           // caminho relativo a partir de templateRoot
    array  $data    = [],       // variáveis passadas ao template
    int    $status  = 200,      // código de status HTTP
    array  $headers = [],       // cabeçalhos de resposta adicionais
): ResponseInterface
```

---

## 4. Registrar as rotas

```php
$router->get('/', new HomeHandler($container->get(HtmlResponseFactory::class)));
$router->get('/notes', new NoteListHandler($container->get(HtmlResponseFactory::class)));
```

---

## 5. Tratar TemplateNotFoundException

`NativePhpViewRenderer::render()` lança `TemplateNotFoundException` nos seguintes casos:

- O arquivo de template não existe
- O caminho do template está vazio
- O caminho do template contém `..` (traversal de diretório bloqueado)

Registre um handler de exceção de domínio para retornar uma resposta HTTP adequada:

```php
use Nene2\Error\DomainExceptionHandlerInterface;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\View\TemplateNotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class TemplateNotFoundExceptionHandler implements DomainExceptionHandlerInterface
{
    public function __construct(private ProblemDetailsResponseFactory $problemDetails) {}

    public function handles(\Throwable $exception): bool
    {
        return $exception instanceof TemplateNotFoundException;
    }

    public function handle(\Throwable $exception, ServerRequestInterface $request): ResponseInterface
    {
        return $this->problemDetails->create($request, 'not-found', 'Not Found', 404);
    }
}
```

---

## 6. Misturar endpoints HTML e JSON

```php
$registerRoutes = static function (Router $router) use ($container): void {
    // API JSON
    $router->get('/api/notes',     $container->get(NoteListJsonHandler::class));
    $router->post('/api/notes',    $container->get(NoteCreateHandler::class));

    // Vistas HTML
    $router->get('/',              $container->get(HomeHandler::class));
    $router->get('/notes',         $container->get(NoteListHtmlHandler::class));
    $router->get('/notes/{id}',    $container->get(NoteShowHtmlHandler::class));
};
```

---

## 7. Testar os handlers HTML

```php
use Nene2\View\HtmlResponseFactory;
use Nene2\View\NativePhpViewRenderer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

public function testHomeReturnsHtml(): void
{
    $templateRoot = sys_get_temp_dir() . '/test-templates-' . bin2hex(random_bytes(4));
    mkdir($templateRoot);
    file_put_contents($templateRoot . '/home.php', '<h1><?= $e($title) ?></h1>');

    $psr17   = new Psr17Factory();
    $factory = new HtmlResponseFactory($psr17, $psr17, new NativePhpViewRenderer($templateRoot));
    $handler = new HomeHandler($factory);

    $response = $handler(new ServerRequest('GET', '/'));

    self::assertSame(200, $response->getStatusCode());
    self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
    self::assertStringContainsString('<h1>Bem-vindo</h1>', (string) $response->getBody());

    unlink($templateRoot . '/home.php');
    rmdir($templateRoot);
}
```

---

## Notas de design

- Os templates são executados dentro de uma closure, portanto não têm acesso a `$this` nem aos internos da classe. As variáveis são injetadas via `extract()` + `EXTR_SKIP` (variáveis existentes não são sobrescritas).
- `HtmlEscaper` usa `ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5`. `"` e `'` são ambos escapados, e sequências UTF-8 inválidas são substituídas em vez de descartadas.
- Traversal de diretório (`../`) é bloqueado na etapa de resolução de caminho. Nenhum acesso ao sistema de arquivos ocorre antes dessa verificação.

Para as garantias de API estável de `Nene2\View\*`, consulte [ADR 0009](../adr/0009-v1.0-public-api-scope.md).

---

## Próximos passos

- [Adicionar uma rota personalizada](./add-custom-route.md)
- [Adicionar limitação de taxa](./add-rate-limiting.md)
- [Adicionar autenticação JWT](./add-jwt-authentication.md)
